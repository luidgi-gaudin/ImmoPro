import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { EMPTY, catchError, switchMap, tap } from 'rxjs';
import {
  ImmoproBadgeComponent,
  ImmoproEmptyStateComponent,
  ImmoproFilterBarComponent,
  ImmoproPageHeaderComponent,
  ImmoproPaginationComponent,
  ImmoproSelectComponent,
  ImmoproSkeletonComponent,
  ImmoproTableComponent,
} from 'ui-lib';
import { createListQuery } from '../../core/list/list-query';
import { PaginatedResponse } from '../../core/list/pagination.model';
import {
  AppDocument,
  DocumentService,
  DocumentableType,
} from '../../core/services/document.service';
import { ConfirmService } from '../../core/services/confirm.service';
import { NotificationService } from '../../core/services/notification.service';

/**
 * Coffre documentaire : toutes les pièces du bailleur, tous rattachements
 * confondus.
 *
 * Les fiches (bail, bien, locataire, portefeuille) portent chacune leur panneau
 * de pièces jointes, ce qui répond à « quels documents pour ce bail ». Cet écran
 * répond à l'autre question, celle qu'aucune fiche ne peut traiter : « quelles
 * attestations d'assurance arrivent à échéance », « où est passé ce diagnostic ».
 */
@Component({
  selector: 'app-documents',
  standalone: true,
  imports: [
    DatePipe,
    RouterLink,
    ImmoproBadgeComponent,
    ImmoproEmptyStateComponent,
    ImmoproFilterBarComponent,
    ImmoproPageHeaderComponent,
    ImmoproPaginationComponent,
    ImmoproSelectComponent,
    ImmoproSkeletonComponent,
    ImmoproTableComponent,
  ],
  templateUrl: './documents.component.html',
  styleUrl: './documents.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DocumentsComponent {
  private readonly service = inject(DocumentService);
  private readonly notifications = inject(NotificationService);
  private readonly confirm = inject(ConfirmService);

  protected readonly list = createListQuery({
    defaultSort: 'created_at',
    defaultDirection: 'desc',
    filterKeys: ['category', 'documentable_type', 'expiration'],
  });

  protected readonly documents = signal<AppDocument[]>([]);
  protected readonly pagination = signal<PaginatedResponse<AppDocument> | undefined>(undefined);
  protected readonly loading = signal(false);
  private readonly firstLoadDone = signal(false);

  protected readonly showSkeleton = computed(() => !this.firstLoadDone() && this.loading());
  protected readonly skeletonRows = Array.from({ length: 6 });

  protected readonly categories = this.service.categories;

  /** Les entités auxquelles une pièce peut être rattachée, pour le filtre. */
  protected readonly attachments: { value: DocumentableType; label: string }[] = [
    { value: 'lease', label: 'Baux' },
    { value: 'property', label: 'Biens' },
    { value: 'tenant', label: 'Locataires' },
    { value: 'portfolio', label: 'Portefeuilles' },
  ];

  protected readonly expirationChoices = [
    { value: 'expire', label: 'Expirés' },
    { value: 'bientot', label: 'Expirent sous 3 mois' },
    { value: 'valide', label: 'En cours de validité' },
  ];

  /**
   * Filtres actifs, rappelés sous la barre de recherche.
   *
   * Un filtre replié dans un menu déroulant se perd de vue : on cherche ensuite
   * pourquoi la liste « ne montre rien », alors qu'un critère posé dix minutes
   * plus tôt l'explique.
   */
  protected readonly activeChips = computed(() => {
    const filters = this.list.filters();
    const chips: { key: string; label: string; value: string }[] = [];

    if (filters['category']) {
      chips.push({
        key: 'category',
        label: 'Catégorie',
        value:
          this.categories().find((c) => c.value === filters['category'])?.label ??
          filters['category'],
      });
    }

    if (filters['documentable_type']) {
      chips.push({
        key: 'documentable_type',
        label: 'Rattaché à',
        value:
          this.attachments.find((a) => a.value === filters['documentable_type'])?.label ??
          filters['documentable_type'],
      });
    }

    if (filters['expiration']) {
      chips.push({
        key: 'expiration',
        label: 'Validité',
        value:
          this.expirationChoices.find((e) => e.value === filters['expiration'])?.label ??
          filters['expiration'],
      });
    }

    return chips;
  });

  constructor() {
    this.service.loadCatalogue().subscribe({ error: () => undefined });

    toObservable(this.list.trigger)
      .pipe(
        tap(() => this.loading.set(true)),
        // switchMap annule la requête précédente : en tapant dans la recherche,
        // seule la dernière frappe compte.
        switchMap(({ params }) =>
          this.service.list(params).pipe(
            catchError((error) => {
              this.loading.set(false);
              this.notifications.fromHttp(error, 'Impossible de charger les documents.');
              return EMPTY;
            }),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((page) => {
        this.documents.set(page.data);
        this.pagination.set(page);
        this.loading.set(false);
        this.firstLoadDone.set(true);
      });
  }

  /** Lien vers la fiche d'où vient la pièce, quand elle en a une. */
  protected linkFor(item: AppDocument): unknown[] | null {
    const { type, id } = item.attached_to;

    if (id === null) {
      return null;
    }

    switch (type) {
      case 'tenant':
        return ['/tenants', id];
      case 'lease':
        return ['/leases'];
      default:
        // Un bien vit sous son portefeuille, dont l'identifiant n'est pas
        // transporté ici : la liste des portefeuilles reste le bon point
        // d'entrée, plutôt qu'un lien qui tomberait à côté.
        return ['/portfolios'];
    }
  }

  protected download(item: AppDocument): void {
    this.service.download(item).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = item.original_name;
        link.click();

        URL.revokeObjectURL(url);
      },
      error: (error) => this.notifications.fromHttp(error, 'Le téléchargement a échoué.'),
    });
  }

  protected async remove(item: AppDocument): Promise<void> {
    const confirmed = await this.confirm.ask({
      title: 'Supprimer ce document ?',
      message: `« ${item.name} » sera retiré du coffre. La suppression est réversible : le fichier reste conservé.`,
      confirmLabel: 'Supprimer',
      danger: true,
    });

    if (!confirmed) {
      return;
    }

    this.service.remove(item.id).subscribe({
      next: () => {
        this.notifications.success('Document supprimé.');
        this.list.refresh();
      },
      error: (error) => this.notifications.fromHttp(error, 'La suppression a échoué.'),
    });
  }
}
