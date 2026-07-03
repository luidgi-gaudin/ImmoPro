import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DashboardService } from '../../services/dashboard.service';
import { ImmoproStatCardComponent } from 'ui-lib';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, ImmoproStatCardComponent],
  template: `
    <div class="dashboard-container">
      <header class="dashboard-header">
        <div class="header-content">
          <h1>Dashboard</h1>
          <p class="subtitle text-secondary">Vue d'ensemble de votre patrimoine</p>
        </div>
      </header>

      <main class="dashboard-content">
        <div class="stats-grid">
          <immopro-stat-card
            label="Portfolios"
            [value]="data?.portfoliosCount ?? 0"
            trend="Actif"
            trendType="success"
          ></immopro-stat-card>
          
          <immopro-stat-card
            label="Propriétés"
            [value]="data?.propertiesCount ?? 0"
          ></immopro-stat-card>

          <immopro-stat-card
            label="Locataires"
            [value]="data?.tenantsCount ?? 0"
          ></immopro-stat-card>

          <immopro-stat-card
            label="Baux en cours"
            [value]="data?.leasesCount ?? 0"
          ></immopro-stat-card>
        </div>

        <div class="content-grid">
          <div class="main-card">
            <div class="card-header">
              <h3>Activité récente</h3>
            </div>
            <div class="activity-list" *ngIf="data as dashboardData">
              <div class="activity-item" *ngFor="let portfolio of dashboardData.recentPortfolios">
                <div>
                  <strong>{{ portfolio.name }}</strong>
                  <p>Portfolio créé ou mis à jour</p>
                </div>
                <span>{{ portfolio.properties_count ?? 0 }} actifs</span>
              </div>
              <div class="activity-item" *ngFor="let lease of dashboardData.recentLeases">
                <div>
                  <strong>Bail #{{ lease.id }}</strong>
                  <p>Statut: {{ lease.statut }}</p>
                </div>
                <span>{{ lease.monthly_rent }} €</span>
              </div>
              <div class="empty-state" *ngIf="!dashboardData.recentPortfolios.length && !dashboardData.recentLeases.length">
                <p class="text-muted">Aucune activité récente à afficher.</p>
              </div>
            </div>
          </div>
          <div class="side-card">
            <div class="card-header">
              <h3>Alertes</h3>
            </div>
            <div class="alert-list" *ngIf="data as dashboardData; else alertsEmpty">
              <div class="alert-item" *ngFor="let tenant of dashboardData.recentTenants">
                <strong>{{ tenant.first_name }} {{ tenant.last_name }}</strong>
                <p>{{ tenant.email || 'Aucun email' }}</p>
              </div>
            </div>
            <ng-template #alertsEmpty>
              <div class="empty-state">
                <p class="text-muted">Tout est à jour.</p>
              </div>
            </ng-template>
          </div>
        </div>
      </main>
    </div>
  `,
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  data: import('../../services/dashboard.service').DashboardData | null = null;
  loading = false;
  error: string | null = null;

  constructor(private svc: DashboardService) {}

  ngOnInit(): void {
    this.loading = true;
    this.svc.getDashboard().subscribe({
      next: (d) => {
        this.data = d;
        this.loading = false;
      },
      error: () => {
        this.error = 'Impossible de charger les données du dashboard';
        this.loading = false;
      },
    });
  }
}