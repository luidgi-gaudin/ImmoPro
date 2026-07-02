import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { TenantService, Tenant, PaginatedResponse } from '../../services/tenant.service';
import { ImmoproButtonComponent, ImmoproInputComponent } from 'ui-lib';

@Component({
  selector: 'app-tenants',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ImmoproButtonComponent, ImmoproInputComponent],
  templateUrl: './tenants.component.html',
  styleUrl: './tenants.component.scss'
})
export class TenantsComponent implements OnInit {
  private fb = inject(FormBuilder);
  private tenantService = inject(TenantService);
  tenants = signal<Tenant[]>([]);
  pagination = signal<PaginatedResponse<Tenant> | undefined>(undefined);
  createForm: FormGroup;
  createModalOpen = false;
  editingTenant: Tenant | null = null;
  loading = false;
  deletingId: number | null = null;
  error: string | null = null;
  submitted = false;

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
    this.tenantService.getTenants(page).subscribe(response => {
      this.tenants.set(response.data);
      this.pagination.set(response);
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
    const confirmed = window.confirm(`Supprimer ${tenant.first_name} ${tenant.last_name} ?`);

    if (!confirmed) {
      return;
    }

    this.deletingId = tenant.id;

    this.tenantService.deleteTenant(tenant.id).subscribe({
      next: () => {
        this.deletingId = null;
        this.loadTenants(this.pagination()?.current_page ?? 1);
      },
      error: () => {
        this.deletingId = null;
        this.error = 'Erreur lors de la suppression du locataire';
      },
    });
  }

  openTenantModal(tenant: Tenant | null = null) {
    this.error = null;
    this.submitted = false;
    this.editingTenant = tenant;

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

    this.createModalOpen = true;
  }

  closeCreateModal() {
    if (this.loading) {
      return;
    }

    this.createModalOpen = false;
  }

  submitTenant() {
    this.submitted = true;
    this.error = null;

    if (this.createForm.invalid) {
      return;
    }

    this.loading = true;

    const payload = this.createForm.value;
    const request = this.editingTenant
      ? this.tenantService.updateTenant(this.editingTenant.id, payload)
      : this.tenantService.createTenant(payload);

    request.subscribe({
      next: () => {
        this.loading = false;
        this.createModalOpen = false;
        this.editingTenant = null;
        this.createForm.reset({
          first_name: '',
          last_name: '',
          email: '',
          phone: '',
          iban: '',
          bic: '',
          country: '',
          address: '',
        });
        this.loadTenants(this.pagination()?.current_page ?? 1);
      },
      error: (response) => {
        this.loading = false;
        if (response.error?.errors) {
          this.error = Object.values(response.error.errors).flat().join(', ');
        } else {
          this.error = response.error?.message || 'Erreur lors de la création du locataire';
        }
      },
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
    return this.editingTenant ? 'Modifier le locataire' : 'Nouveau locataire';
  }

  get submitLabel() {
    return this.editingTenant ? 'Enregistrer les changements' : 'Créer le locataire';
  }
}
