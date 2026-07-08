import { Component, OnInit, inject, computed, ChangeDetectionStrategy } from '@angular/core';
import { DatePipe } from '@angular/common';
import { AlertService, AppAlert, AlertSeverity } from '../../core/services/alert.service';

@Component({
  selector: 'app-alerts',
  standalone: true,
  imports: [DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="alerts-page">
      <header class="alerts-header">
        <div>
          <h1>Alertes</h1>
          <p class="subtitle text-secondary">
            Les échéances à surveiller sur vos biens et vos baux : impayés, révision de loyer,
            fin de bail et diagnostics.
          </p>
        </div>
        @if (svc.unreadCount() > 0) {
          <button class="btn-ghost" (click)="markAllRead()">Tout marquer comme lu</button>
        }
      </header>

      @if (svc.loading()) {
        <div class="skeleton-list">
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
        </div>
      } @else if (svc.alerts().length === 0) {
        <div class="empty-state">
          <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
          <h3>Tout est à jour</h3>
          <p class="text-secondary">Aucune alerte en attente. Nous vous préviendrons dès qu'une échéance approche.</p>
        </div>
      } @else {
        <ul class="alerts-list">
          @for (alert of svc.alerts(); track alert.id) {
            <li class="alert-card" [class]="'severity-' + alert.severity" [class.is-unread]="!alert.is_read">
              <span class="severity-bar" aria-hidden="true"></span>

              <div class="alert-icon" [attr.aria-label]="typeLabel(alert.type)">
                @switch (alert.type) {
                  @case ('loyer_impaye') {
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"/><path d="M17 8a5 5 0 0 0-10 0c0 5-2 6-2 6h14s-2-1-2-6"/><path d="M9 18a3 3 0 0 0 6 0"/></svg>
                  }
                  @case ('revision_irl') {
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                  }
                  @case ('fin_bail') {
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  }
                  @case ('dpe_expiration') {
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                  }
                }
              </div>

              <div class="alert-body">
                <div class="alert-top">
                  <span class="type-tag">{{ typeLabel(alert.type) }}</span>
                  <span class="severity-tag">{{ severityLabel(alert.severity) }}</span>
                  @if (!alert.is_read) {
                    <span class="unread-dot" title="Non lue" aria-label="Non lue"></span>
                  }
                </div>

                <h3 class="alert-title">{{ alert.title }}</h3>
                <p class="alert-message">{{ alert.message }}</p>

                @if (alert.due_date) {
                  <p class="alert-meta text-muted">Échéance : {{ alert.due_date | date: 'dd/MM/yyyy' }}</p>
                }
                @if (alert.reminded_at) {
                  <p class="alert-meta text-muted">Locataire relancé le {{ alert.reminded_at | date: 'dd/MM/yyyy' }}</p>
                }
              </div>

              <div class="alert-actions">
                @if (alert.type === 'loyer_impaye') {
                  <button class="btn-solid" (click)="remind(alert)">
                    {{ alert.reminded_at ? 'Relancer à nouveau' : 'Relancer le locataire' }}
                  </button>
                }
                @if (!alert.is_read) {
                  <button class="btn-ghost" (click)="markRead(alert)">Marquer comme lu</button>
                }
                <button class="btn-ghost" (click)="resolve(alert)">Résoudre</button>
              </div>
            </li>
          }
        </ul>
      }
    </div>
  `,
  styleUrl: './alerts.component.scss',
})
export class AlertsComponent implements OnInit {
  protected svc = inject(AlertService);

  ngOnInit(): void {
    this.svc.load();
  }

  protected readonly hasUnread = computed(() => this.svc.unreadCount() > 0);

  markRead(alert: AppAlert): void {
    this.svc.markRead(alert.id).subscribe();
  }

  markAllRead(): void {
    this.svc.markAllRead().subscribe();
  }

  resolve(alert: AppAlert): void {
    this.svc.resolve(alert.id).subscribe();
  }

  remind(alert: AppAlert): void {
    this.svc.remind(alert.id).subscribe();
  }

  typeLabel(type: AppAlert['type']): string {
    return {
      loyer_impaye: 'Loyer impayé',
      revision_irl: 'Révision du loyer',
      fin_bail: 'Fin de bail',
      dpe_expiration: 'Diagnostic DPE',
    }[type];
  }

  severityLabel(severity: AlertSeverity): string {
    return { critical: 'Urgent', warning: 'À prévoir', info: 'Information' }[severity];
  }
}
