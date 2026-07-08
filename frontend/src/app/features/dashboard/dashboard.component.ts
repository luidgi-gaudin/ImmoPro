import { Component, OnInit, inject, signal, computed, ChangeDetectionStrategy } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ImmoproStatCardComponent } from 'ui-lib';
import { DashboardService, DashboardData } from '../../core/services/dashboard.service';
import { AuthService } from '../../core/services/auth.service';
import { AlertService, AppAlert } from '../../core/services/alert.service';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [RouterLink, ImmoproStatCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page dashboard">
      <header class="page-head">
        <div class="page-heading">
          <h1>Bonjour{{ firstName() ? ', ' + firstName() : '' }}</h1>
          <p class="page-subtitle">Voici l'essentiel de votre gestion locative aujourd'hui.</p>
        </div>
      </header>

      @if (loading()) {
        <div class="kpi-grid">
          <div class="skeleton-kpi"></div>
          <div class="skeleton-kpi"></div>
          <div class="skeleton-kpi"></div>
          <div class="skeleton-kpi"></div>
        </div>
      } @else if (error()) {
        <div class="alert alert-error">{{ error() }}</div>
      } @else if (data(); as d) {
        <section class="kpi-grid">
          <immopro-stat-card label="Portefeuilles" [value]="d.portfoliosCount"></immopro-stat-card>
          <immopro-stat-card label="Biens gérés" [value]="d.propertiesCount"></immopro-stat-card>
          <immopro-stat-card label="Baux actifs" [value]="d.activeLeasesCount"></immopro-stat-card>
          <immopro-stat-card
            label="Loyers attendus / mois"
            [value]="formatEur(d.monthlyRentExpected)"
            trend="Sur les baux actifs"
            trendType="success"
          ></immopro-stat-card>
        </section>

        <section class="dash-grid">
          <!-- Alertes prioritaires -->
          <div class="surface panel priority-panel">
            <div class="panel-head">
              <h3>Alertes prioritaires</h3>
              <a routerLink="/alerts" class="btn btn--ghost btn--sm">Tout voir</a>
            </div>

            @if (priorityAlerts().length === 0) {
              <div class="panel-empty">
                <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                <p>Tout est à jour. Aucune échéance ne requiert votre attention.</p>
              </div>
            } @else {
              <ul class="priority-list">
                @for (alert of priorityAlerts(); track alert.id) {
                  <li class="priority-item" [class]="'sev-' + alert.severity" [routerLink]="'/alerts'">
                    <span class="sev-dot" aria-hidden="true"></span>
                    <div class="priority-body">
                      <div class="priority-top">
                        <span class="priority-title">{{ alert.title }}</span>
                        <span class="badge" [class]="badgeClass(alert.severity)">{{ severityLabel(alert.severity) }}</span>
                      </div>
                      <p class="priority-msg">{{ alert.message }}</p>
                    </div>
                  </li>
                }
              </ul>
            }
          </div>

          <div class="dash-col">
            <!-- Actions rapides -->
            <div class="surface panel">
              <div class="panel-head"><h3>Actions rapides</h3></div>
              <div class="quick-actions">
                <a routerLink="/portfolios" class="quick-action">
                  <span class="qa-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg></span>
                  <span>Ajouter un bien</span>
                  <svg class="qa-chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <a routerLink="/tenants" class="quick-action">
                  <span class="qa-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></span>
                  <span>Ajouter un locataire</span>
                  <svg class="qa-chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <a routerLink="/leases" class="quick-action">
                  <span class="qa-icon"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg></span>
                  <span>Créer un bail</span>
                  <svg class="qa-chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
              </div>
            </div>

            <!-- Locataires récents -->
            <div class="surface panel">
              <div class="panel-head">
                <h3>Locataires récents</h3>
                <a routerLink="/tenants" class="btn btn--ghost btn--sm">Voir</a>
              </div>
              <ul class="mini-list">
                @for (tenant of d.recentTenants; track tenant.id) {
                  <li>
                    <span class="mini-avatar">{{ tenant.first_name.charAt(0) }}{{ tenant.last_name.charAt(0) }}</span>
                    <div>
                      <strong>{{ tenant.first_name }} {{ tenant.last_name }}</strong>
                      <span class="text-muted">{{ tenant.email || 'Aucun email' }}</span>
                    </div>
                  </li>
                } @empty {
                  <li class="mini-empty text-muted">Aucun locataire pour le moment.</li>
                }
              </ul>
            </div>
          </div>
        </section>
      }
    </div>
  `,
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  private svc = inject(DashboardService);
  private auth = inject(AuthService);
  protected alerts = inject(AlertService);

  protected data = signal<DashboardData | null>(null);
  protected loading = signal(false);
  protected error = signal<string | null>(null);

  protected readonly firstName = computed(() => this.auth.currentUser()?.name?.split(' ')[0] ?? '');

  private readonly severityRank: Record<AppAlert['severity'], number> = {
    critical: 0,
    warning: 1,
    info: 2,
  };

  /** Les alertes les plus urgentes, tous types confondus, pour l'aperçu. */
  protected readonly priorityAlerts = computed(() =>
    [...this.alerts.alerts()]
      .sort((a, b) => this.severityRank[a.severity] - this.severityRank[b.severity])
      .slice(0, 4)
  );

  ngOnInit(): void {
    this.loading.set(true);
    this.svc.getDashboard().subscribe({
      next: (d) => {
        this.data.set(d);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Impossible de charger les données du tableau de bord.');
        this.loading.set(false);
      },
    });

    // Rafraîchit les alertes si le shell ne les a pas déjà chargées.
    if (this.alerts.alerts().length === 0) {
      this.alerts.load();
    }
  }

  formatEur(amount: number): string {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: 0,
    }).format(amount ?? 0);
  }

  severityLabel(severity: AppAlert['severity']): string {
    return { critical: 'Urgent', warning: 'À prévoir', info: 'Info' }[severity];
  }

  badgeClass(severity: AppAlert['severity']): string {
    return { critical: 'badge--danger', warning: 'badge--warning', info: 'badge--info' }[severity];
  }
}
