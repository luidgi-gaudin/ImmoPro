import { Component, inject, ChangeDetectionStrategy } from '@angular/core';
import { ImmoproStatCardComponent } from 'ui-lib';
import { PortfolioContextService } from './portfolio-context.service';
import { DocumentsPanelComponent } from '../../shared/components/documents-panel/documents-panel.component';

@Component({
  selector: 'app-portfolio-overview',
  standalone: true,
  imports: [ImmoproStatCardComponent, DocumentsPanelComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="kpi-grid">
      <immopro-stat-card label="Actifs au total" [value]="ctx.stats().total"></immopro-stat-card>
      <immopro-stat-card
        label="Loués"
        [value]="ctx.stats().rented"
        trendType="success"
      ></immopro-stat-card>
      <immopro-stat-card label="Disponibles" [value]="ctx.stats().available"></immopro-stat-card>
      <immopro-stat-card
        label="Loyers mensuels attendus"
        [value]="formatEur(ctx.stats().monthlyRent)"
        trendType="success"
      ></immopro-stat-card>
    </section>

    @if (ctx.portfolio()?.description) {
      <div class="surface description-block">
        <h3>Description</h3>
        <p class="text-secondary">{{ ctx.portfolio()?.description }}</p>
      </div>
    }

    <!-- Mandat de gestion et pièces qui valent pour tout le portefeuille,
         plutôt que pour un bien en particulier. -->
    @if (ctx.portfolio(); as portfolio) {
      <div class="documents-section">
        <app-documents-panel
          type="portfolio"
          [entityId]="portfolio.id"
          [entityLabel]="portfolio.name"
        />
      </div>
    }
  `,
  styles: [
    `
      .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
      }
      .documents-section {
        margin-top: 20px;
      }
      .description-block {
        margin-top: 20px;
        padding: 24px 28px;
        h3 {
          margin: 0 0 8px;
          font-size: 1.1rem;
        }
        p {
          margin: 0;
        }
      }
      @media (max-width: 1080px) {
        .kpi-grid {
          grid-template-columns: repeat(2, 1fr);
        }
      }
      @media (max-width: 520px) {
        .kpi-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export class PortfolioOverviewComponent {
  protected ctx = inject(PortfolioContextService);

  constructor() {
    // Cet écran n'affiche que des compteurs : il n'a aucune liste à demander,
    // c'est donc lui qui charge le portefeuille. `ensureLoaded()` ne fait rien
    // si une autre sous-page l'a déjà rapporté.
    this.ctx.ensureLoaded();
  }

  formatEur(amount: number): string {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: 0,
    }).format(amount ?? 0);
  }
}
