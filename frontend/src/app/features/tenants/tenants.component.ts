import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { TenantService, Tenant, PaginatedResponse } from '../../core/services/tenant.service';
import { ImmoproButtonComponent, ImmoproInputComponent } from 'ui-lib';

@Component({
  selector: 'app-tenants',
  standalone: true,
  imports: [ReactiveFormsModule, ImmoproButtonComponent, ImmoproInputComponent],
  templateUrl: './tenants.component.html',
  styleUrl: './tenants.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantsComponent implements OnInit {
  private fb = inject(FormBuilder);
  private tenantService = inject(TenantService);

  tenants = signal<Tenant[]>([]);
  pagination = signal<PaginatedResponse<Tenant> | undefined>(undefined);
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
  }

  ngOnInit() {
    this.loadTenants();
  }

  loadTenants(page: number = 1) {
    this.tenantService.getTenants(page).subscribe({
      next: (response) => {
        this.tenants.set(response.data);
        this.pagination.set(response);
      },
      error: () => {
        this.error.set('Impossible de charger les locataires');
      }
    });
  }

  loadTenantsBackground(page: number = 1) {
    // Silent update to refresh metadata/list in background
    this.tenantService.getTenants(page).subscribe({
      next: (response) => {
        this.tenants.set(response.data);
        this.pagination.set(response);
      }
    });
  }

  changePage(page: number) {
    this.loadTenants(page);
  }

  addTenant() {
    this.openTenantModal();
  }

  editTenant(tenant: Tenant) {
    this.openTenantModal(tenant);
  }

  deleteTenant(tenant: Tenant) {
    const confirmed = window.confirm(`Supprimer le locataire ${tenant.first_name} ${tenant.last_name} ?`);
    if (!confirmed) {
      return;
    }

    const previousTenants = this.tenants();
    
    // Optimistic UI Deletion
    this.tenants.set(previousTenants.filter(t => t.id !== tenant.id));
    this.deletingId.set(tenant.id);
    this.error.set(null);

    this.tenantService.deleteTenant(tenant.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        // Sync layout data quietly
        this.loadTenantsBackground(this.pagination()?.current_page ?? 1);
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
      this.tenants.set(previousTenants.map(t => t.id === editId ? updatedTenant : t));
      this.createModalOpen.set(false);

      this.tenantService.updateTenant(editId, payload).subscribe({
        next: (savedTenant) => {
          this.loading.set(false);
          this.tenants.set(this.tenants().map(t => t.id === editId ? savedTenant : t));
          this.editingTenant.set(null);
          this.loadTenantsBackground(this.pagination()?.current_page ?? 1);
        },
        error: (err) => {
          this.loading.set(false);
          this.createModalOpen.set(true); // reopen
          this.tenants.set(previousTenants); // rollback
          this.error.set(err.error?.message || 'Erreur lors de la modification du locataire');
        }
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
        address: payload.address || '' 
      };
      
      this.tenants.set([tempTenant, ...previousTenants]);
      this.createModalOpen.set(false);

      this.tenantService.createTenant(payload).subscribe({
        next: (savedTenant) => {
          this.loading.set(false);
          this.tenants.set(this.tenants().map(t => t.id === tempId ? savedTenant : t));
          this.createForm.reset();
          this.submitted.set(false);
          this.loadTenantsBackground(this.pagination()?.current_page ?? 1);
        },
        error: (err) => {
          this.loading.set(false);
          this.createModalOpen.set(true); // reopen
          this.tenants.set(previousTenants); // rollback
          this.error.set(err.error?.message || 'Erreur lors de la création du locataire');
        }
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
