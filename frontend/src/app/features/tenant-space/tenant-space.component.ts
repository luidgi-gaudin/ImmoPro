import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import {
  TenantSpaceLease,
  TenantSpaceOverview,
  TenantSpaceService,
} from '../../core/services/tenant-space.service';
import { ImmoproCardComponent, ImmoproPageHeaderComponent, ImmoproBadgeComponent } from 'ui-lib';

/**
 * Accueil du locataire : ses logements, ses baux, ce qu'il reste à payer.
 *
 * Un locataire n'a pas de parc à piloter — il a un logement, parfois deux. Cet
 * écran est donc une fiche, pas un tableau de bord : tout ce qui compte tient
 * dans la première hauteur d'écran, et rien n'y est filtrable.
 */
@Component({
  selector: 'app-tenant-space',
  standalone: true,
  imports: [
    DatePipe,
    DecimalPipe,
    RouterLink,
    ImmoproCardComponent,
    ImmoproPageHeaderComponent,
    ImmoproBadgeComponent,
  ],
  templateUrl: './tenant-space.component.html',
  styleUrls: ['./tenant-space.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantSpaceComponent {
  private service = inject(TenantSpaceService);
  private auth = inject(AuthService);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  readonly overview = signal<TenantSpaceOverview | null>(null);

  /**
   * Explication renvoyée par le serveur quand aucun dossier n'est rattaché.
   *
   * Un écran vide sans un mot ressemble à une panne : le compte existe, mais
   * aucun bailleur n'a encore inscrit cette adresse sur une fiche locataire.
   */
  readonly notice = signal<string | null>(null);

  readonly userName = computed(() => this.auth.currentUser()?.name ?? '');

  readonly hasFiles = computed(() => (this.overview()?.leases.length ?? 0) > 0);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.service.overview().subscribe({
      next: (response) => {
        this.overview.set(response.data);
        this.notice.set(response.message ?? null);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set("Votre espace n'a pas pu être chargé. Réessayez dans un instant.");
      },
    });
  }

  /** Ton de la pastille de statut du bail. */
  leaseTone(lease: TenantSpaceLease): 'success' | 'info' | 'neutral' {
    return lease.statut === 'actif'
      ? 'success'
      : lease.statut === 'en_attente'
        ? 'info'
        : 'neutral';
  }

  /** Équipements présents, pour n'afficher que ce que le logement a. */
  amenities(lease: TenantSpaceLease): string[] {
    const property = lease.property;

    if (!property) {
      return [];
    }

    return [
      property.is_furnished ? 'Meublé' : null,
      property.has_balcony ? 'Balcon' : null,
      property.has_terrace ? 'Terrasse' : null,
      property.has_garden ? 'Jardin' : null,
      property.has_garage ? 'Garage' : null,
      property.has_parking ? 'Parking' : null,
      property.has_cave ? 'Cave' : null,
    ].filter((label): label is string => label !== null);
  }
}
