import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { ImmoproButtonComponent, ImmoproInputComponent } from 'ui-lib';
import { CreateLeasePayload, Lease, LeaseService } from '../../services/lease.service';
import { PortfolioService } from '../../services/portfolio.service';
import { TenantService } from '../../services/tenant.service';

interface Option {
  id: number;
  label: string;
}

@Component({
  selector: 'app-leases',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ImmoproButtonComponent, ImmoproInputComponent],
  templateUrl: './leases.component.html',
  styleUrl: './leases.component.scss',
})
export class LeasesComponent implements OnInit {
  private fb = inject(FormBuilder);
  private leaseService = inject(LeaseService);
  private tenantService = inject(TenantService);
  private portfolioService = inject(PortfolioService);

  leases = signal<Lease[]>([]);
  properties = signal<Option[]>([]);
  tenants = signal<Option[]>([]);
  leaseForm: FormGroup;
  createModalOpen = false;
  editingLease: Lease | null = null;
  saving = false;
  deletingId: number | null = null;
  loading = false;
  error: string | null = null;
  submitted = false;
  statusOptions = [
    { value: 'actif', label: 'Actif' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'termine', label: 'Terminé' },
  ];

  constructor() {
    this.leaseForm = this.fb.group({
      property_id: ['', [Validators.required]],
      tenant_id: ['', [Validators.required]],
      start_date: ['', [Validators.required]],
      end_date: [''],
      monthly_rent: [null, [Validators.required]],
      deposit: [null],
      statut: ['actif', [Validators.required]],
    });
  }

  ngOnInit() {
    this.loadSupportData();
    this.loadLeases();
  }

  loadLeases() {
    this.loading = true;
    this.error = null;

    this.leaseService.getLeases().subscribe({
      next: (leases) => {
        this.leases.set(leases);
        this.loading = false;
      },
      error: () => {
        this.error = 'Erreur lors du chargement des baux';
        this.loading = false;
      },
    });
  }

  loadSupportData() {
    forkJoin({
      portfolios: this.portfolioService.getPortfolios(),
      tenants: this.tenantService.getTenants(1),
    }).subscribe({
      next: ({ portfolios, tenants }) => {
        const propertyRequests = portfolios.map((portfolio) => this.portfolioService.getPortfolioProperties(portfolio.id));

        if (!propertyRequests.length) {
          this.properties.set([]);
          this.tenants.set(
            tenants.data.map((tenant) => ({
              id: tenant.id,
              label: `${tenant.first_name} ${tenant.last_name}`,
            }))
          );
          return;
        }

        forkJoin(propertyRequests).subscribe({
          next: (propertiesByPortfolio) => {
            const propertyOptions = propertiesByPortfolio
              .flat()
              .map((property) => ({ id: property.id, label: `${property.title} - ${property.city}` }));

            this.properties.set(propertyOptions);
            this.tenants.set(
              tenants.data.map((tenant) => ({
                id: tenant.id,
                label: `${tenant.first_name} ${tenant.last_name}`,
              }))
            );
          },
          error: () => {
            this.error = 'Impossible de charger la liste des biens';
          },
        });
      },
      error: () => {
        this.error = 'Impossible de charger les données de formulaire';
      },
    });
  }

  addLease() {
    this.openLeaseModal();
  }

  editLease(lease: Lease) {
    this.openLeaseModal(lease);
  }

  deleteLease(lease: Lease) {
    const confirmed = window.confirm(`Supprimer le bail #${lease.id} ?`);

    if (!confirmed) {
      return;
    }

    this.deletingId = lease.id;
    this.leaseService.deleteLease(lease.id).subscribe({
      next: () => {
        this.deletingId = null;
        this.loadLeases();
      },
      error: () => {
        this.deletingId = null;
        this.error = 'Erreur lors de la suppression du bail';
      },
    });
  }

  openLeaseModal(lease: Lease | null = null) {
    this.error = null;
    this.submitted = false;
    this.editingLease = lease;

    this.leaseForm.reset({
      property_id: lease?.property_id?.toString() ?? '',
      tenant_id: lease?.tenant_id?.toString() ?? '',
      start_date: lease?.start_date ?? '',
      end_date: lease?.end_date ?? '',
      monthly_rent: lease?.monthly_rent ?? null,
      deposit: lease?.deposit ?? null,
      statut: lease?.statut ?? 'actif',
    });

    this.createModalOpen = true;
  }

  closeCreateModal() {
    if (this.saving) {
      return;
    }

    this.createModalOpen = false;
    this.editingLease = null;
  }

  submitLease() {
    this.submitted = true;
    this.error = null;

    if (this.leaseForm.invalid) {
      return;
    }

    this.saving = true;
    const formValue = this.leaseForm.value;
    const payload: CreateLeasePayload = {
      property_id: Number(formValue.property_id),
      tenant_id: Number(formValue.tenant_id),
      start_date: formValue.start_date,
      end_date: formValue.end_date || null,
      monthly_rent: Number(formValue.monthly_rent),
      deposit: formValue.deposit !== null && formValue.deposit !== '' ? Number(formValue.deposit) : null,
      statut: formValue.statut,
    };

    const request = this.editingLease
      ? this.leaseService.updateLease(this.editingLease.id, payload)
      : this.leaseService.createLease(payload);

    request.subscribe({
      next: () => {
        this.saving = false;
        this.createModalOpen = false;
        this.editingLease = null;
        this.loadLeases();
      },
      error: (response) => {
        this.saving = false;
        if (response.error?.errors) {
          this.error = Object.values(response.error.errors).flat().join(', ');
        } else {
          this.error = response.error?.message || 'Erreur lors de la sauvegarde du bail';
        }
      },
    });
  }

  resolvePropertyLabel(propertyId: number): string {
    return this.properties().find((property) => property.id === propertyId)?.label || `Bien #${propertyId}`;
  }

  resolveTenantLabel(tenantId: number): string {
    return this.tenants().find((tenant) => tenant.id === tenantId)?.label || `Locataire #${tenantId}`;
  }

  get propertyId() {
    return this.leaseForm.get('property_id');
  }

  get tenantId() {
    return this.leaseForm.get('tenant_id');
  }

  get startDate() {
    return this.leaseForm.get('start_date');
  }

  get monthlyRent() {
    return this.leaseForm.get('monthly_rent');
  }

  get statut() {
    return this.leaseForm.get('statut');
  }

  get modalTitle() {
    return this.editingLease ? 'Modifier le bail' : 'Nouveau bail';
  }

  get submitLabel() {
    return this.editingLease ? 'Enregistrer les changements' : 'Créer le bail';
  }
}
