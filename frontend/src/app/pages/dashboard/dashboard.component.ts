import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DashboardService } from '../../services/dashboard.service';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule],
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
          <div class="stat-card">
            <div class="stat-label">Portfolios</div>
            <div class="stat-value">{{ data?.portfoliosCount ?? '0' }}</div>
            <div class="stat-trend success">Actif</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Propriétés</div>
            <div class="stat-value">{{ data?.propertiesCount ?? '0' }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Locataires</div>
            <div class="stat-value">{{ data?.tenantsCount ?? '0' }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Baux en cours</div>
            <div class="stat-value">{{ data?.leasesCount ?? '0' }}</div>
          </div>
        </div>

        <div class="content-grid">
          <div class="main-card">
            <div class="card-header">
              <h3>Activité récente</h3>
            </div>
            <div class="empty-state">
              <p class="text-muted">Aucune activité récente à afficher.</p>
            </div>
          </div>
          <div class="side-card">
            <div class="card-header">
              <h3>Alertes</h3>
            </div>
            <div class="empty-state">
              <p class="text-muted">Tout est à jour.</p>
            </div>
          </div>
        </div>
      </main>
    </div>
  `,
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  data: import('../../services/dashboard.service').DashboardData | null = null;

  constructor(private svc: DashboardService) {}

  ngOnInit(): void {
    this.svc.getDashboard().subscribe({ next: (d) => (this.data = d) });
  }
}