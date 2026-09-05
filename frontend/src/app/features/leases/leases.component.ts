import {
  Component,
  OnInit,
  inject,
  signal,
  computed,
  ChangeDetectionStrategy,
} from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import { FormBuilder, FormGroup, FormArray, ReactiveFormsModule, Validators } from '@angular/forms';
import { DatePipe } from '@angular/common';
import { EMPTY, catchError, forkJoin, map, switchMap, tap } from 'rxjs';
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
  ImmoproFilterBarComponent,
  ImmoproPaginationComponent,
  ImmoproComboboxComponent,
  ComboboxOption,
  FilterChip,
} from 'ui-lib';
import {
  CreateLeasePayload,
  Lease,
  LeasePhoto,
  LeaseService,
  RentPayment,
  QuittanceData,
} from '../../core/services/lease.service';
import { PortfolioService } from '../../core/services/portfolio.service';
import { TenantService } from '../../core/services/tenant.service';
import { ConfirmService } from '../../core/services/confirm.service';
import { NotificationService } from '../../core/services/notification.service';
import { LayoutService } from '../../core/services/layout.service';
import { DocumentsPanelComponent } from '../../shared/components/documents-panel/documents-panel.component';
import { QuittancePreviewComponent } from './quittance-preview.component';
import { PaginatedResponse } from '../../core/list/pagination.model';
import { createListQuery } from '../../core/list/list-query';

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
    ImmoproFilterBarComponent,
    ImmoproPaginationComponent,
    ImmoproComboboxComponent,
    DocumentsPanelComponent,
    QuittancePreviewComponent,
    DatePipe,
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
  private confirm = inject(ConfirmService);
  private notifications = inject(NotificationService);
  private route = inject(ActivatedRoute);
  protected layout = inject(LayoutService);

  /**
   * Les listes déroulantes du formulaire ne sont chargées qu'à la première
   * ouverture d'une modale, et une seule fois par visite.
   */
  private supportDataLoaded = false;

  /** Recherche, filtres et page de la liste des baux, mémorisés dans l'URL. */
  protected readonly list = createListQuery({
    defaultSort: 'start_date',
    defaultDirection: 'desc',
    filterKeys: ['type', 'statut'],
  });

  readonly activeChips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const filters = this.list.filters();
    if (filters['type']) {
      const label =
        this.leaseTypes.find((t) => t.value === filters['type'])?.label ?? filters['type'];
      chips.push({ key: 'type', label: 'Type de bail', value: label });
    }
    if (filters['statut']) {
      const label =
        this.statusOptions.find((s) => s.value === filters['statut'])?.label ?? filters['statut'];
      chips.push({ key: 'statut', label: 'Statut', value: label });
    }
    return chips;
  });

  /**
   * Les échéances du bail sélectionné ont leur propre état : elles vivent dans
   * un panneau distinct, avec leurs filtres et leur pagination. Un préfixe
   * évite que leurs paramètres n'entrent en collision avec ceux de la liste
   * principale dans l'URL.
   */
  protected readonly paymentsList = createListQuery({
    defaultSort: 'period',
    defaultDirection: 'desc',
    perPage: 12,
    filterKeys: ['statut_paiement', 'annee'],
  });

  leases = signal<Lease[]>([]);
  pagination = signal<PaginatedResponse<Lease> | undefined>(undefined);
  listLoading = signal(false);
  paymentsPagination = signal<PaginatedResponse<RentPayment> | undefined>(undefined);
  /**
   * Biens et locataires proposés au formulaire.
   *
   * Le `hint` porte ce qui départage deux libellés proches — la ville et le
   * portefeuille d'un bien, le courriel d'un locataire. Sur un parc où trois
   * lots s'appellent « T2 Rue de Charonne », c'est la seule information qui
   * permet de choisir.
   */
  properties = signal<ComboboxOption[]>([]);
  tenants = signal<ComboboxOption[]>([]);

  /**
   * Sur téléphone, liste et fiche ne tiennent pas côte à côte : l'écran devient
   * un empilement liste → fiche, avec un retour explicite. Sur grand écran, les
   * deux colonnes cohabitent et ce drapeau ne sert à rien.
   */
  detailOpenOnMobile = signal(false);

  paymentStatuses = [
    { value: 'paye', label: 'Payé' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'en_retard', label: 'En retard' },
  ];

  /** Années proposées au filtre des échéances : l'année en cours et les quatre précédentes. */
  readonly paymentYears = Array.from({ length: 5 }, (_, index) =>
    String(new Date().getFullYear() - index),
  );

  // Forms
  leaseForm: FormGroup;
  revisionForm: FormGroup;
  terminationForm: FormGroup;
  paymentForm: FormGroup;
  scheduleForm: FormGroup;

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
  scheduleModalOpen = signal(false);

  // Modal payload and details
  editingLease = signal<Lease | null>(null);
  editingPayment = signal<RentPayment | null>(null);
  quittanceDetails = signal<QuittanceData | null>(null);

  // State flags
  saving = signal(false);
  deletingId = signal<number | null>(null);
  error = signal<string | null>(null);

  /**
   * Erreurs de validation renvoyées par le serveur, champ par champ.
   *
   * Les règles de droit — plafond du dépôt, durée minimale, unicité d'un
   * colocataire — vivent côté serveur et nulle part ailleurs : une règle écrite
   * des deux côtés finit par diverger, et c'est la version du front qui se
   * périme. Le formulaire ne les rejoue donc pas, il affiche ce que le serveur
   * répond, à l'endroit où la saisie a eu lieu.
   */
  fieldErrors = signal<Record<string, string>>({});

  submitted = signal(false);
  paymentSubmitted = signal(false);

  /** Échéances cochées, pour le pointage en lot. */
  selectedPaymentIds = signal<ReadonlySet<number>>(new Set());

  /** Identifiant de l'échéance en cours de pointage, pour désactiver son bouton. */
  payingId = signal<number | null>(null);

  readonly selectedPaymentCount = computed(() => this.selectedPaymentIds().size);

  /** Échéances de la page en cours qui peuvent encore être pointées. */
  readonly payablePayments = computed(() => this.payments().filter((p) => !p.paid_at));

  readonly allPayableSelected = computed(() => {
    const payable = this.payablePayments();
    const selected = this.selectedPaymentIds();

    return payable.length > 0 && payable.every((payment) => selected.has(payment.id));
  });

  /**
   * Les durées annoncées ici sont indicatives : le serveur seul tranche.
   *
   * « 9 mois » laissait croire que le bail étudiant était figé à cette durée,
   * alors que neuf mois est la réduction *maximale* que la loi autorise sur la
   * durée d'un an du meublé. Une année universitaire de septembre à juin en
   * compte dix, et elle est parfaitement valable.
   */
  leaseTypes = [
    { value: 'nu', label: 'Location vide (Nu)' },
    { value: 'meuble', label: 'Location meublée' },
    { value: 'etudiant', label: 'Bail étudiant (9 à 12 mois)' },
    { value: 'mobilite', label: 'Bail mobilité (1 à 10 mois)' },
  ];

  statusOptions = [
    { value: 'actif', label: 'Actif' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'termine', label: 'Terminé' },
  ];

  scheduleHorizons = [
    { value: 3, label: '3 mois' },
    { value: 6, label: '6 mois' },
    { value: 12, label: '12 mois' },
    { value: 24, label: '24 mois' },
    { value: 36, label: '36 mois' },
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
      generate_schedule: [true],
      schedule_months: [12],
      co_tenants: this.fb.array([]),
    });

    this.revisionForm = this.fb.group({
      irl_old: [null, [Validators.required, Validators.min(0.01)]],
      irl_new: [null, [Validators.required, Validators.min(0.01)]],
    });

    this.terminationForm = this.fb.group({
      end_date: ['', [Validators.required]],
    });

    this.paymentForm = this.fb.group({
      period: ['', [Validators.required]],
      amount_rent: [null, [Validators.min(0)]],
      amount_charges: [null, [Validators.min(0)]],
      paid_at: [''],
      payment_method: ['Virement'],
    });

    this.scheduleForm = this.fb.group({
      months: [12, [Validators.required]],
      prorate: [true],
    });

    // Handle lease type adjustments dynamically
    this.leaseForm.get('type')?.valueChanges.subscribe((type) => {
      this.adjustFormForType(type);
    });

    // Liste des baux : rechargée à chaque changement de critère, la requête
    // précédente étant annulée par switchMap.
    toObservable(this.list.trigger)
      .pipe(
        tap(() => this.listLoading.set(true)),
        switchMap(({ params }) =>
          this.leaseService.getLeases(params).pipe(
            catchError(() => {
              this.error.set('Erreur lors du chargement des baux');
              this.listLoading.set(false);
              return EMPTY;
            }),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((response) => {
        this.leases.set(response.data);
        this.pagination.set(response);
        this.listLoading.set(false);

        // Garde le bail ouvert à jour s'il fait partie de la page reçue, sans
        // écraser le résumé d'échéancier que seule la fiche ramène.
        const selected = this.selectedLease();
        if (selected) {
          const updated = response.data.find((lease) => lease.id === selected.id);
          if (updated) {
            this.selectedLease.set({ ...updated, schedule: selected.schedule });
          }
        }
      });

    // Échéances du bail sélectionné.
    toObservable(this.paymentsList.trigger)
      .pipe(
        switchMap(({ params }) => {
          const selected = this.selectedLease();
          if (!selected) {
            return EMPTY;
          }

          this.paymentsLoading.set(true);

          // Le backend attend « statut » ; l'URL le préfixe pour ne pas écraser
          // le filtre de statut de la liste des baux.
          const { statut_paiement, ...rest } = params.filters;

          return this.leaseService
            .getPayments(selected.id, {
              ...params,
              filters: { ...rest, statut: statut_paiement ?? '' },
            })
            .pipe(
              catchError(() => {
                this.error.set('Impossible de charger les échéances de loyer');
                this.paymentsLoading.set(false);
                return EMPTY;
              }),
            );
        }),
        takeUntilDestroyed(),
      )
      .subscribe((response) => {
        this.payments.set(response.data);
        this.paymentsPagination.set(response);
        this.paymentsLoading.set(false);

        // Une sélection qui survivrait au changement de page ferait pointer des
        // échéances qu'on ne voit plus.
        this.selectedPaymentIds.set(new Set());
      });
  }

  ngOnInit() {
    // Les listes déroulantes du formulaire ne servent qu'aux modales de
    // création et d'édition. Les charger ici imposait deux appels HTTP, plus un
    // par portefeuille, à *toute* ouverture de l'écran — y compris pour un
    // utilisateur venu simplement consulter ses baux.

    this.openLeaseFromUrl();
  }

  /**
   * Ouvre directement le bail désigné par `?lease=`.
   *
   * La recherche globale renvoyait auparavant vers `/leases?search=Dupont`, ce
   * qui n'ouvrait rien : il fallait retrouver la bonne ligne parmi les
   * homonymes. Le bail est maintenant désigné par son identifiant, et le
   * dossier s'ouvre à l'arrivée.
   *
   * Le bail visé n'est pas forcément dans la première page de la liste : on le
   * demande donc directement, plutôt que de le chercher dans ce qui est déjà
   * chargé.
   */
  private openLeaseFromUrl(): void {
    const raw = this.route.snapshot.queryParamMap.get('lease');
    const id = raw !== null && /^\d+$/.test(raw) ? Number(raw) : null;

    if (id === null) {
      return;
    }

    this.leaseService.getLease(id).subscribe({
      next: (lease) => {
        this.selectedLease.set(lease);
        this.detailOpenOnMobile.set(true);
        this.paymentsList.setPage(1);
        // `setPage(1)` ne déclenche rien si la page valait déjà 1 — c'est le cas
        // à l'arrivée. Sans ce rafraîchissement explicite, l'écran ouvert par
        // lien direct affichait un échéancier vide sur un bail qui en a un.
        this.paymentsList.refresh();
      },
      error: () => this.notifications.error("Ce bail n'existe plus ou ne vous appartient pas."),
    });
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

  /**
   * Alimente les menus déroulants du formulaire de bail.
   *
   * Ces listes doivent être exhaustives : un bien ou un locataire absent du
   * menu est un bien qu'on ne peut pas louer. Auparavant, seule la première
   * page de locataires était demandée, ce qui en masquait silencieusement
   * au-delà du dixième.
   */
  loadSupportData() {
    // Une seule fois par visite : ces listes ne changent pas entre deux
    // ouvertures de la modale, et les recharger ferait clignoter les menus.
    if (this.supportDataLoaded) {
      return;
    }

    this.supportDataLoaded = true;

    forkJoin({
      portfolios: this.portfolioService.getAllPortfolios(),
      tenants: this.tenantService.getAllTenants(),
    }).subscribe({
      next: ({ portfolios, tenants }) => {
        this.tenants.set(
          tenants.map((tenant) => ({
            value: tenant.id,
            label: `${tenant.first_name} ${tenant.last_name}`,
            hint: tenant.email ?? undefined,
          })),
        );

        if (!portfolios.length) {
          this.properties.set([]);
          return;
        }

        forkJoin(
          portfolios.map((portfolio) =>
            this.portfolioService
              .getAllPortfolioProperties(portfolio.id)
              .pipe(map((properties) => ({ portfolio, properties }))),
          ),
        ).subscribe({
          next: (byPortfolio) => {
            this.properties.set(
              byPortfolio.flatMap(({ portfolio, properties }) =>
                properties.map((property) => ({
                  value: property.id,
                  label: property.title,
                  hint: [property.city, portfolio.name].filter(Boolean).join(' · '),
                })),
              ),
            );
          },
          error: () => {
            this.supportDataLoaded = false;
            this.notifications.error('Impossible de charger la liste des biens.');
          },
        });
      },
      error: () => {
        // Une erreur ici doit pouvoir être retentée à la réouverture.
        this.supportDataLoaded = false;
        this.notifications.error('Impossible de charger la liste des biens et des locataires.');
      },
    });
  }

  // ── Navigation liste ↔ fiche ────────────────────────────────────────────────

  selectLease(lease: Lease) {
    // Affiche immédiatement la version en liste, puis rafraîchit avec la version canonique du serveur.
    this.selectedLease.set(lease);
    this.detailOpenOnMobile.set(true);
    this.selectedPaymentIds.set(new Set());

    // Sur téléphone, la fiche remplace la liste : sans remontée, on arrive au
    // milieu du contenu, à la hauteur où l'on avait touché la carte.
    if (this.layout.isNarrow()) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Changer de bail repart de la première page d'échéances : rester en page 3
    // sur un bail qui n'en compte qu'une afficherait un panneau vide.
    this.paymentsList.setPage(1);
    this.paymentsList.refresh();

    this.refreshSelectedLease(lease.id);
  }

  /** Retour à la liste depuis la fiche, sur écran étroit. */
  backToList(): void {
    this.detailOpenOnMobile.set(false);
  }

  /**
   * Recharge la fiche depuis le serveur : c'est le seul chemin qui ramène le
   * résumé de l'échéancier (nombre d'échéances, impayés, montant dû).
   */
  private refreshSelectedLease(id: number): void {
    this.leaseService.getLease(id).subscribe({
      next: (fresh) => {
        if (this.selectedLease()?.id === fresh.id) {
          this.selectedLease.set(fresh);
        }
      },
    });
  }

  // ── État des lieux (photos d'entrée / de sortie) ────────────────────────────

  uploadPhoto(type: 'entree' | 'sortie', event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const selected = this.selectedLease();
    if (!file || !selected) return;

    this.photoUploading.set(type);
    this.leaseService.uploadLeasePhoto(selected.id, type, file).subscribe({
      next: (photo) => {
        this.photoUploading.set(null);
        this.selectedLease.update((lease) =>
          lease ? { ...lease, photos: [...(lease.photos ?? []), photo] } : lease,
        );
      },
      error: (err: unknown) => {
        this.photoUploading.set(null);
        this.notifications.fromHttp(
          err,
          "Impossible de téléverser cette photo de l'état des lieux",
        );
      },
    });

    input.value = '';
  }

  async deletePhoto(photo: LeasePhoto): Promise<void> {
    const selected = this.selectedLease();
    if (!selected) return;

    const confirmed = await this.confirm.ask({
      title: 'Supprimer cette photo ?',
      message:
        "Les photos de l'état des lieux font foi en cas de litige sur le dépôt de garantie. " +
        'Cette suppression est définitive.',
      confirmLabel: 'Supprimer',
      danger: true,
    });

    if (!confirmed) return;

    this.leaseService.deleteLeasePhoto(selected.id, photo.id).subscribe({
      next: () => {
        this.selectedLease.update((lease) =>
          lease
            ? { ...lease, photos: (lease.photos ?? []).filter((p) => p.id !== photo.id) }
            : lease,
        );
      },
      error: (err: unknown) =>
        this.notifications.fromHttp(err, 'Impossible de supprimer cette photo'),
    });
  }

  photosByType(type: 'entree' | 'sortie'): LeasePhoto[] {
    return (this.selectedLease()?.photos ?? []).filter((p) => p.type === type);
  }

  // ── Cycle de vie du bail ────────────────────────────────────────────────────

  addLease() {
    this.openLeaseModal();
  }

  editLease(lease: Lease) {
    this.openLeaseModal(lease);
  }

  /**
   * Suppression d'un bail, sortie de l'en-tête de la fiche.
   *
   * Elle y voisinait « Réviser le loyer » et « Résilier », à un clic d'écart, en
   * haut de l'écran — la place qu'on donne à une action courante. Or supprimer
   * un bail emporte son échéancier et ses quittances. Elle vit désormais en bas
   * de la fiche, dans une zone séparée, et demande de recopier un mot.
   *
   * Le serveur oppose deux refus : un bail actif se résilie plutôt que de se
   * supprimer, et un bail dont des loyers ont été encaissés demande un
   * acquittement explicite — que la recopie du mot autorise.
   */
  async deleteLease(lease: Lease) {
    const settled = (lease.schedule?.paid_count ?? 0) > 0;

    const confirmed = await this.confirm.ask({
      title: 'Supprimer ce bail ?',
      message:
        `Le bail de ${this.resolveTenantLabel(lease)} sur ${this.resolvePropertyLabel(lease)} ` +
        `sera retiré de la gestion courante, avec ses ${lease.schedule?.count ?? 0} échéance(s). ` +
        (settled
          ? 'Des loyers ont été encaissés sur ce bail : les quittances déjà remises restent valables, ' +
            'mais leur trace disparaîtra de vos écrans.'
          : 'La suppression reste réversible en base.'),
      confirmLabel: 'Supprimer le bail',
      danger: true,
      typeToConfirm: 'SUPPRIMER',
    });

    if (!confirmed) {
      return;
    }

    this.deletingId.set(lease.id);

    this.leaseService.deleteLease(lease.id, true).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.selectedLease.set(null);
        this.payments.set([]);
        this.detailOpenOnMobile.set(false);
        this.list.refresh();
        this.notifications.success('Bail supprimé.');
      },
      error: (error) => {
        this.deletingId.set(null);
        this.notifications.fromHttp(error, 'La suppression du bail a échoué.');
      },
    });
  }

  openLeaseModal(lease: Lease | null = null) {
    // Les menus déroulants sont nécessaires à partir d'ici, et pas avant.
    this.loadSupportData();

    this.error.set(null);
    this.fieldErrors.set({});
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
      generate_schedule: true,
      schedule_months: 12,
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

  private buildCoTenantGroup(coTenant?: {
    tenant_id: number | string;
    rent_share?: number | null;
  }) {
    return this.fb.group({
      tenant_id: [coTenant?.tenant_id?.toString() ?? '', Validators.required],
      rent_share: [coTenant?.rent_share ?? null, [Validators.min(0)]],
    });
  }

  private setCoTenants(lease: Lease | null): void {
    this.coTenantsArray.clear();
    (lease?.co_tenants ?? []).forEach((ct) => {
      this.coTenantsArray.push(
        this.buildCoTenantGroup({ tenant_id: ct.id, rent_share: ct.pivot.rent_share }),
      );
    });
  }

  closeCreateModal() {
    this.createModalOpen.set(false);
    this.editingLease.set(null);
  }

  submitLease() {
    this.submitted.set(true);
    this.error.set(null);
    this.fieldErrors.set({});

    if (this.leaseForm.invalid) {
      return;
    }

    const formValue = this.leaseForm.getRawValue();
    const isEdit = !!this.editingLease();

    const payload: CreateLeasePayload = {
      property_id: Number(formValue.property_id),
      tenant_id: Number(formValue.tenant_id),
      type: formValue.type,
      start_date: formValue.start_date,
      end_date: formValue.end_date || null,
      monthly_rent: Number(formValue.monthly_rent),
      charges: Number(formValue.charges),
      deposit:
        formValue.deposit !== null && formValue.deposit !== '' ? Number(formValue.deposit) : null,
      payment_day: Number(formValue.payment_day),
      statut: formValue.statut,
      co_tenants: this.coTenantsArray.value
        .filter((ct: { tenant_id: string | null }) => ct.tenant_id !== '' && ct.tenant_id !== null)
        .map((ct: { tenant_id: string; rent_share: number | string | null }) => ({
          tenant_id: Number(ct.tenant_id),
          rent_share: ct.rent_share !== null && ct.rent_share !== '' ? Number(ct.rent_share) : null,
        })),
    };

    // L'échéancier n'est posé qu'à la création : sur un bail existant, il se
    // complète depuis sa fiche, où l'on voit ce qui est déjà couvert.
    if (!isEdit) {
      payload.generate_schedule = !!formValue.generate_schedule;
      payload.schedule_months = Number(formValue.schedule_months);
    }

    this.saving.set(true);

    const request = isEdit
      ? this.leaseService.updateLease(this.editingLease()!.id, payload)
      : this.leaseService.createLease(payload);

    request.subscribe({
      next: (savedLease) => {
        this.saving.set(false);
        this.createModalOpen.set(false);
        this.editingLease.set(null);
        this.list.refresh();

        if (isEdit) {
          this.notifications.success('Bail mis à jour.');

          if (this.selectedLease()?.id === savedLease.id) {
            this.selectedLease.set(savedLease);
            this.paymentsList.refresh();
          }

          return;
        }

        const generated = savedLease.schedule?.count ?? 0;
        this.notifications.success(
          generated > 0
            ? `Bail créé, ${generated} échéances générées.`
            : 'Bail créé. Générez son échéancier depuis sa fiche.',
        );

        // Le nouveau bail s'ouvre : c'est là que se fait la suite du travail.
        this.selectLease(savedLease);
      },
      error: (response) => this.handleFormError(response),
    });
  }

  /**
   * Reporte une réponse 422 sur les champs concernés.
   *
   * Laravel renvoie `errors` sous la forme `{ champ: [messages] }`. Sans ce
   * report, une erreur de plafond de dépôt s'affichait en haut du formulaire,
   * loin du champ à corriger — et sur mobile, hors de l'écran.
   */
  private handleFormError(response: unknown): void {
    this.saving.set(false);

    const payload = (response as { error?: { errors?: Record<string, string[]> } })?.error;
    const errors = payload?.errors;

    if (errors) {
      this.fieldErrors.set(
        Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]])),
      );
    }

    this.error.set(this.notifications.describe(response, 'Erreur lors de la sauvegarde du bail'));
  }

  /** Message d'erreur à afficher sous un champ : serveur d'abord, formulaire ensuite. */
  fieldError(field: string, fallback: string): string | null {
    const fromServer = this.fieldErrors()[field];
    if (fromServer) {
      return fromServer;
    }

    return this.submitted() && this.leaseForm.get(field)?.invalid ? fallback : null;
  }

  // ── Révision du loyer ───────────────────────────────────────────────────────

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
        this.notifications.success(
          `Loyer révisé : ${res.new_rent} € par mois (auparavant ${res.old_rent} €).` +
            (res.repriced_payments > 0
              ? ` ${res.repriced_payments} échéance(s) à venir mises à jour.`
              : ''),
        );
        this.selectedLease.set(res.data);
        this.list.refresh();
        this.paymentsList.refresh();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(this.notifications.describe(err, 'Erreur lors de la révision du loyer'));
      },
    });
  }

  // ── Résiliation ─────────────────────────────────────────────────────────────

  openTerminationModal() {
    const selected = this.selectedLease();
    if (!selected) return;
    this.terminationModalOpen.set(true);
    this.error.set(null);
    this.terminationForm.reset({
      end_date: selected.end_date || '',
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
      next: (res) => {
        this.saving.set(false);
        this.terminationModalOpen.set(false);
        this.selectedLease.set(res.data);
        this.notifications.success(res.message);
        this.list.refresh();
        this.paymentsList.refresh();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(this.notifications.describe(err, 'Erreur lors de la résiliation du bail'));
      },
    });
  }

  // ── Échéancier : génération ─────────────────────────────────────────────────

  openScheduleModal(): void {
    const selected = this.selectedLease();
    if (!selected) return;

    this.error.set(null);
    this.scheduleForm.reset({ months: 12, prorate: true });
    this.scheduleModalOpen.set(true);
  }

  closeScheduleModal(): void {
    this.scheduleModalOpen.set(false);
  }

  submitSchedule(): void {
    const selected = this.selectedLease();
    if (!selected) return;

    this.saving.set(true);
    this.error.set(null);

    const { months, prorate } = this.scheduleForm.getRawValue();

    this.leaseService
      .generateSchedule(selected.id, {
        // Reprendre au premier mois non couvert évite de redemander au serveur
        // des mois qu'il ignorera de toute façon, et rend le compte annoncé
        // conforme à ce que l'utilisateur a demandé.
        from: selected.schedule?.next_period ?? undefined,
        months: Number(months),
        prorate: !!prorate,
      })
      .subscribe({
        next: (result) => {
          this.saving.set(false);
          this.scheduleModalOpen.set(false);
          this.notifications.success(result.message);
          this.paymentsList.setPage(1);
          this.paymentsList.refresh();
          this.refreshSelectedLease(selected.id);
        },
        error: (err) => {
          this.saving.set(false);
          this.error.set(
            this.notifications.describe(err, "La génération de l'échéancier a échoué"),
          );
        },
      });
  }

  // ── Échéancier : pointage ───────────────────────────────────────────────────

  /**
   * Pointe un règlement en un clic, à la date du jour.
   *
   * C'est le geste quotidien du bailleur : constater qu'un virement est arrivé.
   * Il passait par le formulaire complet de l'échéance — période, montants,
   * moyen de paiement — alors que tout y était déjà juste.
   */
  markPaid(payment: RentPayment): void {
    const selected = this.selectedLease();
    if (!selected) return;

    this.payingId.set(payment.id);

    this.leaseService.markPaid(selected.id, payment.id).subscribe({
      next: (updated) => {
        this.payingId.set(null);
        this.payments.update((list) => list.map((p) => (p.id === updated.id ? updated : p)));
        this.refreshSelectedLease(selected.id);
      },
      error: (err: unknown) => {
        this.payingId.set(null);
        this.notifications.fromHttp(err, 'Impossible de pointer cette échéance.');
      },
    });
  }

  /** Annule un pointage fait par erreur : l'échéance redevient due. */
  async markUnpaid(payment: RentPayment): Promise<void> {
    const selected = this.selectedLease();
    if (!selected) return;

    const confirmed = await this.confirm.ask({
      title: 'Annuler ce pointage ?',
      message:
        `L'échéance redeviendra due. Si une quittance a déjà été remise au locataire pour ` +
        `cette période, elle reste valable — la quittance atteste du paiement, pas cet écran.`,
      confirmLabel: 'Annuler le pointage',
      danger: true,
    });

    if (!confirmed) return;

    this.payingId.set(payment.id);

    this.leaseService.markUnpaid(selected.id, payment.id).subscribe({
      next: (updated) => {
        this.payingId.set(null);
        this.payments.update((list) => list.map((p) => (p.id === updated.id ? updated : p)));
        this.refreshSelectedLease(selected.id);
      },
      error: (err: unknown) => {
        this.payingId.set(null);
        this.notifications.fromHttp(err, 'Impossible de revenir sur ce pointage.');
      },
    });
  }

  togglePaymentSelection(payment: RentPayment): void {
    this.selectedPaymentIds.update((current) => {
      const next = new Set(current);
      next.has(payment.id) ? next.delete(payment.id) : next.add(payment.id);
      return next;
    });
  }

  isPaymentSelected(payment: RentPayment): boolean {
    return this.selectedPaymentIds().has(payment.id);
  }

  toggleAllPayable(): void {
    this.selectedPaymentIds.set(
      this.allPayableSelected() ? new Set() : new Set(this.payablePayments().map((p) => p.id)),
    );
  }

  /**
   * Pointe la sélection en une écriture.
   *
   * Un bailleur rapproche ses loyers par relevé bancaire, pas ligne à ligne :
   * il coche les échéances reçues et valide une fois.
   */
  bulkMarkPaid(): void {
    const selected = this.selectedLease();
    const ids = [...this.selectedPaymentIds()];

    if (!selected || ids.length === 0) return;

    this.saving.set(true);

    this.leaseService.bulkMarkPaid(selected.id, ids).subscribe({
      next: (result) => {
        this.saving.set(false);
        this.selectedPaymentIds.set(new Set());
        this.notifications.success(result.message);
        this.paymentsList.refresh();
        this.refreshSelectedLease(selected.id);
      },
      error: (err: unknown) => {
        this.saving.set(false);
        this.notifications.fromHttp(err, 'Le pointage groupé a échoué.');
      },
    });
  }

  // ── Échéancier : saisie manuelle ────────────────────────────────────────────

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
      // À la création, le mois proposé est le premier non couvert : c'est
      // toujours celui qu'on veut ajouter, et le serveur refuse les doublons.
      period: payment?.period
        ? payment.period.substring(0, 7)
        : (selected.schedule?.next_period ?? '').substring(0, 7),
      amount_rent: payment?.amount_rent ?? selected.monthly_rent,
      amount_charges: payment?.amount_charges ?? selected.charges,
      paid_at: payment?.paid_at ? payment.paid_at.substring(0, 10) : '',
      payment_method: payment?.payment_method ?? 'Virement',
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
      payment_method: val.paid_at ? val.payment_method : null,
    };

    const editing = this.editingPayment();

    const request = editing
      ? this.leaseService.updatePayment(leaseVal.id, editing.id, payload)
      : this.leaseService.createPayment(leaseVal.id, payload);

    request.subscribe({
      next: () => {
        this.saving.set(false);
        this.paymentModalOpen.set(false);
        this.editingPayment.set(null);
        this.paymentsList.refresh();
        this.refreshSelectedLease(leaseVal.id);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(
          this.notifications.describe(err, "Erreur lors de la sauvegarde de l'échéance"),
        );
      },
    });
  }

  async deletePayment(payment: RentPayment) {
    const leaseVal = this.selectedLease();
    if (!leaseVal) return;

    const confirmed = await this.confirm.ask({
      title: 'Supprimer cette échéance ?',
      message: `L'échéance de ${payment.period} sera retirée du suivi. Si une quittance a été remise au locataire, elle restera valable.`,
      confirmLabel: "Supprimer l'échéance",
      danger: true,
    });

    if (!confirmed) return;

    const previousPayments = this.payments();
    // Optimistic UI delete
    this.payments.set(previousPayments.filter((p) => p.id !== payment.id));
    this.deletingId.set(payment.id);

    this.leaseService.deletePayment(leaseVal.id, payment.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.paymentsList.refresh();
        this.refreshSelectedLease(leaseVal.id);
      },
      error: (error: unknown) => {
        this.deletingId.set(null);
        this.payments.set(previousPayments); // rollback
        this.notifications.fromHttp(error, 'Impossible de supprimer cette échéance.');
      },
    });
  }

  // ── Quittance ───────────────────────────────────────────────────────────────

  viewQuittance(payment: RentPayment) {
    const selected = this.selectedLease();
    if (!selected) return;
    this.leaseService.getQuittance(selected.id, payment.id).subscribe({
      next: (res) => {
        this.quittanceDetails.set(res);
        this.quittanceModalOpen.set(true);
      },
      error: (err: unknown) => {
        this.notifications.fromHttp(err, 'Impossible de charger la quittance.');
      },
    });
  }

  closeQuittanceModal() {
    this.quittanceModalOpen.set(false);
    this.quittanceDetails.set(null);
  }

  printQuittance() {
    window.print();
  }

  // ── Libellés ────────────────────────────────────────────────────────────────

  /**
   * Libellé du bien, lu sur le bail lui-même.
   *
   * L'API joint désormais le bien et le locataire à chaque bail. Avant, cet
   * écran devait charger l'intégralité du patrimoine pour afficher un nom :
   * tous les portefeuilles, tous les locataires, puis les biens de chaque
   * portefeuille — sept appels HTTP sur un parc de cinq portefeuilles, tous
   * effectués avant le premier affichage.
   *
   * Le repli sur la liste chargée pour le formulaire couvre le cas d'un bail
   * créé à l'instant, pas encore rechargé depuis le serveur.
   */
  resolvePropertyLabel(lease: Lease): string {
    if (lease.property) {
      return lease.property.city
        ? `${lease.property.title} — ${lease.property.city}`
        : lease.property.title;
    }

    return (
      this.properties().find((property) => property.value === lease.property_id)?.label ||
      `Bien #${lease.property_id}`
    );
  }

  resolveTenantLabel(lease: Lease): string {
    if (lease.tenant) {
      return `${lease.tenant.first_name} ${lease.tenant.last_name}`.trim();
    }

    return (
      this.tenants().find((tenant) => tenant.value === lease.tenant_id)?.label ||
      `Locataire #${lease.tenant_id}`
    );
  }

  resolveLeaseTypeLabel(type: string): string {
    return this.leaseTypes.find((t) => t.value === type)?.label || type;
  }

  leaseStatusLabel(statut: string): string {
    return this.statusOptions.find((s) => s.value === statut)?.label ?? statut;
  }

  leaseStatusTone(statut: string): 'success' | 'danger' | 'info' {
    return (
      { actif: 'success' as const, termine: 'danger' as const, en_attente: 'info' as const }[
        statut
      ] ?? 'info'
    );
  }

  paymentStatusLabel(status: string): string {
    return status === 'paye' ? 'Payé' : status === 'en_retard' ? 'En retard' : 'En attente';
  }

  paymentStatusTone(status: string): 'success' | 'danger' | 'warning' {
    return (
      { paye: 'success' as const, en_retard: 'danger' as const, en_attente: 'warning' as const }[
        status
      ] ?? 'warning'
    );
  }

  /** Montant formaté en euros, sans décimales superflues. */
  euros(amount: number | string | null | undefined): string {
    const value = Number(amount ?? 0);

    return value.toLocaleString('fr-FR', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: value % 1 === 0 ? 0 : 2,
    });
  }

  /*
   * Les champs du bail n'ont plus de getter dédié : `fieldError()` les couvre
   * tous, en donnant priorité au message du serveur sur le message générique du
   * formulaire.
   */
  get period() {
    return this.paymentForm.get('period');
  }

  get generateSchedule() {
    return this.leaseForm.get('generate_schedule');
  }

  get modalTitle() {
    return this.editingLease() ? 'Modifier le bail' : 'Nouveau bail';
  }

  get submitLabel() {
    return this.editingLease() ? 'Enregistrer les changements' : 'Créer le bail';
  }
}
