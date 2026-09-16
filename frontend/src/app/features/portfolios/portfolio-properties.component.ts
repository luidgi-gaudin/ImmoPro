import { Component, computed, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { EMPTY, catchError, combineLatest, filter, switchMap, tap } from 'rxjs';
import {
  ImmoproButtonComponent,
  ImmoproInputComponent,
  ImmoproTableComponent,
  ImmoproAvatarComponent,
  ImmoproIconButtonComponent,
  ImmoproBadgeComponent,
  ImmoproDpeBadgeComponent,
  ImmoproSelectComponent,
  ImmoproFilterBarComponent,
  ImmoproPaginationComponent,
  ImmoproSkeletonComponent,
} from 'ui-lib';
import { FilterChip } from 'ui-lib';
import {
  CreatePropertyPayload,
  PortfolioService,
  Property,
} from '../../core/services/portfolio.service';
import { PaginatedResponse } from '../../core/list/pagination.model';
import { createListQuery } from '../../core/list/list-query';
import { ConfirmService } from '../../core/services/confirm.service';
import { NotificationService } from '../../core/services/notification.service';
import { PortfolioContextService } from './portfolio-context.service';

@Component({
  selector: 'app-portfolio-properties',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    ImmoproButtonComponent,
    ImmoproInputComponent,
    ImmoproTableComponent,
    ImmoproAvatarComponent,
    ImmoproIconButtonComponent,
    ImmoproBadgeComponent,
    ImmoproDpeBadgeComponent,
    ImmoproSelectComponent,
    ImmoproFilterBarComponent,
    ImmoproPaginationComponent,
    ImmoproSkeletonComponent,
  ],
  templateUrl: './portfolio-properties.component.html',
  styleUrl: './portfolio-properties.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PortfolioPropertiesComponent {
  private fb = inject(FormBuilder);
  private confirm = inject(ConfirmService);
  private notifications = inject(NotificationService);
  private portfolioService = inject(PortfolioService);
  protected ctx = inject(PortfolioContextService);

  protected readonly list = createListQuery({
    defaultSort: 'title',
    defaultDirection: 'asc',
    filterKeys: ['property_type', 'dpe', 'loue'],
  });

  /**
   * Liste affichée : paginée et filtrée par le serveur, indépendamment du
   * contexte du portefeuille — celui-ci garde l'ensemble des biens pour ses
   * statistiques et la navigation vers une fiche.
   */
  properties = signal<Property[]>([]);
  pagination = signal<PaginatedResponse<Property> | undefined>(undefined);
  listLoading = signal(false);

  /** Passe à vrai dès la première réponse reçue, même vide. */
  private readonly firstLoadDone = signal(false);

  /**
   * Squelette réservé au tout premier affichage : sur un changement de filtre,
   * la liste en place pâlit au lieu d'être remplacée par des blocs gris, ce qui
   * évite un clignotement à chaque frappe.
   */
  protected readonly showSkeleton = computed(() => !this.firstLoadDone() && this.listLoading());

  /** Lignes fantômes affichées pendant le premier chargement. */
  protected readonly skeletonRows = Array.from({ length: 8 });

  listError = signal<string | null>(null);

  propertyTypes = [
    { label: 'Appartement', value: 'appartement' },
    { label: 'Maison', value: 'maison' },
    { label: 'Terrain', value: 'terrain' },
  ];
  dpeValues = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
  rentedOptions = [
    { value: '1', label: 'Loué' },
    { value: '0', label: 'Disponible' },
  ];

  readonly activeChips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const filters = this.list.filters();
    if (filters['property_type']) {
      const typeLabel =
        this.propertyTypes.find((t) => t.value === filters['property_type'])?.label ??
        filters['property_type'];
      chips.push({ key: 'property_type', label: 'Type', value: typeLabel });
    }
    if (filters['dpe']) {
      chips.push({ key: 'dpe', label: 'DPE', value: filters['dpe'] });
    }
    if (filters['loue']) {
      chips.push({
        key: 'loue',
        label: 'Occupation',
        value: filters['loue'] === '1' ? 'Loué' : 'Disponible',
      });
    }
    return chips;
  });
  propertyForm: FormGroup;

  createModalOpen = signal(false);
  editingProperty = signal<Property | null>(null);
  saving = signal(false);
  submitted = signal(false);

  constructor() {
    // Le portefeuille est résolu de façon asynchrone par le shell : on écoute
    // les deux sources, sans quoi le premier déclenchement partirait avec un
    // identifiant nul et plus rien ne relancerait la requête ensuite.
    combineLatest([toObservable(this.ctx.portfolioId), toObservable(this.list.trigger)])
      .pipe(
        filter(([portfolioId]) => portfolioId !== null),
        tap(() => this.listLoading.set(true)),
        switchMap(([portfolioId, { params }]) =>
          this.portfolioService.getPortfolioProperties(portfolioId!, params).pipe(
            catchError(() => {
              this.listError.set('Impossible de charger la liste des actifs');
              this.listLoading.set(false);
              return EMPTY;
            }),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((response) => {
        this.properties.set(response.data);
        this.pagination.set(response);
        this.listLoading.set(false);
        this.firstLoadDone.set(true);
        this.listError.set(null);

        // Le portefeuille et ses compteurs voyagent avec ses biens : le bandeau
        // parent n'a donc rien à demander de son côté. C'est ce qui fait tenir
        // cet écran en un seul appel HTTP au lieu de trois.
        this.ctx.adopt(response.portfolio);
      });

    this.propertyForm = this.fb.group({
      title: ['', [Validators.required, Validators.minLength(2)]],
      property_type: ['', [Validators.required]],
      address: ['', [Validators.required]],
      city: ['', [Validators.required]],
      postal_code: ['', [Validators.required]],
      dpe: ['', [Validators.required]],
      rooms: [null],
      area_sqm: [null],
      has_balcony: [false],
      has_garden: [false],
      has_parking: [false],
      has_cave: [false],
      is_rented: [false],
      monthly_rent: [null],
      description: [''],
    });
  }

  addProperty() {
    this.openPropertyModal();
  }

  editProperty(property: Property) {
    this.openPropertyModal(property);
  }

  async deleteProperty(property: Property) {
    const confirmed = await this.confirm.ask({
      title: `Supprimer « ${property.title} » ?`,
      message: property.is_rented
        ? 'Ce bien est actuellement loué. Le supprimer emporte son bail en cours, ses échéances et ses quittances.'
        : "Ce bien sera retiré du portefeuille, avec l'historique des baux qui s'y rattachent.",
      confirmLabel: 'Supprimer le bien',
      danger: true,
    });

    if (!confirmed) {
      return;
    }

    // Retrait immédiat de la page affichée, puis rechargement : la ligne
    // disparaît sans attendre l'aller-retour, et la page se recomplète ensuite
    // avec l'élément suivant.
    const previous = this.properties();
    this.properties.update((list) => list.filter((candidate) => candidate.id !== property.id));

    this.ctx.deleteProperty(property).subscribe({
      next: () => {
        this.ctx.deletingId.set(null);
        this.list.refresh();
        this.notifications.success(`« ${property.title} » a été supprimé.`);
      },
      error: (error: unknown) => {
        this.ctx.deletingId.set(null);
        this.properties.set(previous);
        this.notifications.fromHttp(error, 'La suppression du bien a échoué.');
      },
    });
  }

  openPropertyModal(property: Property | null = null) {
    this.ctx.error.set(null);
    this.submitted.set(false);
    this.editingProperty.set(property);

    this.propertyForm.reset({
      title: property?.title ?? '',
      property_type: property?.property_type ?? '',
      address: property?.address ?? '',
      city: property?.city ?? '',
      postal_code: property?.postal_code ?? '',
      dpe: property?.dpe ?? '',
      rooms: property?.rooms ?? null,
      area_sqm: property?.area_sqm ?? null,
      has_balcony: property?.has_balcony ?? false,
      has_garden: property?.has_garden ?? false,
      has_parking: property?.has_parking ?? false,
      has_cave: property?.has_cave ?? false,
      is_rented: property?.is_rented ?? false,
      monthly_rent: property?.monthly_rent ?? null,
      description: property?.description ?? '',
    });

    this.createModalOpen.set(true);
  }

  closePropertyModal() {
    if (this.saving()) return;
    this.createModalOpen.set(false);
    this.editingProperty.set(null);
  }

  submitProperty() {
    this.submitted.set(true);
    this.ctx.error.set(null);

    if (this.propertyForm.invalid) return;

    this.saving.set(true);
    const payload = this.propertyForm.value as CreatePropertyPayload;
    const isEdit = !!this.editingProperty();
    const previousProperties = this.properties();

    if (isEdit) {
      const editId = this.editingProperty()!.id;
      const updatedProp: Property = {
        ...this.editingProperty()!,
        ...payload,
        has_balcony: payload.has_balcony || false,
        has_garden: payload.has_garden || false,
        has_parking: payload.has_parking || false,
        has_cave: payload.has_cave || false,
        is_rented: payload.is_rented || false,
        rooms: payload.rooms || null,
        area_sqm: payload.area_sqm || null,
        monthly_rent: payload.monthly_rent || null,
        description: payload.description || null,
      };

      this.properties.set(
        previousProperties.map((candidate) => (candidate.id === editId ? updatedProp : candidate)),
      );
      this.createModalOpen.set(false);

      this.ctx.updateProperty(editId, payload).subscribe({
        next: (savedProp) => {
          this.saving.set(false);
          this.properties.update((list) =>
            list.map((candidate) => (candidate.id === editId ? savedProp : candidate)),
          );
          this.editingProperty.set(null);
          this.list.refresh();
          this.notifications.success('Bien mis à jour.');
        },
        error: (err) => {
          this.saving.set(false);
          this.createModalOpen.set(true);
          this.properties.set(previousProperties);
          this.ctx.error.set(err.error?.message || 'Impossible de sauvegarder cet actif');
        },
      });
    } else {
      const tempId = -Date.now();
      const tempProp: Property = {
        id: tempId,
        ...payload,
        has_balcony: payload.has_balcony || false,
        has_garden: payload.has_garden || false,
        has_parking: payload.has_parking || false,
        has_cave: payload.has_cave || false,
        is_rented: payload.is_rented || false,
        rooms: payload.rooms || null,
        area_sqm: payload.area_sqm || null,
        monthly_rent: payload.monthly_rent || null,
        description: payload.description || null,
      };

      this.properties.set([tempProp, ...previousProperties]);
      this.createModalOpen.set(false);

      this.ctx.createProperty(payload).subscribe({
        next: (savedProp) => {
          this.saving.set(false);
          this.properties.update((list) =>
            list.map((candidate) => (candidate.id === tempId ? savedProp : candidate)),
          );
          this.propertyForm.reset();
          this.submitted.set(false);
          this.list.refresh();
          this.notifications.success(`« ${savedProp.title} » a été ajouté au portefeuille.`);
        },
        error: (err) => {
          this.saving.set(false);
          this.createModalOpen.set(true);
          this.properties.set(previousProperties);
          this.ctx.error.set(err.error?.message || 'Impossible de sauvegarder cet actif');
        },
      });
    }
  }

  get title() {
    return this.propertyForm.get('title');
  }
  get propertyType() {
    return this.propertyForm.get('property_type');
  }
  get address() {
    return this.propertyForm.get('address');
  }
  get city() {
    return this.propertyForm.get('city');
  }
  get postalCode() {
    return this.propertyForm.get('postal_code');
  }
  get dpe() {
    return this.propertyForm.get('dpe');
  }

  get modalTitle() {
    return this.editingProperty() ? 'Modifier un actif' : 'Créer un actif';
  }

  get submitLabel() {
    return this.editingProperty() ? 'Enregistrer' : "Créer l'actif";
  }
}
