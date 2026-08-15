import { Component, computed, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { EMPTY, catchError, switchMap, tap } from 'rxjs';
import { TenantService, Tenant } from '../../core/services/tenant.service';
import { PaginatedResponse } from '../../core/list/pagination.model';
import { createListQuery } from '../../core/list/list-query';
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
  private fb = inject(FormBuilder);
  private tenantService = inject(TenantService);

  /** Page, recherche et tri, mémorisés dans l'URL. */
  protected readonly list = createListQuery({
    defaultSort: 'last_name',
    defaultDirection: 'asc',
    filterKeys: ['country', 'loue'],
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

  deleteTenant(tenant: Tenant) {
    const confirmed = window.confirm(
      `Supprimer le locataire ${tenant.first_name} ${tenant.last_name} ?`,
    );
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
        // Sync layout data quietly
        this.list.refresh();
      },
      error: () => {
        this.deletingId.set(null);
        // Rollback on error
        this.tenants.set(previousTenants);
        this.error.set('Erreur lors de la suppression du locataire');
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
