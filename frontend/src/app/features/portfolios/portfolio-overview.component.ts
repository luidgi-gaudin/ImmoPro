import { Component, inject, ChangeDetectionStrategy } from '@angular/core';
import { ImmoproStatCardComponent } from 'ui-lib';
import { PortfolioContextService } from './portfolio-context.service';

@Component({
  selector: 'app-portfolio-overview',
  standalone: true,
  imports: [ImmoproStatCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="kpi-grid">
      <immopro-stat-card label="Actifs au total" [value]="ctx.stats().total"></immopro-stat-card>
      <immopro-stat-card label="Loués" [value]="ctx.stats().rented" trendType="success"></immopro-stat-card>
      <immopro-stat-card label="Disponibles" [value]="ctx.stats().available"></immopro-stat-card>
      <immopro-stat-card label="Loyers mensuels attendus" [value]="formatEur(ctx.stats().monthlyRent)" trendType="success"></immopro-stat-card>
    </section>

    @if (ctx.portfolio()?.description) {
      <div class="surface description-block">
        <h3>Description</h3>
        <p class="text-secondary">{{ ctx.portfolio()?.description }}</p>
      </div>
    }
  `,
  styles: [`
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
    }
    .description-block {
      margin-top: 20px;
      padding: 24px 28px;
      h3 { margin: 0 0 8px; font-size: 1.1rem; }
      p { margin: 0; }
    }
    @media (max-width: 1080px) {
      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 520px) {
      .kpi-grid { grid-template-columns: 1fr; }
    }
  `],
})
export class PortfolioOverviewComponent {
  protected ctx = inject(PortfolioContextService);

  formatEur(amount: number): string {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: 0,
    }).format(amount ?? 0);
  }
}
