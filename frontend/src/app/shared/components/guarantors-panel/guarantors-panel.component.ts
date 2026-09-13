import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Guarantor, GuarantorPayload, TenantService } from '../../../core/services/tenant.service';
import { ConfirmService } from '../../../core/services/confirm.service';
import { NotificationService } from '../../../core/services/notification.service';
import {
  ImmoproBadgeComponent,
  ImmoproButtonComponent,
  ImmoproCardComponent,
  ImmoproEmptyStateComponent,
  ImmoproInputComponent,
  ImmoproSelectComponent,
} from 'ui-lib';

/**
 * Garants d'un locataire.
 *
 * Le pluriel est la règle et non l'exception : deux parents se portent
 * couramment caution pour un étudiant, et un dossier peut cumuler une caution
 * personnelle et une garantie Visale.
 *
 * Le formulaire s'adapte à la nature de la garantie. Une caution est une
 * personne, qu'il faut pouvoir nommer et joindre ; Visale et une assurance
 * loyers impayés sont des dispositifs, identifiés par un numéro de dossier.
 * Demander une date de naissance à un organisme n'aurait pas de sens, et
 * laisser un garant sans nom rendrait l'acte inopposable.
 */
@Component({
  selector: 'app-guarantors-panel',
  standalone: true,
  imports: [
    DatePipe,
    DecimalPipe,
    ReactiveFormsModule,
    ImmoproBadgeComponent,
    ImmoproButtonComponent,
    ImmoproCardComponent,
    ImmoproEmptyStateComponent,
    ImmoproInputComponent,
    ImmoproSelectComponent,
  ],
  templateUrl: './guarantors-panel.component.html',
  styleUrls: ['./guarantors-panel.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class GuarantorsPanelComponent {
  private tenants = inject(TenantService);
  private confirm = inject(ConfirmService);
  private notifications = inject(NotificationService);
  private fb = inject(FormBuilder);

  readonly tenantId = input.required<number>();

  /** Loyer du bail en cours, pour vérifier la règle des trois fois le loyer. */
  readonly monthlyRent = input<number | null>(null);

  readonly guarantors = signal<Guarantor[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  readonly formOpen = signal(false);
  readonly editing = signal<Guarantor | null>(null);

  readonly form: FormGroup;

  /**
   * Types de garantie.
   *
   * `personal` dit si la garantie est portée par une personne : c'est ce qui
   * décide des champs demandés.
   */
  readonly guaranteeTypes = [
    { value: 'caution_solidaire', label: 'Caution solidaire', personal: true },
    { value: 'caution_simple', label: 'Caution simple', personal: true },
    { value: 'depot_bancaire', label: 'Caution bancaire', personal: true },
    { value: 'visale', label: 'Visale (Action Logement)', personal: false },
    { value: 'garantie_loyers_impayes', label: 'Garantie loyers impayés', personal: false },
    { value: 'autre', label: 'Autre garantie', personal: true },
  ];

  readonly isPersonal = computed(() => {
    const chosen = this.form?.get('guarantee_type')?.value as string | undefined;

    return this.guaranteeTypes.find((type) => type.value === chosen)?.personal ?? true;
  });

  constructor() {
    this.form = this.fb.group({
      guarantee_type: ['caution_solidaire', [Validators.required]],
      first_name: [''],
      last_name: [''],
      birth_date: [''],
      profession: [''],
      company_name: [''],
      email: ['', [Validators.email]],
      phone: [''],
      address: [''],
      postal_code: [''],
      city: [''],
      monthly_income: [null],
      contract_reference: [''],
      starts_on: [''],
      ends_on: [''],
      max_amount: [null],
      notes: [''],
    });

    // `input.required` n'est pas encore résolu dans le constructeur : le
    // chargement attend la première lecture effective de l'identifiant.
    queueMicrotask(() => this.load());
  }

  load(): void {
    this.loading.set(true);

    this.tenants.getGuarantors(this.tenantId()).subscribe({
      next: (response) => {
        this.guarantors.set(response.data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set("Les garants n'ont pas pu être chargés.");
      },
    });
  }

  /* ----------------------------------------------------------------------
   | Formulaire
   |----------------------------------------------------------------------*/

  openForm(guarantor: Guarantor | null = null): void {
    this.error.set(null);
    this.editing.set(guarantor);

    this.form.reset({
      guarantee_type: guarantor?.guarantee_type ?? 'caution_solidaire',
      first_name: guarantor?.first_name ?? '',
      last_name: guarantor?.last_name ?? '',
      birth_date: guarantor?.birth_date ?? '',
      profession: guarantor?.profession ?? '',
      company_name: guarantor?.company_name ?? '',
      email: guarantor?.email ?? '',
      phone: guarantor?.phone ?? '',
      address: guarantor?.address ?? '',
      postal_code: guarantor?.postal_code ?? '',
      city: guarantor?.city ?? '',
      monthly_income: guarantor?.monthly_income ?? null,
      contract_reference: guarantor?.contract_reference ?? '',
      starts_on: guarantor?.starts_on ?? '',
      ends_on: guarantor?.ends_on ?? '',
      max_amount: guarantor?.max_amount ?? null,
      notes: guarantor?.notes ?? '',
    });

    this.formOpen.set(true);
  }

  closeForm(): void {
    if (this.saving()) {
      return;
    }

    this.formOpen.set(false);
    this.editing.set(null);
  }

  submit(): void {
    this.error.set(null);

    if (this.form.invalid) {
      return;
    }

    this.saving.set(true);

    const payload = this.cleanPayload();
    const editing = this.editing();

    const request = editing
      ? this.tenants.updateGuarantor(this.tenantId(), editing.id, payload)
      : this.tenants.createGuarantor(this.tenantId(), payload);

    request.subscribe({
      next: () => {
        this.saving.set(false);
        this.formOpen.set(false);
        this.editing.set(null);
        this.notifications.success(editing ? 'Garant mis à jour.' : 'Garant ajouté au dossier.');
        this.load();
      },
      error: (error: unknown) => {
        this.saving.set(false);

        const errors = (error as { error?: { errors?: Record<string, string[]> } })?.error?.errors;

        this.error.set(
          errors ? Object.values(errors).flat().join(' ') : "Le garant n'a pas pu être enregistré.",
        );
      },
    });
  }

  async remove(guarantor: Guarantor): Promise<void> {
    const confirmed = await this.confirm.ask({
      title: `Retirer ${guarantor.display_name ?? 'ce garant'} du dossier ?`,
      message:
        "La suppression est réversible : l'engagement reste conservé, car la prescription " +
        'des loyers court sur trois ans.',
      confirmLabel: 'Retirer le garant',
      danger: true,
    });

    if (!confirmed) {
      return;
    }

    this.tenants.deleteGuarantor(this.tenantId(), guarantor.id).subscribe({
      next: () => {
        this.notifications.success('Garant retiré du dossier.');
        this.load();
      },
      error: (error: unknown) => this.notifications.fromHttp(error, 'La suppression a échoué.'),
    });
  }

  /* ----------------------------------------------------------------------
   | Lecture
   |----------------------------------------------------------------------*/

  typeLabel(guarantor: Guarantor): string {
    return (
      this.guaranteeTypes.find((type) => type.value === guarantor.guarantee_type)?.label ??
      guarantor.guarantee_type
    );
  }

  /**
   * La règle d'usage des trois fois le loyer.
   *
   * Ce n'est pas une obligation légale mais la pratique constante des bailleurs
   * et des assureurs. Renvoie `null` quand la question ne se pose pas : un
   * organisme n'a pas de salaire.
   */
  coversRent(guarantor: Guarantor): boolean | null {
    const rent = this.monthlyRent();
    const income = Number(guarantor.monthly_income ?? 0);

    if (!rent || !income) {
      return null;
    }

    return income >= rent * 3;
  }

  private cleanPayload(): GuarantorPayload {
    const raw = this.form.value as Record<string, unknown>;

    const cleaned = Object.fromEntries(
      Object.entries(raw).map(([key, value]) => [
        key,
        typeof value === 'string' && value.trim() === '' ? null : value,
      ]),
    );

    return cleaned as unknown as GuarantorPayload;
  }
}
