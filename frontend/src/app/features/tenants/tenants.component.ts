import { Component, computed, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { EMPTY, catchError, switchMap, tap } from 'rxjs';
import { TenantService, Tenant } from '../../core/services/tenant.service';
import { PaginatedResponse } from '../../core/list/pagination.model';
import { createListQuery } from '../../core/list/list-query';
import { ConfirmService } from '../../core/services/confirm.service';
import { maskBankIdentifier } from '../../core/format/bank-identifier';
import { NotificationService } from '../../core/services/notification.service';
import {
  ImmoproButtonComponent,
  ImmoproInputComponent,
  ImmoproPageHeaderComponent,
  ImmoproTableComponent,
  ImmoproAvatarComponent,
  ImmoproIconButtonComponent,
  ImmoproPaginationComponent,
  ImmoproSkeletonComponent,
  ImmoproFilterBarComponent,
  ImmoproSelectComponent,
  FilterChip,
} from 'ui-lib';

@Component({
  selector: 'app-tenants',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    ImmoproButtonComponent,
    ImmoproInputComponent,
    ImmoproPageHeaderComponent,
    ImmoproTableComponent,
    ImmoproAvatarComponent,
    ImmoproIconButtonComponent,
    ImmoproPaginationComponent,
    ImmoproSkeletonComponent,
    ImmoproFilterBarComponent,
    ImmoproSelectComponent,
  ],
  templateUrl: './tenants.component.html',
  styleUrl: './tenants.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantsComponent {
  /** IBAN et BIC ne s'affichent qu'en partie dans la liste. */
  protected mask(value: string | null | undefined): string {
    return maskBankIdentifier(value);
  }

  private fb = inject(FormBuilder);
  private tenantService = inject(TenantService);
  private confirm = inject(ConfirmService);
  private notifications = inject(NotificationService);

  /** Page, recherche et tri, mémorisés dans l'URL. */
  protected readonly list = createListQuery({
    defaultSort: 'last_name',
    defaultDirection: 'asc',
    filterKeys: ['country', 'loue', 'avec_garant', 'archives'],
  });

  readonly activeChips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const filters = this.list.filters();
    if (filters['country']) {
      chips.push({ key: 'country', label: 'Pays', value: filters['country'] });
    }
    if (filters['loue']) {
      chips.push({
        key: 'loue',
        label: 'Bail actif',
        value: filters['loue'] === '1' ? 'Sous contrat' : 'Sans contrat',
      });
    }
    if (filters['avec_garant']) {
      chips.push({
        key: 'avec_garant',
        label: 'Garant',
        value: filters['avec_garant'] === '1' ? 'Avec garant' : 'Sans garant',
      });
    }
    if (filters['archives']) {
      chips.push({
        key: 'archives',
        label: 'Archives',
        value: filters['archives'] === 'seuls' ? 'Dossiers clos' : 'Clos inclus',
      });
    }
    return chips;
  });

  tenants = signal<Tenant[]>([]);
  pagination = signal<PaginatedResponse<Tenant> | undefined>(undefined);
  listLoading = signal(false);

  /** Passe à vrai dès la première réponse reçue, même vide. */
  private readonly firstLoadDone = signal(false);

  /**
   * Squelette réservé au tout premier affichage : sur un changement de filtre,
   * la liste en place pâlit au lieu d'être remplacée par des blocs gris, ce qui
   * évite un clignotement à chaque frappe.
   */
  protected readonly showSkeleton = computed(() => !this.firstLoadDone() && this.listLoading());

  /** Lignes fantômes, calées sur la taille de page courante. */
  protected readonly skeletonRows = Array.from({ length: 8 });
  createForm: FormGroup;

  /** Natures de pièce d'identité acceptées au dossier. */
  readonly identityDocumentTypes = [
    { value: 'carte_identite', label: "Carte nationale d'identité" },
    { value: 'passeport', label: 'Passeport' },
    { value: 'titre_sejour', label: 'Titre de séjour' },
    { value: 'permis_conduire', label: 'Permis de conduire' },
    { value: 'autre', label: 'Autre pièce' },
  ];

  readonly archiveOptions = [
    { value: '', label: 'Dossiers actifs' },
    { value: 'inclus', label: 'Actifs et clos' },
    { value: 'seuls', label: 'Dossiers clos' },
  ];

  createModalOpen = signal(false);
  editingTenant = signal<Tenant | null>(null);
  loading = signal(false);
  deletingId = signal<number | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);

  constructor() {
    this.createForm = this.fb.group({
      first_name: ['', [Validators.required, Validators.minLength(2)]],
      last_name: ['', [Validators.required, Validators.minLength(2)]],
      birth_date: [''],
      birth_place: [''],
      identity_document_type: [''],
      identity_document_number: [''],
      email: ['', [Validators.email]],
      phone: [''],
      iban: [''],
      bic: [''],
      country: [''],
      address: [''],
    });

    // Un seul point de chargement, déclenché par tout changement de critère.
    // switchMap annule la requête précédente : en tapant dans la recherche, seul
    // le dernier appel compte, et une réponse lente ne peut plus écraser une
    // réponse plus récente.
    toObservable(this.list.trigger)
      .pipe(
        tap(() => this.listLoading.set(true)),
        switchMap(({ params }) =>
          this.tenantService.getTenants(params).pipe(
            catchError(() => {
              this.error.set('Impossible de charger les locataires');
              this.listLoading.set(false);
              return EMPTY;
            }),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((response) => {
        this.tenants.set(response.data);
        this.pagination.set(response);
        this.listLoading.set(false);
        this.firstLoadDone.set(true);
        this.error.set(null);
      });
  }

  addTenant() {
    this.openTenantModal();
  }

  editTenant(tenant: Tenant) {
    this.openTenantModal(tenant);
  }

  async deleteTenant(tenant: Tenant) {
    const confirmed = await this.confirm.ask({
      title: `Supprimer ${tenant.first_name} ${tenant.last_name} ?`,
      message:
        "Le dossier locataire sera retiré de l'annuaire. Ses baux et ses quittances restent " +
        'consultables : la loi impose de pouvoir justifier des loyers perçus.',
      confirmLabel: 'Supprimer le dossier',
      danger: true,
    });

    if (!confirmed) {
      return;
    }

    const previousTenants = this.tenants();

    // Optimistic UI Deletion
    this.tenants.set(previousTenants.filter((t) => t.id !== tenant.id));
    this.deletingId.set(tenant.id);
    this.error.set(null);

    this.tenantService.deleteTenant(tenant.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.list.refresh();
        this.notifications.success('Dossier locataire supprimé.');
      },
      error: (error: unknown) => {
        this.deletingId.set(null);
        // Rollback on error
        this.tenants.set(previousTenants);
        this.notifications.fromHttp(error, 'La suppression du locataire a échoué.');
      },
    });
  }

  openTenantModal(tenant: Tenant | null = null) {
    this.error.set(null);
    this.submitted.set(false);
    this.editingTenant.set(tenant);

    this.createForm.reset({
      first_name: tenant?.first_name ?? '',
      last_name: tenant?.last_name ?? '',
      birth_date: tenant?.birth_date ?? '',
      birth_place: tenant?.birth_place ?? '',
      identity_document_type: tenant?.identity_document_type ?? '',
      identity_document_number: tenant?.identity_document_number ?? '',
      email: tenant?.email ?? '',
      phone: tenant?.phone ?? '',
      iban: tenant?.iban ?? '',
      bic: tenant?.bic ?? '',
      country: tenant?.country ?? '',
      address: tenant?.address ?? '',
    });

    this.createModalOpen.set(true);
  }

  closeCreateModal() {
    if (this.loading()) {
      return;
    }
    this.createModalOpen.set(false);
  }

  submitTenant() {
    this.submitted.set(true);
    this.error.set(null);

    if (this.createForm.invalid) {
      return;
    }

    this.loading.set(true);
    const payload = this.createForm.value;
    const isEdit = !!this.editingTenant();
    const previousTenants = this.tenants();

    if (isEdit) {
      const editId = this.editingTenant()!.id;
      const updatedTenant: Tenant = { ...this.editingTenant()!, ...payload };

      // Optimistic UI Update
      this.tenants.set(previousTenants.map((t) => (t.id === editId ? updatedTenant : t)));
      this.createModalOpen.set(false);

      this.tenantService.updateTenant(editId, payload).subscribe({
        next: (savedTenant) => {
          this.loading.set(false);
          this.tenants.set(this.tenants().map((t) => (t.id === editId ? savedTenant : t)));
          this.editingTenant.set(null);
          this.list.refresh();
        },
        error: (err) => {
          this.loading.set(false);
          this.createModalOpen.set(true); // reopen
          this.tenants.set(previousTenants); // rollback
          this.error.set(err.error?.message || 'Erreur lors de la modification du locataire');
        },
      });
    } else {
      // Optimistic UI Creation with temporary ID
      const tempId = -Date.now();
      const tempTenant: Tenant = {
        id: tempId,
        ...payload,
        phone: payload.phone || '',
        iban: payload.iban || '',
        bic: payload.bic || '',
        country: payload.country || '',
        address: payload.address || '',
      };

      this.tenants.set([tempTenant, ...previousTenants]);
      this.createModalOpen.set(false);

      this.tenantService.createTenant(payload).subscribe({
        next: (savedTenant) => {
          this.loading.set(false);
          this.tenants.set(this.tenants().map((t) => (t.id === tempId ? savedTenant : t)));
          this.createForm.reset();
          this.submitted.set(false);
          this.list.refresh();
        },
        error: (err) => {
          this.loading.set(false);
          this.createModalOpen.set(true); // reopen
          this.tenants.set(previousTenants); // rollback
          this.error.set(err.error?.message || 'Erreur lors de la création du locataire');
        },
      });
    }
  }

  /* ----------------------------------------------------------------------
   | Archivage et invitation
   |----------------------------------------------------------------------*/

  /**
   * Clôt un dossier sans l'effacer.
   *
   * Distinct de la suppression, qui répond à « créé par erreur ». Ici le
   * dossier a servi : la prescription des loyers court sur trois ans, et
   * l'ancien locataire peut réclamer son dépôt de garantie bien après.
   */
  async archiveTenant(tenant: Tenant): Promise<void> {
    const confirmed = await this.confirm.ask({
      title: `Archiver le dossier de ${tenant.first_name} ${tenant.last_name} ?`,
      message:
        'Le dossier sortira de la liste de travail sans être supprimé. Vous le retrouverez ' +
        'par le filtre « Dossiers clos ».',
      confirmLabel: 'Archiver',
    });

    if (!confirmed) {
      return;
    }

    this.tenantService.archiveTenant(tenant.id).subscribe({
      next: () => {
        this.list.refresh();
        this.notifications.success('Dossier archivé.');
      },
      error: (error: unknown) => this.notifications.fromHttp(error, "L'archivage a échoué."),
    });
  }

  unarchiveTenant(tenant: Tenant): void {
    this.tenantService.unarchiveTenant(tenant.id).subscribe({
      next: () => {
        this.list.refresh();
        this.notifications.success('Dossier réactivé.');
      },
      error: (error: unknown) => this.notifications.fromHttp(error, 'La réactivation a échoué.'),
    });
  }

  /** Invite le locataire à ouvrir son espace en ligne. */
  inviteTenant(tenant: Tenant): void {
    this.tenantService.inviteTenant(tenant.id).subscribe({
      next: (response) => {
        this.notifications.success(response.message);
        this.list.refresh();
      },
      error: (error: unknown) =>
        this.notifications.fromHttp(error, "L'invitation n'a pas pu être envoyée."),
    });
  }

  get firstName() {
    return this.createForm.get('first_name');
  }

  get lastName() {
    return this.createForm.get('last_name');
  }

  get email() {
    return this.createForm.get('email');
  }

  get modalTitle() {
    return this.editingTenant() ? 'Modifier le locataire' : 'Nouveau locataire';
  }

  get submitLabel() {
    return this.editingTenant() ? 'Enregistrer les changements' : 'Créer le locataire';
  }
}
