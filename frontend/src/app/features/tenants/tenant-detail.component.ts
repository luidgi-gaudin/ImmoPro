import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { DatePipe } from '@angular/common';
import {
  ImmoproCardComponent,
  ImmoproAvatarComponent,
  ImmoproBadgeComponent,
  ImmoproEmptyStateComponent,
} from 'ui-lib';
import { TenantService, Tenant } from '../../core/services/tenant.service';
import { LeaseService, Lease } from '../../core/services/lease.service';
import { DocumentsPanelComponent } from '../../shared/components/documents-panel/documents-panel.component';
import { maskBankIdentifier } from '../../core/format/bank-identifier';

@Component({
  selector: 'app-tenant-detail',
  standalone: true,
  imports: [
    RouterLink,
    DatePipe,
    ImmoproCardComponent,
    ImmoproAvatarComponent,
    ImmoproBadgeComponent,
    ImmoproEmptyStateComponent,
    DocumentsPanelComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="tenant-detail-actions">
      <a routerLink="/tenants" class="btn btn--ghost btn--sm">← Retour aux locataires</a>
    </div>

    @if (loading()) {
      <div class="surface" style="text-align: center; padding: 40px;">
        <p class="text-secondary">Chargement du locataire...</p>
      </div>
    } @else if (tenant(); as t) {
      <immopro-card>
        <div card-header class="tenant-header">
          <immopro-avatar
            size="lg"
            [initials]="t.first_name.charAt(0) + t.last_name.charAt(0)"
          ></immopro-avatar>
          <div>
            <h2 class="luxury-title" style="margin: 0; font-size: 1.6rem;">
              {{ t.first_name }} {{ t.last_name }}
            </h2>
            <p class="text-secondary" style="margin-top: 4px;">
              {{ t.email || 'Aucun email renseigné' }}
            </p>
          </div>
        </div>

        <div class="detail-grid">
          <div class="detail-item">
            <span class="label text-muted">Téléphone</span>
            <strong class="value">{{ t.phone || '-' }}</strong>
          </div>
          <div class="detail-item">
            <span class="label text-muted">Adresse</span>
            <strong class="value">{{ t.address || '-' }}</strong>
          </div>
          <div class="detail-item">
            <span class="label text-muted">Pays</span>
            <strong class="value">{{ t.country || '-' }}</strong>
          </div>
          <!-- Coordonnées bancaires masquées par défaut.
               Elles sont chiffrées au repos en base, mais restaient affichées en
               clair à l'écran — donc lisibles par-dessus l'épaule, et capturées
               par le moindre partage d'écran. Les révéler reste à un clic. -->
          <div class="detail-item">
            <span class="label text-muted">IBAN</span>
            <strong class="value">
              <code>{{ bankVisible() ? t.iban || '-' : mask(t.iban) }}</code>
            </strong>
          </div>
          <div class="detail-item">
            <span class="label text-muted">BIC</span>
            <strong class="value">
              <code>{{ bankVisible() ? t.bic || '-' : mask(t.bic) }}</code>
            </strong>
            @if (t.iban || t.bic) {
              <button type="button" class="reveal" (click)="bankVisible.set(!bankVisible())">
                {{ bankVisible() ? 'Masquer' : 'Afficher les coordonnées' }}
              </button>
            }
          </div>
        </div>
      </immopro-card>

      <div class="leases-section">
        <h3 class="luxury-title" style="font-size: 1.25rem;">Baux associés</h3>

        @if (leasesLoading()) {
          <p class="text-secondary">Chargement des baux...</p>
        } @else if (tenantLeases().length === 0) {
          <immopro-empty-state message="Aucun bail n'est associé à ce locataire pour le moment.">
            <svg
              icon
              xmlns="http://www.w3.org/2000/svg"
              width="32"
              height="32"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.5"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
            </svg>
          </immopro-empty-state>
        } @else {
          <div class="lease-cards">
            @for (lease of tenantLeases(); track lease.id) {
              <a routerLink="/leases" class="lease-card">
                <div class="lease-card-top">
                  <span class="lease-id">Bail #{{ lease.id }}</span>
                  <immopro-badge [tone]="leaseTone(lease.statut)">{{ lease.statut }}</immopro-badge>
                </div>
                <div class="lease-card-meta">
                  <span>{{ lease.property?.title || 'Bien #' + lease.property_id }}</span>
                  <span
                    >{{ lease.start_date | date: 'dd/MM/yyyy' }}
                    @if (lease.end_date) {
                      → {{ lease.end_date | date: 'dd/MM/yyyy' }}
                    }
                  </span>
                </div>
                <strong class="lease-rent">{{ lease.monthly_rent }} € / mois</strong>
              </a>
            }
          </div>
        }
      </div>

      <!-- Pièce d'identité, justificatifs de revenus, acte de cautionnement. -->
      <div class="documents-section">
        <app-documents-panel
          type="tenant"
          [entityId]="t.id"
          [entityLabel]="t.first_name + ' ' + t.last_name"
        />
      </div>
    } @else {
      <immopro-empty-state
        title="Locataire introuvable"
        message="Ce locataire n'existe plus ou a été supprimé."
      >
        <svg
          icon
          xmlns="http://www.w3.org/2000/svg"
          width="34"
          height="34"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <circle cx="12" cy="12" r="9" />
          <line x1="12" y1="8" x2="12" y2="12" />
          <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
      </immopro-empty-state>
    }
  `,
  styles: [
    `
      .tenant-detail-actions {
        margin-bottom: 16px;
      }
      .documents-section {
        margin-top: 24px;
      }
      .reveal {
        align-self: flex-start;
        margin-top: 6px;
        padding: 0;
        border: none;
        background: none;
        color: var(--primary);
        font: inherit;
        font-size: 0.78rem;
        cursor: pointer;

        &:hover {
          text-decoration: underline;
        }
      }
      .tenant-header {
        display: flex;
        align-items: center;
        gap: 20px;
      }
      .detail-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;

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
      .leases-section {
        margin-top: 30px;
      }
      .lease-cards {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 14px;
        margin-top: 16px;
      }
      .lease-card {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 16px 18px;
        background: var(--surface-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        transition: all var(--transition-fast);

        &:hover {
          border-color: var(--border-hover);
          transform: translateY(-2px);
        }
      }
      .lease-card-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .lease-id {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-secondary);
      }
      .lease-card-meta {
        display: flex;
        flex-direction: column;
        gap: 2px;
        font-size: 0.82rem;
        color: var(--text-secondary);
      }
      .lease-rent {
        color: var(--primary);
      }
    `,
  ],
})
export class TenantDetailComponent implements OnInit {
  /** Les coordonnées bancaires ne s'affichent qu'à la demande. */
  protected readonly bankVisible = signal(false);

  protected mask(value: string | null | undefined): string {
    return maskBankIdentifier(value);
  }

  private route = inject(ActivatedRoute);
  private tenantService = inject(TenantService);
  private leaseService = inject(LeaseService);

  protected loading = signal(false);
  protected tenant = signal<Tenant | undefined>(undefined);
  protected leasesLoading = signal(false);
  protected tenantLeases = signal<Lease[]>([]);

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    this.loading.set(true);
    this.tenantService.getTenant(id).subscribe({
      next: (tenant) => {
        this.tenant.set(tenant);
        this.loading.set(false);
      },
      error: () => {
        this.tenant.set(undefined);
        this.loading.set(false);
      },
    });

    this.leasesLoading.set(true);
    // Le filtrage se fait côté serveur : inutile de télécharger tous les baux
    // du bailleur pour n'en garder que ceux de ce locataire.
    this.leaseService.getLeases({ per_page: 100, filters: { tenant_id: String(id) } }).subscribe({
      next: (response) => {
        this.tenantLeases.set(response.data);
        this.leasesLoading.set(false);
      },
      error: () => this.leasesLoading.set(false),
    });
  }

  leaseTone(statut: string): 'success' | 'danger' | 'info' {
    return (
      { actif: 'success' as const, termine: 'danger' as const, en_attente: 'info' as const }[
        statut
      ] ?? 'info'
    );
  }
}
