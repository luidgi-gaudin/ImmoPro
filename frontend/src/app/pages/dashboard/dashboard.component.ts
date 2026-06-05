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
            [value]="data?.portfoliosCount ?? '0'"
            trend="Actif"
            trendType="success"
          ></immopro-stat-card>
          
          <immopro-stat-card
            label="Propriétés"
            [value]="data?.propertiesCount ?? '0'"
          ></immopro-stat-card>

          <immopro-stat-card
            label="Locataires"
            [value]="data?.tenantsCount ?? '0'"
          ></immopro-stat-card>

          <immopro-stat-card
            label="Baux en cours"
            [value]="data?.leasesCount ?? '0'"
          ></immopro-stat-card>
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