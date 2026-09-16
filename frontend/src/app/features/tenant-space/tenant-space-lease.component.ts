import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import {
  TenantSpaceLease,
  TenantSpacePayment,
  TenantSpaceService,
} from '../../core/services/tenant-space.service';
import { ImmoproCardComponent, ImmoproPageHeaderComponent, ImmoproBadgeComponent } from 'ui-lib';

/**
 * Historique des paiements d'un bail, vu par le locataire.
 *
 * C'est la question qu'il se pose le plus souvent — « ai-je bien payé le mois
 * dernier ? » — et celle qui déclenche le plus d'appels au bailleur. Le tableau
 * répond sans intermédiaire.
 */
@Component({
  selector: 'app-tenant-space-lease',
  standalone: true,
  imports: [
    DatePipe,
    DecimalPipe,
    RouterLink,
    ImmoproCardComponent,
    ImmoproPageHeaderComponent,
    ImmoproBadgeComponent,
  ],
  templateUrl: './tenant-space-lease.component.html',
  styleUrls: ['./tenant-space-lease.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantSpaceLeaseComponent {
  private route = inject(ActivatedRoute);
  private service = inject(TenantSpaceService);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly lease = signal<TenantSpaceLease | null>(null);

  constructor() {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    this.service.lease(id).subscribe({
      next: (response) => {
        this.lease.set(response.data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Ce bail ne fait pas partie de vos dossiers.');
      },
    });
  }

  statusLabel(payment: TenantSpacePayment): string {
    return (
      { paye: 'Payé', en_retard: 'En retard', en_attente: 'À venir' }[payment.status] ??
      payment.status
    );
  }

  statusTone(payment: TenantSpacePayment): 'success' | 'danger' | 'neutral' {
    return payment.status === 'paye'
      ? 'success'
      : payment.status === 'en_retard'
        ? 'danger'
        : 'neutral';
  }
}
