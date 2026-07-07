import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { DashboardService, DashboardData } from '../../core/services/dashboard.service';
import { ImmoproStatCardComponent } from 'ui-lib';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [ImmoproStatCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="dashboard-container">
      <header class="dashboard-header">
        <div class="header-content">
          <h1>Dashboard</h1>
          <p class="subtitle text-secondary">Vue d'ensemble de votre patrimoine</p>
        </div>
      </header>

      <main class="dashboard-content">
        @if (loading()) {
          <div class="loading-skeleton">
            <div class="skeleton-bar title"></div>
            <div class="skeleton-grid-4">
              <div class="skeleton-card-small"></div>
              <div class="skeleton-card-small"></div>
              <div class="skeleton-card-small"></div>
              <div class="skeleton-card-small"></div>
            </div>
          </div>
        } @else if (error()) {
          <div class="alert alert-error">
            {{ error() }}
          </div>
        } @else if (data(); as dashboardData) {
          <div class="stats-grid">
            <immopro-stat-card
              label="Portfolios"
              [value]="dashboardData.portfoliosCount"
              trend="Actif"
              trendType="success"
            ></immopro-stat-card>
            
            <immopro-stat-card
              label="Propriétés"
              [value]="dashboardData.propertiesCount"
            ></immopro-stat-card>

            <immopro-stat-card
              label="Locataires"
              [value]="dashboardData.tenantsCount"
            ></immopro-stat-card>

            <immopro-stat-card
              label="Baux en cours"
              [value]="dashboardData.leasesCount"
            ></immopro-stat-card>
          </div>

          <!-- Deferring the heavy details grid below the fold -->
          @defer (on idle) {
            <div class="content-grid">
              <div class="main-card">
                <div class="card-header">
                  <h3>Activité récente</h3>
                </div>
                <div class="activity-list">
                  @for (portfolio of dashboardData.recentPortfolios; track portfolio.id) {
                    <div class="activity-item">
                      <div>
                        <strong>{{ portfolio.name }}</strong>
                        <p>Portfolio créé ou mis à jour</p>
                      </div>
                      <span>{{ portfolio.properties_count ?? 0 }} actifs</span>
                    </div>
                  }
                  @for (lease of dashboardData.recentLeases; track lease.id) {
                    <div class="activity-item">
                      <div>
                        <strong>Bail #{{ lease.id }}</strong>
                        <p>Statut: {{ lease.statut }}</p>
                      </div>
                      <span>{{ lease.monthly_rent }} €</span>
                    </div>
                  }
                  @if (!dashboardData.recentPortfolios.length && !dashboardData.recentLeases.length) {
                    <div class="empty-state">
                      <p class="text-muted">Aucune activité récente à afficher.</p>
                    </div>
                  }
                </div>
              </div>
              <div class="side-card">
                <div class="card-header">
                  <h3>Locataires récents</h3>
                </div>
                <div class="alert-list">
                  @for (tenant of dashboardData.recentTenants; track tenant.id) {
                    <div class="alert-item">
                      <strong>{{ tenant.first_name }} {{ tenant.last_name }}</strong>
                      <p>{{ tenant.email || 'Aucun email' }}</p>
                    </div>
                  } @empty {
                    <div class="empty-state">
                      <p class="text-muted">Tout est à jour.</p>
                    </div>
                  }
                </div>
              </div>
            </div>
          } @placeholder (minimum 300ms) {
            <div class="content-grid-placeholder">
              <div class="placeholder-box large"></div>
              <div class="placeholder-box small"></div>
            </div>
          }
        }
      </main>
    </div>
  `,
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  private svc = inject(DashboardService);
  
  data = signal<DashboardData | null>(null);
  loading = signal(false);
  error = signal<string | null>(null);

  ngOnInit(): void {
    this.loading.set(true);
    this.svc.getDashboard().subscribe({
      next: (d) => {
        this.data.set(d);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Impossible de charger les données du dashboard');
        this.loading.set(false);
      },
    });
  }
}