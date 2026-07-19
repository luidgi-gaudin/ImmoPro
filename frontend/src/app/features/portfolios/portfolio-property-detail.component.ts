import { Component, OnInit, inject, signal, computed, ChangeDetectionStrategy } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ImmoproCardComponent, ImmoproBadgeComponent, ImmoproDpeBadgeComponent, ImmoproEmptyStateComponent } from 'ui-lib';
import { PortfolioContextService } from './portfolio-context.service';

@Component({
  selector: 'app-portfolio-property-detail',
  standalone: true,
  imports: [RouterLink, ImmoproCardComponent, ImmoproBadgeComponent, ImmoproDpeBadgeComponent, ImmoproEmptyStateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (ctx.propertiesLoading()) {
      <div class="surface" style="text-align: center; padding: 40px;">
        <p class="text-secondary">Chargement de l'actif...</p>
      </div>
    } @else if (property(); as prop) {
      <div class="property-detail-actions">
        <a [routerLink]="['/portfolios', ctx.portfolioId(), 'properties']" class="btn btn--ghost btn--sm">← Retour aux actifs</a>
      </div>

      <immopro-card>
        <div card-header class="detail-header">
          <div>
            <h2 class="luxury-title" style="margin: 0; font-size: 1.7rem;">{{ prop.title }}</h2>
            <p class="text-secondary" style="margin-top: 6px;">{{ prop.address }}, {{ prop.postal_code }} {{ prop.city }}</p>
          </div>
          <div class="detail-header-badges">
            <immopro-dpe-badge [value]="prop.dpe"></immopro-dpe-badge>
            <immopro-badge [tone]="prop.is_rented ? 'info' : 'success'">{{ prop.is_rented ? 'Loué' : 'Disponible' }}</immopro-badge>
          </div>
        </div>

        <div class="detail-grid">
          <div class="detail-item">
            <span class="label text-muted">Type de bien</span>
            <strong class="value capitalize">{{ prop.property_type }}</strong>
          </div>
          <div class="detail-item">
            <span class="label text-muted">Loyer mensuel prévu</span>
            <strong class="value text-gold">{{ prop.monthly_rent ? (prop.monthly_rent + ' €') : '-' }}</strong>
          </div>
          <div class="detail-item">
            <span class="label text-muted">Surface habitable</span>
            <strong class="value">{{ prop.area_sqm ? (prop.area_sqm + ' m²') : '-' }}</strong>
          </div>
          <div class="detail-item">
            <span class="label text-muted">Nombre de pièces</span>
            <strong class="value">{{ prop.rooms ?? '-' }}</strong>
          </div>
        </div>

        <div class="amenities-row">
          <span class="label text-muted">Équipements</span>
          <div class="amenities-badges">
            @if (prop.has_balcony) { <immopro-badge tone="neutral">Balcon</immopro-badge> }
            @if (prop.has_garden) { <immopro-badge tone="neutral">Jardin</immopro-badge> }
            @if (prop.has_parking) { <immopro-badge tone="neutral">Parking</immopro-badge> }
            @if (prop.has_cave) { <immopro-badge tone="neutral">Cave</immopro-badge> }
            @if (!prop.has_balcony && !prop.has_garden && !prop.has_parking && !prop.has_cave) {
              <span class="text-muted">Aucun équipement renseigné</span>
            }
          </div>
        </div>

        @if (prop.description) {
          <div card-footer>
            <span class="label text-muted" style="display: block; margin-bottom: 6px;">Description</span>
            <p class="text-secondary" style="margin: 0;">{{ prop.description }}</p>
          </div>
        }
      </immopro-card>
    } @else {
      <immopro-empty-state title="Actif introuvable" message="Cet actif n'existe plus ou a été supprimé de ce portefeuille.">
        <svg icon xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </immopro-empty-state>
    }
  `,
  styles: [`
    .property-detail-actions {
      margin-bottom: 16px;
    }
    .detail-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
    }
    .detail-header-badges {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
      margin-bottom: 24px;

      @media (max-width: 700px) {
        grid-template-columns: 1fr 1fr;
      }
    }
    .detail-item {
      display: flex;
      flex-direction: column;
      gap: 4px;

      .label {
        font-size: 0.7rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }
      .value {
        font-size: 0.95rem;
        color: var(--text-primary);
      }
    }
    .amenities-row {
      .label {
        font-size: 0.7rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        display: block;
        margin-bottom: 8px;
      }
    }
    .amenities-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .capitalize {
      text-transform: capitalize;
    }
  `],
})
export class PortfolioPropertyDetailComponent implements OnInit {
  private route = inject(ActivatedRoute);
  protected ctx = inject(PortfolioContextService);

  private propertyId = signal<number | null>(null);
  protected property = computed(() => {
    const id = this.propertyId();
    return id ? this.ctx.findProperty(id) : undefined;
  });

  ngOnInit(): void {
    this.propertyId.set(Number(this.route.snapshot.paramMap.get('propertyId')));
  }
}
