import { Component, OnInit, inject, signal, computed, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, FormArray, ReactiveFormsModule, Validators } from '@angular/forms';
import { CommonModule, DatePipe } from '@angular/common';
import { forkJoin } from 'rxjs';
import {
  ImmoproButtonComponent,
  ImmoproInputComponent,
  ImmoproCardComponent,
  ImmoproPageHeaderComponent,
  ImmoproTableComponent,
  ImmoproIconButtonComponent,
  ImmoproBadgeComponent,
  ImmoproEmptyStateComponent,
  ImmoproSelectComponent,
} from 'ui-lib';
import { CreateLeasePayload, Lease, LeasePhoto, LeaseService, RentPayment, QuittanceData } from '../../core/services/lease.service';
import { PortfolioService } from '../../core/services/portfolio.service';
import { TenantService } from '../../core/services/tenant.service';

interface Option {
  id: number;
  label: string;
}

@Component({
  selector: 'app-leases',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    ImmoproButtonComponent,
    ImmoproInputComponent,
    ImmoproCardComponent,
    ImmoproPageHeaderComponent,
    ImmoproTableComponent,
    ImmoproIconButtonComponent,
    ImmoproBadgeComponent,
    ImmoproEmptyStateComponent,
    ImmoproSelectComponent,
    DatePipe
  ],
  templateUrl: './leases.component.html',
  styleUrl: './leases.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LeasesComponent implements OnInit {
  private fb = inject(FormBuilder);
  private leaseService = inject(LeaseService);
  private tenantService = inject(TenantService);
  private portfolioService = inject(PortfolioService);

  leases = signal<Lease[]>([]);
  properties = signal<Option[]>([]);
  tenants = signal<Option[]>([]);
  
  // Forms
  leaseForm: FormGroup;
  revisionForm: FormGroup;
  terminationForm: FormGroup;
  paymentForm: FormGroup;

  // Selected lease and payments details as Signals
  selectedLease = signal<Lease | null>(null);
  payments = signal<RentPayment[]>([]);
  paymentsLoading = signal(false);
  photoUploading = signal<'entree' | 'sortie' | null>(null);

  // Modals controllers as Signals
  createModalOpen = signal(false);
  revisionModalOpen = signal(false);
  terminationModalOpen = signal(false);
  paymentModalOpen = signal(false);
  quittanceModalOpen = signal(false);

  // Modal payload and details
  editingLease = signal<Lease | null>(null);
  editingPayment = signal<RentPayment | null>(null);
  quittanceDetails = signal<QuittanceData | null>(null);

  // State flags
  loading = signal(false);
  saving = signal(false);
  deletingId = signal<number | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);
  paymentSubmitted = signal(false);

  leaseTypes = [
    { value: 'nu', label: 'Location vide (Nu)' },
    { value: 'meuble', label: 'Location meublée' },
    { value: 'etudiant', label: 'Bail étudiant (9 mois)' },
    { value: 'mobilite', label: 'Bail mobilité' }
  ];

  statusOptions = [
    { value: 'actif', label: 'Actif' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'termine', label: 'Terminé' },
  ];

  paymentMethods = ['Virement', 'Prélèvement', 'Chèque', 'Espèces'];
  inspectionTypes: ('entree' | 'sortie')[] = ['entree', 'sortie'];

  constructor() {
    this.leaseForm = this.fb.group({
      property_id: ['', [Validators.required]],
      tenant_id: ['', [Validators.required]],
      type: ['nu', [Validators.required]],
      start_date: ['', [Validators.required]],
      end_date: [''],
      monthly_rent: [null, [Validators.required, Validators.min(1)]],
      charges: [0, [Validators.required, Validators.min(0)]],
      deposit: [null, [Validators.min(0)]],
      payment_day: [1, [Validators.required, Validators.min(1), Validators.max(28)]],
      statut: ['actif', [Validators.required]],
      co_tenants: this.fb.array([]),
    });

    this.revisionForm = this.fb.group({
      irl_old: [null, [Validators.required, Validators.min(0.01)]],
      irl_new: [null, [Validators.required, Validators.min(0.01)]]
    });

    this.terminationForm = this.fb.group({
      end_date: ['', [Validators.required]]
    });

    this.paymentForm = this.fb.group({
      period: ['', [Validators.required]],
      amount_rent: [null, [Validators.min(0)]],
      amount_charges: [null, [Validators.min(0)]],
      paid_at: [''],
      payment_method: ['Virement']
    });

    // Handle lease type adjustments dynamically
    this.leaseForm.get('type')?.valueChanges.subscribe(type => {
      this.adjustFormForType(type);
    });
  }

  ngOnInit() {
    this.loadSupportData();
    this.loadLeases();
  }

  adjustFormForType(type: string) {
    const depositCtrl = this.leaseForm.get('deposit');
    const endDateCtrl = this.leaseForm.get('end_date');

    if (type === 'mobilite' || type === 'etudiant') {
      endDateCtrl?.setValidators([Validators.required]);
    } else {
      endDateCtrl?.clearValidators();
    }

    if (type === 'mobilite') {
      depositCtrl?.setValue(0);
      depositCtrl?.disable();
    } else {
      depositCtrl?.enable();
    }
    depositCtrl?.updateValueAndValidity();
    endDateCtrl?.updateValueAndValidity();
  }

  loadLeases() {
    this.loading.set(true);
    this.error.set(null);

    this.leaseService.getLeases().subscribe({
      next: (leases) => {
        this.leases.set(leases);
        this.loading.set(false);
        // Keep selection synchronized if any
        const selected = this.selectedLease();
        if (selected) {
          const updated = leases.find(l => l.id === selected.id);
          if (updated) {
            this.selectedLease.set(updated);
          }
        }
      },
      error: () => {
        this.error.set('Erreur lors du chargement des baux');
        this.loading.set(false);
      },
    });
  }

  loadLeasesBackground() {
    this.leaseService.getLeases().subscribe({
      next: (leases) => {
        this.leases.set(leases);
        const selected = this.selectedLease();
        if (selected) {
          const updated = leases.find(l => l.id === selected.id);
          if (updated) {
            this.selectedLease.set(updated);
          }
        }
      }
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
            this.error.set('Impossible de charger la liste des biens');
          },
        });
      },
      error: () => {
        this.error.set('Impossible de charger les données de formulaire');
      },
    });
  }

  selectLease(lease: Lease) {
    // Affiche immédiatement la version en liste, puis rafraîchit avec la version canonique du serveur.
    this.selectedLease.set(lease);
    this.loadPayments();

    this.leaseService.getLease(lease.id).subscribe({
      next: (fresh) => {
        if (this.selectedLease()?.id === fresh.id) {
          this.selectedLease.set(fresh);
        }
      },
    });
  }

  loadPayments() {
    const selected = this.selectedLease();
    if (!selected) return;
    this.paymentsLoading.set(true);
    this.leaseService.getPayments(selected.id).subscribe({
      next: (pays) => {
        this.payments.set(pays);
        this.paymentsLoading.set(false);
      },
      error: () => {
        this.error.set('Impossible de charger les échéances de loyer');
        this.paymentsLoading.set(false);
      }
    });
  }

  loadPaymentsBackground() {
    const selected = this.selectedLease();
    if (!selected) return;
    this.leaseService.getPayments(selected.id).subscribe({
      next: (pays) => {
        this.payments.set(pays);
      }
    });
  }

  // État des lieux (photos d'entrée / de sortie)
  uploadPhoto(type: 'entree' | 'sortie', event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const selected = this.selectedLease();
    if (!file || !selected) return;

    this.photoUploading.set(type);
    this.leaseService.uploadLeasePhoto(selected.id, type, file).subscribe({
      next: (photo) => {
        this.photoUploading.set(null);
        this.selectedLease.update((lease) => (lease ? { ...lease, photos: [...(lease.photos ?? []), photo] } : lease));
      },
      error: () => {
        this.photoUploading.set(null);
        this.error.set("Impossible de téléverser cette photo de l'état des lieux");
      },
    });

    input.value = '';
  }

  deletePhoto(photo: LeasePhoto): void {
    const selected = this.selectedLease();
    if (!selected) return;
    const confirmed = window.confirm('Supprimer cette photo ?');
    if (!confirmed) return;

    this.leaseService.deleteLeasePhoto(selected.id, photo.id).subscribe({
      next: () => {
        this.selectedLease.update((lease) =>
          lease ? { ...lease, photos: (lease.photos ?? []).filter((p) => p.id !== photo.id) } : lease
        );
      },
      error: () => this.error.set('Impossible de supprimer cette photo'),
    });
  }

  photosByType(type: 'entree' | 'sortie'): LeasePhoto[] {
    return (this.selectedLease()?.photos ?? []).filter((p) => p.type === type);
  }

  addLease() {
    this.openLeaseModal();
  }

  editLease(lease: Lease) {
    this.openLeaseModal(lease);
  }

  deleteLease(lease: Lease) {
    const confirmed = window.confirm(`Supprimer définitivement le bail #${lease.id} ?`);
    if (!confirmed) return;

    const previousLeases = this.leases();
    // Optimistic delete
    this.leases.set(previousLeases.filter(l => l.id !== lease.id));
    if (this.selectedLease()?.id === lease.id) {
      this.selectedLease.set(null);
      this.payments.set([]);
    }
    this.deletingId.set(lease.id);

    this.leaseService.deleteLease(lease.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.loadLeasesBackground();
      },
      error: () => {
        this.deletingId.set(null);
        this.leases.set(previousLeases); // rollback
        this.error.set('Erreur lors de la suppression du bail');
      },
    });
  }

  openLeaseModal(lease: Lease | null = null) {
    this.error.set(null);
    this.submitted.set(false);
    this.editingLease.set(lease);

    this.leaseForm.reset({
      property_id: lease?.property_id?.toString() ?? '',
      tenant_id: lease?.tenant_id?.toString() ?? '',
      type: lease?.type ?? 'nu',
      start_date: lease?.start_date ?? '',
      end_date: lease?.end_date ?? '',
      monthly_rent: lease?.monthly_rent ?? null,
      charges: lease?.charges ?? 0,
      deposit: lease?.deposit ?? null,
      payment_day: lease?.payment_day ?? 1,
      statut: lease?.statut ?? 'actif',
    });

    this.setCoTenants(lease);
    this.adjustFormForType(lease?.type ?? 'nu');
    this.createModalOpen.set(true);
  }

  get coTenantsArray(): FormArray {
    return this.leaseForm.get('co_tenants') as FormArray;
  }

  addCoTenant(): void {
    this.coTenantsArray.push(this.buildCoTenantGroup());
  }

  removeCoTenant(index: number): void {
    this.coTenantsArray.removeAt(index);
  }

  private buildCoTenantGroup(coTenant?: { tenant_id: number | string; rent_share?: number | null }) {
    return this.fb.group({
      tenant_id: [coTenant?.tenant_id?.toString() ?? '', Validators.required],
      rent_share: [coTenant?.rent_share ?? null, [Validators.min(0)]],
    });
  }

  private setCoTenants(lease: Lease | null): void {
    this.coTenantsArray.clear();
    (lease?.co_tenants ?? []).forEach((ct) => {
      this.coTenantsArray.push(this.buildCoTenantGroup({ tenant_id: ct.id, rent_share: ct.pivot.rent_share }));
    });
  }

  closeCreateModal() {
    this.createModalOpen.set(false);
    this.editingLease.set(null);
  }

  submitLease() {
    this.submitted.set(true);
    this.error.set(null);

    if (this.leaseForm.invalid) {
      return;
    }

    const formValue = this.leaseForm.getRawValue();
    const type = formValue.type;
    const rent = Number(formValue.monthly_rent);
    const deposit = formValue.deposit !== null && formValue.deposit !== '' ? Number(formValue.deposit) : 0;

    // Legal safety validations
    if (type === 'nu' && deposit > rent) {
      this.error.set('Le dépôt de garantie ne peut pas excéder 1 mois de loyer HC en location vide (Loi n° 89-462).');
      return;
    }
    if ((type === 'meuble' || type === 'etudiant') && deposit > 2 * rent) {
      this.error.set('Le dépôt de garantie ne peut pas excéder 2 mois de loyer HC en location meublée (Loi n° 89-462).');
      return;
    }
    if (type === 'mobilite' && deposit > 0) {
      this.error.set('Aucun dépôt de garantie ne peut être exigé pour un bail mobilité (Loi n° 89-462).');
      return;
    }

    if (formValue.end_date) {
      const start = new Date(formValue.start_date);
      const end = new Date(formValue.end_date);

      if (end <= start) {
        this.error.set('La date de fin doit être postérieure à la date de début.');
        return;
      }

      let minMonths = 0;
      let maxMonths: number | null = null;
      if (type === 'nu') minMonths = 36;
      else if (type === 'meuble') minMonths = 12;
      else if (type === 'etudiant') { minMonths = 9; maxMonths = 9; }
      else if (type === 'mobilite') { minMonths = 1; maxMonths = 10; }

      const minDate = new Date(start);
      minDate.setMonth(minDate.getMonth() + minMonths);
      minDate.setDate(minDate.getDate() - 1);

      if (end < minDate) {
        const typeLabel = type === 'nu' ? 'Location vide' : type === 'meuble' ? 'Location meublée' : type === 'etudiant' ? 'Bail meublé étudiant' : 'Bail mobilité';
        this.error.set(`La durée minimale d'un bail « ${typeLabel} » est de ${minMonths} mois (loi n° 89-462).`);
        return;
      }

      if (maxMonths !== null) {
        const maxDate = new Date(start);
        maxDate.setMonth(maxDate.getMonth() + maxMonths);
        if (end > maxDate) {
          const typeLabel = type === 'etudiant' ? 'Bail meublé étudiant' : 'Bail mobilité';
          this.error.set(`La durée maximale d'un bail « ${typeLabel} » est de ${maxMonths} mois (loi n° 89-462).`);
          return;
        }
      }
    }

    this.saving.set(true);
    const payload: CreateLeasePayload = {
      property_id: Number(formValue.property_id),
      tenant_id: Number(formValue.tenant_id),
      type: formValue.type,
      start_date: formValue.start_date,
      end_date: formValue.end_date || null,
      monthly_rent: rent,
      charges: Number(formValue.charges),
      deposit: formValue.deposit !== null && formValue.deposit !== '' ? Number(formValue.deposit) : null,
      payment_day: Number(formValue.payment_day),
      statut: formValue.statut,
      co_tenants: this.coTenantsArray.value
        .filter((ct: any) => ct.tenant_id !== '' && ct.tenant_id !== null)
        .map((ct: any) => ({
          tenant_id: Number(ct.tenant_id),
          rent_share: ct.rent_share !== null && ct.rent_share !== '' ? Number(ct.rent_share) : null,
        })),
    };

    const isEdit = !!this.editingLease();
    const previousLeases = this.leases();

    if (isEdit) {
      const editId = this.editingLease()!.id;
      const updatedLease: Lease = {
        ...this.editingLease()!,
        ...payload,
        type: payload.type as 'nu' | 'meuble' | 'etudiant' | 'mobilite',
        statut: (payload.statut || 'actif') as 'actif' | 'en_attente' | 'termine',
        charges: payload.charges || 0,
        deposit: payload.deposit || null,
        end_date: payload.end_date || null,
        payment_day: payload.payment_day !== undefined && payload.payment_day !== null ? payload.payment_day : null,
        co_tenants: this.editingLease()!.co_tenants,
      };
      this.leases.set(previousLeases.map(l => l.id === editId ? updatedLease : l));
      this.createModalOpen.set(false);

      this.leaseService.updateLease(editId, payload).subscribe({
        next: (savedLease) => {
          this.saving.set(false);
          this.leases.set(this.leases().map(l => l.id === editId ? savedLease : l));
          this.editingLease.set(null);
          this.loadLeasesBackground();
        },
        error: (response) => {
          this.saving.set(false);
          this.createModalOpen.set(true); // reopen
          this.leases.set(previousLeases); // rollback
          this.error.set(response.error?.message || 'Erreur lors de la sauvegarde du bail');
        }
      });
    } else {
      const tempId = -Date.now();
      const tempLease: Lease = {
        id: tempId,
        ...payload,
        type: payload.type as 'nu' | 'meuble' | 'etudiant' | 'mobilite',
        statut: (payload.statut || 'actif') as 'actif' | 'en_attente' | 'termine',
        charges: payload.charges || 0,
        deposit: payload.deposit || null,
        end_date: payload.end_date || null,
        payment_day: payload.payment_day !== undefined && payload.payment_day !== null ? payload.payment_day : null,
        co_tenants: undefined,
      };

      this.leases.set([...previousLeases, tempLease]);
      this.createModalOpen.set(false);

      this.leaseService.createLease(payload).subscribe({
        next: (savedLease) => {
          this.saving.set(false);
          this.leases.set(this.leases().map(l => l.id === tempId ? savedLease : l));
          this.loadLeasesBackground();
        },
        error: (response) => {
          this.saving.set(false);
          this.createModalOpen.set(true); // reopen
          this.leases.set(previousLeases); // rollback
          this.error.set(response.error?.message || 'Erreur lors de la sauvegarde du bail');
        }
      });
    }
  }

  // Rent Revision Methods
  openRevisionModal() {
    if (!this.selectedLease()) return;
    this.revisionModalOpen.set(true);
    this.error.set(null);
    this.revisionForm.reset();
  }

  closeRevisionModal() {
    this.revisionModalOpen.set(false);
  }

  submitRevision() {
    const selected = this.selectedLease();
    if (this.revisionForm.invalid || !selected) return;

    this.saving.set(true);
    this.error.set(null);
    const val = this.revisionForm.value;

    this.leaseService.reviseRent(selected.id, val.irl_old, val.irl_new).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.revisionModalOpen.set(false);
        alert(`Le loyer a été révisé avec succès. Nouveau loyer : ${res.new_rent} € (ancien : ${res.old_rent} €)`);
        this.loadLeasesBackground();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.message || 'Erreur lors de la révision du loyer');
      }
    });
  }

  // Lease Termination Methods
  openTerminationModal() {
    const selected = this.selectedLease();
    if (!selected) return;
    this.terminationModalOpen.set(true);
    this.error.set(null);
    this.terminationForm.reset({
      end_date: selected.end_date || ''
    });
  }

  closeTerminationModal() {
    this.terminationModalOpen.set(false);
  }

  submitTermination() {
    const selected = this.selectedLease();
    if (this.terminationForm.invalid || !selected) return;

    this.saving.set(true);
    this.error.set(null);
    const endDate = this.terminationForm.get('end_date')?.value;

    this.leaseService.terminateLease(selected.id, endDate).subscribe({
      next: () => {
        this.saving.set(false);
        this.terminationModalOpen.set(false);
        this.loadLeasesBackground();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.message || 'Erreur lors de la résiliation du bail');
      }
    });
  }

  // Payment CRUD operations
  openPaymentModal(payment: RentPayment | null = null) {
    const selected = this.selectedLease();
    if (!selected) return;

    if (payment) {
      // Récupère la version canonique de l'échéance avant de préremplir le formulaire d'édition.
      this.leaseService.getPayment(selected.id, payment.id).subscribe({
        next: (fresh) => this.fillPaymentModal(selected, fresh),
        error: () => this.fillPaymentModal(selected, payment),
      });
    } else {
      this.fillPaymentModal(selected, null);
    }
  }

  private fillPaymentModal(selected: Lease, payment: RentPayment | null) {
    this.editingPayment.set(payment);
    this.paymentSubmitted.set(false);
    this.error.set(null);

    this.paymentForm.reset({
      period: payment?.period ? payment.period.substring(0, 7) : '', // yyyy-MM format for input type="month"
      amount_rent: payment?.amount_rent ?? selected.monthly_rent,
      amount_charges: payment?.amount_charges ?? selected.charges,
      paid_at: payment?.paid_at ? payment.paid_at.substring(0, 10) : '',
      payment_method: payment?.payment_method ?? 'Virement'
    });

    this.paymentModalOpen.set(true);
  }

  closePaymentModal() {
    this.paymentModalOpen.set(false);
    this.editingPayment.set(null);
  }

  submitPayment() {
    this.paymentSubmitted.set(true);
    this.error.set(null);

    const leaseVal = this.selectedLease();
    if (this.paymentForm.invalid || !leaseVal) return;

    this.saving.set(true);
    const val = this.paymentForm.value;
    const payload = {
      period: val.period.length === 7 ? `${val.period}-01` : val.period, // Format Month picker value to Date string
      amount_rent: val.amount_rent !== null ? Number(val.amount_rent) : null,
      amount_charges: val.amount_charges !== null ? Number(val.amount_charges) : null,
      paid_at: val.paid_at || null,
      payment_method: val.paid_at ? val.payment_method : null
    };

    const isEdit = !!this.editingPayment();
    const previousPayments = this.payments();

    if (isEdit) {
      const editId = this.editingPayment()!.id;
      const rentVal = payload.amount_rent !== null && payload.amount_rent !== undefined ? Number(payload.amount_rent) : leaseVal.monthly_rent;
      const chargesVal = payload.amount_charges !== null && payload.amount_charges !== undefined ? Number(payload.amount_charges) : leaseVal.charges;
      const updatedPayment: RentPayment = {
        ...this.editingPayment()!,
        ...payload,
        amount_rent: rentVal,
        amount_charges: chargesVal,
        paid_at: payload.paid_at ? String(payload.paid_at) : null,
        payment_method: payload.payment_method ? String(payload.payment_method) : null,
        total: rentVal + chargesVal,
        status: payload.paid_at ? 'paye' as const : 'en_attente' as const
      };

      // Optimistic update
      this.payments.set(previousPayments.map(p => p.id === editId ? updatedPayment : p));
      this.paymentModalOpen.set(false);

      this.leaseService.updatePayment(leaseVal.id, editId, payload).subscribe({
        next: (savedPay) => {
          this.saving.set(false);
          this.payments.set(this.payments().map(p => p.id === editId ? savedPay : p));
          this.editingPayment.set(null);
          this.loadPaymentsBackground();
        },
        error: (err) => {
          this.saving.set(false);
          this.paymentModalOpen.set(true); // reopen
          this.payments.set(previousPayments); // rollback
          this.error.set(err.error?.message || 'Erreur lors de la sauvegarde de l\'échéance');
        }
      });
    } else {
      const tempId = -Date.now();
      const rentVal = payload.amount_rent !== null && payload.amount_rent !== undefined ? Number(payload.amount_rent) : leaseVal.monthly_rent;
      const chargesVal = payload.amount_charges !== null && payload.amount_charges !== undefined ? Number(payload.amount_charges) : leaseVal.charges;
      const tempPayment: RentPayment = {
        id: tempId,
        lease_id: leaseVal.id,
        period: payload.period,
        amount_rent: rentVal,
        amount_charges: chargesVal,
        paid_at: payload.paid_at ? String(payload.paid_at) : null,
        payment_method: payload.payment_method ? String(payload.payment_method) : null,
        total: rentVal + chargesVal,
        status: payload.paid_at ? 'paye' as const : 'en_attente' as const
      };

      // Optimistic create
      this.payments.set([...previousPayments, tempPayment]);
      this.paymentModalOpen.set(false);

      this.leaseService.createPayment(leaseVal.id, payload).subscribe({
        next: (savedPay) => {
          this.saving.set(false);
          this.payments.set(this.payments().map(p => p.id === tempId ? savedPay : p));
          this.loadPaymentsBackground();
        },
        error: (err) => {
          this.saving.set(false);
          this.paymentModalOpen.set(true); // reopen
          this.payments.set(previousPayments); // rollback
          this.error.set(err.error?.message || 'Erreur lors de la sauvegarde de l\'échéance');
        }
      });
    }
  }

  deletePayment(payment: RentPayment) {
    const leaseVal = this.selectedLease();
    if (!leaseVal) return;
    const confirmed = window.confirm(`Supprimer cette échéance de loyer pour ${payment.period} ?`);
    if (!confirmed) return;

    const previousPayments = this.payments();
    // Optimistic UI delete
    this.payments.set(previousPayments.filter(p => p.id !== payment.id));
    this.deletingId.set(payment.id);

    this.leaseService.deletePayment(leaseVal.id, payment.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.loadPaymentsBackground();
      },
      error: () => {
        this.deletingId.set(null);
        this.payments.set(previousPayments); // rollback
        alert('Impossible de supprimer cette échéance.');
      }
    });
  }

  // Quittance Rent Receipt Download
  viewQuittance(payment: RentPayment) {
    const selected = this.selectedLease();
    if (!selected) return;
    this.leaseService.getQuittance(selected.id, payment.id).subscribe({
      next: (res) => {
        this.quittanceDetails.set(res);
        this.quittanceModalOpen.set(true);
      },
      error: (err) => {
        alert(err.error?.message || 'Erreur lors du chargement de la quittance.');
      }
    });
  }

  closeQuittanceModal() {
    this.quittanceModalOpen.set(false);
    this.quittanceDetails.set(null);
  }

  printQuittance() {
    window.print();
  }

  resolvePropertyLabel(propertyId: number): string {
    return this.properties().find((property) => property.id === propertyId)?.label || `Bien #${propertyId}`;
  }

  resolveTenantLabel(tenantId: number): string {
    return this.tenants().find((tenant) => tenant.id === tenantId)?.label || `Locataire #${tenantId}`;
  }

  resolveLeaseTypeLabel(type: string): string {
    return this.leaseTypes.find(t => t.value === type)?.label || type;
  }

  leaseStatusTone(statut: string): 'success' | 'danger' | 'info' {
    return { actif: 'success' as const, termine: 'danger' as const, en_attente: 'info' as const }[statut] ?? 'info';
  }

  paymentStatusLabel(status: string): string {
    return status === 'paye' ? 'Payé' : (status === 'en_retard' ? 'En retard' : 'En attente');
  }

  paymentStatusTone(status: string): 'success' | 'danger' | 'warning' {
    return { paye: 'success' as const, en_retard: 'danger' as const, en_attente: 'warning' as const }[status] ?? 'warning';
  }

  // Getters for form validations
  get propertyId() { return this.leaseForm.get('property_id'); }
  get tenantId() { return this.leaseForm.get('tenant_id'); }
  get type() { return this.leaseForm.get('type'); }
  get startDate() { return this.leaseForm.get('start_date'); }
  get monthlyRent() { return this.leaseForm.get('monthly_rent'); }
  get charges() { return this.leaseForm.get('charges'); }
  get deposit() { return this.leaseForm.get('deposit'); }
  get paymentDay() { return this.leaseForm.get('payment_day'); }
  
  get period() { return this.paymentForm.get('period'); }

  get modalTitle() {
    return this.editingLease() ? 'Modifier le bail' : 'Nouveau bail';
  }

  get submitLabel() {
    return this.editingLease() ? 'Enregistrer les changements' : 'Créer le bail';
  }
}
