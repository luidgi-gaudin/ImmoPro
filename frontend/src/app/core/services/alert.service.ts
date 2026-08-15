import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { ListParams, PaginatedResponse, toHttpParams } from '../list/pagination.model';

export type AlertType = 'loyer_impaye' | 'revision_irl' | 'fin_bail' | 'dpe_expiration';
export type AlertSeverity = 'info' | 'warning' | 'critical';

export interface AppAlert {
  id: number;
  type: AlertType;
  severity: AlertSeverity;
  title: string;
  message: string;
  due_date: string | null;
  meta: Record<string, unknown> | null;
  subject_type: string | null;
  subject_id: number | null;
  is_read: boolean;
  is_resolved: boolean;
  reminded_at: string | null;
  created_at: string | null;
}

/**
 * État centralisé des alertes proactives du bailleur (impayés, révision IRL,
 * fin de bail, expiration DPE). Basé sur les signals pour un compteur non lu
 * réactif partagé entre la barre de navigation et la page dédiée.
 */
@Injectable({ providedIn: 'root' })
export class AlertService {
  private http = inject(HttpClient);
  private apiUrl = 'http://127.0.0.1:8000/api/alerts';

  private readonly _alerts = signal<AppAlert[]>([]);
  readonly alerts = this._alerts.asReadonly();

  private readonly _page = signal<PaginatedResponse<AppAlert> | null>(null);
  readonly page = this._page.asReadonly();

  readonly loading = signal(false);

  private readonly _unreadCount = signal(0);
  /** Nombre d'alertes actives non lues, pour la pastille de notification. */
  readonly unreadCount = this._unreadCount.asReadonly();

  /**
   * Compteur de non-lues, demandé au serveur.
   *
   * Il était auparavant déduit de la liste chargée en mémoire. Maintenant que
   * la liste est paginée, ce calcul ne compterait que la page affichée : la
   * pastille indiquerait « 3 » alors que dix alertes attendent. On demande donc
   * une page d'un seul élément et on ne lit que le total.
   */
  loadUnreadCount(): void {
    this.http
      .get<PaginatedResponse<AppAlert>>(this.apiUrl, {
        params: toHttpParams({ per_page: 1, filters: { unread: '1' } }),
      })
      .subscribe({
        next: (response) => this._unreadCount.set(response.total),
        error: () => this._unreadCount.set(0),
      });
  }

  /** Charge une page d'alertes selon les critères courants. */
  load(params: Partial<ListParams> = {}): void {
    this.loading.set(true);

    this.http
      .get<PaginatedResponse<AppAlert>>(this.apiUrl, { params: toHttpParams(params) })
      .subscribe({
        next: (response) => {
          this._alerts.set(response.data);
          this._page.set(response);
          this.loading.set(false);
        },
        error: () => this.loading.set(false),
      });
  }

  markRead(id: number): Observable<unknown> {
    return this.http.post(`${this.apiUrl}/${id}/read`, {}).pipe(
      tap(() => {
        this.patch(id, { is_read: true });
        this.loadUnreadCount();
      }),
    );
  }

  markAllRead(): Observable<unknown> {
    return this.http.post(`${this.apiUrl}/read-all`, {}).pipe(
      tap(() => {
        this._alerts.update((list) => list.map((a) => ({ ...a, is_read: true })));
        // Toutes les alertes sont lues, y compris celles hors de la page.
        this._unreadCount.set(0);
      }),
    );
  }

  /** Résout l'alerte : elle disparaît de la liste active. */
  resolve(id: number): Observable<unknown> {
    return this.http.post(`${this.apiUrl}/${id}/resolve`, {}).pipe(
      tap(() => {
        this._alerts.update((list) => list.filter((a) => a.id !== id));
        this.loadUnreadCount();
      }),
    );
  }

  /** Relance le locataire pour un loyer impayé (horodatage in-app). */
  remind(id: number): Observable<unknown> {
    return this.http.post(`${this.apiUrl}/${id}/remind`, {}).pipe(
      tap(() => {
        this.patch(id, { is_read: true, reminded_at: new Date().toISOString() });
        this.loadUnreadCount();
      }),
    );
  }

  private patch(id: number, changes: Partial<AppAlert>): void {
    this._alerts.update((list) => list.map((a) => (a.id === id ? { ...a, ...changes } : a)));
  }
}
