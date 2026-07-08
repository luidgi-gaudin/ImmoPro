import { Injectable, inject, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';

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

interface AlertCollection {
  data: AppAlert[];
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
  readonly loading = signal(false);

  /** Nombre d'alertes actives non lues, pour la pastille de notification. */
  readonly unreadCount = computed(() => this._alerts().filter((a) => !a.is_read).length);

  /** Charge les alertes actives (non résolues) de l'utilisateur connecté. */
  load(): void {
    this.loading.set(true);
    this.http.get<AlertCollection>(this.apiUrl).subscribe({
      next: (res) => {
        this._alerts.set(res.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  markRead(id: number): Observable<unknown> {
    return this.http
      .post(`${this.apiUrl}/${id}/read`, {})
      .pipe(tap(() => this.patch(id, { is_read: true })));
  }

  markAllRead(): Observable<unknown> {
    return this.http
      .post(`${this.apiUrl}/read-all`, {})
      .pipe(tap(() => this._alerts.update((list) => list.map((a) => ({ ...a, is_read: true })))));
  }

  /** Résout l'alerte : elle disparaît de la liste active. */
  resolve(id: number): Observable<unknown> {
    return this.http
      .post(`${this.apiUrl}/${id}/resolve`, {})
      .pipe(tap(() => this._alerts.update((list) => list.filter((a) => a.id !== id))));
  }

  /** Relance le locataire pour un loyer impayé (horodatage in-app). */
  remind(id: number): Observable<unknown> {
    return this.http
      .post(`${this.apiUrl}/${id}/remind`, {})
      .pipe(tap(() => this.patch(id, { is_read: true, reminded_at: new Date().toISOString() })));
  }

  private patch(id: number, changes: Partial<AppAlert>): void {
    this._alerts.update((list) => list.map((a) => (a.id === id ? { ...a, ...changes } : a)));
  }
}
