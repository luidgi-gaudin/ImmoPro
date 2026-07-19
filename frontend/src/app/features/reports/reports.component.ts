import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ImmoproPageHeaderComponent, ImmoproStatCardComponent, ImmoproTableComponent, ImmoproBadgeComponent } from 'ui-lib';
import { ReportService, ReportOverview } from '../../core/services/report.service';

@Component({
  selector: 'app-reports',
  standalone: true,
  imports: [RouterLink, ImmoproPageHeaderComponent, ImmoproStatCardComponent, ImmoproTableComponent, ImmoproBadgeComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page reports">
      <immopro-page-header title="Rapports" subtitle="Vue d'ensemble de votre patrimoine, de vos paiements et de vos locataires."></immopro-page-header>

      @if (loading()) {
        <div class="surface" style="text-align: center; padding: 40px;">
          <p class="text-secondary">Chargement des rapports...</p>
        </div>
      } @else if (error()) {
        <div class="alert alert-error">{{ error() }}</div>
      } @else if (data(); as d) {
        <section class="kpi-grid">
          <immopro-stat-card label="Portefeuilles" [value]="d.bailleur.total_portfolios"></immopro-stat-card>
          <immopro-stat-card label="Biens occupés" [value]="d.bailleur.occupied_properties + ' / ' + d.bailleur.total_properties" trendType="success"></immopro-stat-card>
          <immopro-stat-card label="Baux actifs" [value]="d.bailleur.active_leases"></immopro-stat-card>
          <immopro-stat-card label="Loyers mensuels attendus" [value]="formatEur(d.bailleur.monthly_rent_expected)" trendType="success"></immopro-stat-card>
        </section>

        <section class="kpi-grid" style="margin-top: 16px;">
          <immopro-stat-card label="Encaissé ce mois-ci" [value]="formatEur(d.bailleur.this_month.paid_amount)" [trend]="d.bailleur.this_month.paid_count + ' paiement(s)'" trendType="success"></immopro-stat-card>
          <immopro-stat-card label="En retard ce mois-ci" [value]="formatEur(d.bailleur.this_month.late_amount)" [trend]="d.bailleur.this_month.late_count + ' échéance(s)'"></immopro-stat-card>
          <immopro-stat-card label="En attente ce mois-ci" [value]="formatEur(d.bailleur.this_month.pending_amount)" [trend]="d.bailleur.this_month.pending_count + ' échéance(s)'"></immopro-stat-card>
        </section>

        <section class="report-section">
          <h2 class="luxury-title" style="font-size: 1.3rem;">Rapport par bien</h2>
          <immopro-table>
            <thead>
              <tr>
                <th>Bien</th>
                <th>Portefeuille</th>
                <th>Statut</th>
                <th>Loyer</th>
                <th>Locataire</th>
              </tr>
            </thead>
            <tbody>
              @for (row of d.par_bien; track row.id) {
                <tr>
                  <td class="title-text">{{ row.title }}</td>
                  <td>{{ row.portfolio_name || '-' }}</td>
                  <td><immopro-badge [tone]="row.is_rented ? 'info' : 'success'">{{ row.is_rented ? 'Loué' : 'Disponible' }}</immopro-badge></td>
                  <td>{{ row.monthly_rent ? formatEur(row.monthly_rent) : '-' }}</td>
                  <td>{{ row.tenant_name || '-' }}</td>
                </tr>
              } @empty {
                <tr><td colspan="5" class="ip-table-empty">Aucun bien pour le moment.</td></tr>
              }
            </tbody>
          </immopro-table>
        </section>

        <section class="report-section">
          <h2 class="luxury-title" style="font-size: 1.3rem;">Rapport par locataire</h2>
          <immopro-table>
            <thead>
              <tr>
                <th>Locataire</th>
                <th>Total payé</th>
                <th>Total dû</th>
                <th>Retards</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (row of d.par_locataire; track row.id) {
                <tr>
                  <td class="title-text">{{ row.name }}</td>
                  <td>{{ formatEur(row.total_paid) }}</td>
                  <td>{{ formatEur(row.total_due) }}</td>
                  <td>
                    @if (row.late_count > 0) {
                      <immopro-badge tone="danger">{{ row.late_count }}</immopro-badge>
                    } @else {
                      <span class="text-muted">0</span>
                    }
                  </td>
                  <td class="actions">
                    <a [routerLink]="['/tenants', row.id]" class="btn btn--ghost btn--sm">Voir</a>
                  </td>
                </tr>
              } @empty {
                <tr><td colspan="5" class="ip-table-empty">Aucun locataire pour le moment.</td></tr>
              }
            </tbody>
          </immopro-table>
        </section>
      }
    </div>
  `,
  styles: [`
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;

      @media (max-width: 1080px) { grid-template-columns: repeat(2, 1fr); }
      @media (max-width: 520px) { grid-template-columns: 1fr; }
    }
    .report-section {
      margin-top: 32px;

      h2 { margin-bottom: 16px; }
    }
    .title-text {
      font-weight: 500;
      color: var(--text-primary);
    }
  `],
})
export class ReportsComponent implements OnInit {
  private reportService = inject(ReportService);

  protected loading = signal(false);
  protected error = signal<string | null>(null);
  protected data = signal<ReportOverview | null>(null);

  ngOnInit(): void {
    this.loading.set(true);
    this.reportService.getOverview().subscribe({
      next: (overview) => {
        this.data.set(overview);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Impossible de charger les rapports.');
        this.loading.set(false);
      },
    });
  }

  formatEur(amount: number): string {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: 0,
    }).format(amount ?? 0);
  }
}
