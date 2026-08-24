import { Component, computed, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { EMPTY, catchError, switchMap, tap } from 'rxjs';
import { PortfolioService, Portfolio } from '../../core/services/portfolio.service';
import { PaginatedResponse } from '../../core/list/pagination.model';
import { createListQuery } from '../../core/list/list-query';
import { ConfirmService } from '../../core/services/confirm.service';
import { NotificationService } from '../../core/services/notification.service';
import {
  ImmoproCardComponent,
  ImmoproButtonComponent,
  ImmoproInputComponent,
  ImmoproPageHeaderComponent,
  ImmoproIconButtonComponent,
  ImmoproFilterBarComponent,
  ImmoproPaginationComponent,
  ImmoproSkeletonComponent,
} from 'ui-lib';

@Component({
  selector: 'app-portfolios',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    ImmoproCardComponent,
    ImmoproButtonComponent,
    ImmoproInputComponent,
    ImmoproPageHeaderComponent,
    ImmoproIconButtonComponent,
    ImmoproFilterBarComponent,
    ImmoproPaginationComponent,
    ImmoproSkeletonComponent,
  ],
  templateUrl: './portfolios.component.html',
  styleUrl: './portfolios.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PortfoliosComponent {
  private fb = inject(FormBuilder);
  private router = inject(Router);
  private portfolioService = inject(PortfolioService);
  private confirm = inject(ConfirmService);
  private notifications = inject(NotificationService);

  protected readonly list = createListQuery({ defaultSort: 'name', defaultDirection: 'asc' });

  portfolios = signal<Portfolio[]>([]);
  pagination = signal<PaginatedResponse<Portfolio> | undefined>(undefined);
  listLoading = signal(false);

  /** Passe à faux dès la première réponse reçue, même vide. */
  private readonly firstLoadDone = signal(false);

  /**
   * Squelette réservé au tout premier affichage.
   *
   * Sur un changement de filtre, la liste précédente reste à l'écran et se
   * contente de pâlir : substituer des blocs gris à un contenu déjà lisible à
   * chaque frappe donnerait une impression de clignotement, et ferait sauter la
   * hauteur de la page.
   */
  protected readonly showSkeleton = computed(() => !this.firstLoadDone() && this.listLoading());

  /** Nombre de cartes fantômes, choisi pour remplir une grille sans excès. */
  protected readonly skeletonCards = Array.from({ length: 6 });
  createForm: FormGroup;

  createModalOpen = signal(false);
  editingPortfolio = signal<Portfolio | null>(null);
  loading = signal(false);
  deletingId = signal<number | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);

  constructor() {
    this.createForm = this.fb.group({
      name: ['', [Validators.required, Validators.minLength(2)]],
      description: [''],
    });

    toObservable(this.list.trigger)
      .pipe(
        tap(() => this.listLoading.set(true)),
        switchMap(({ params }) =>
          this.portfolioService.getPortfolios(params).pipe(
            catchError(() => {
              this.error.set('Impossible de charger les portfolios');
              this.listLoading.set(false);
              return EMPTY;
            }),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((response) => {
        this.portfolios.set(response.data);
        this.pagination.set(response);
        this.listLoading.set(false);
        this.firstLoadDone.set(true);
      });
  }

  addPortfolio() {
    this.editingPortfolio.set(null);
    this.error.set(null);
    this.submitted.set(false);
    this.createForm.reset({ name: '', description: '' });
    this.createModalOpen.set(true);
  }

  editPortfolio(portfolio: Portfolio, event: MouseEvent) {
    event.stopPropagation();
    this.editingPortfolio.set(portfolio);
    this.error.set(null);
    this.submitted.set(false);
    this.createForm.reset({ name: portfolio.name, description: portfolio.description ?? '' });
    this.createModalOpen.set(true);
  }

  async deletePortfolio(portfolio: Portfolio, event: MouseEvent) {
    event.stopPropagation();

    const count = portfolio.properties_count ?? 0;

    const confirmed = await this.confirm.ask({
      title: `Supprimer « ${portfolio.name} » ?`,
      message:
        count > 0
          ? `Ce portefeuille contient ${count} bien${count > 1 ? 's' : ''}. Les supprimer emporte aussi leurs baux, leurs échéances et leurs quittances.`
          : 'Ce portefeuille ne contient aucun bien. La suppression est sans effet sur le reste de votre parc.',
      confirmLabel: 'Supprimer le portefeuille',
      danger: true,
      // Un portefeuille peuplé emporte trop de choses pour se supprimer d'un
      // clic distrait : on demande de recopier son nom.
      typeToConfirm: count > 0 ? portfolio.name : undefined,
    });

    if (!confirmed) {
      return;
    }

    const previousPortfolios = this.portfolios();
    this.portfolios.set(previousPortfolios.filter((p) => p.id !== portfolio.id));
    this.deletingId.set(portfolio.id);

    this.portfolioService.deletePortfolio(portfolio.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.notifications.success(`« ${portfolio.name} » a été supprimé.`);
      },
      error: (error: unknown) => {
        this.deletingId.set(null);
        this.notifications.fromHttp(error, 'La suppression du portefeuille a échoué.');
        this.portfolios.set(previousPortfolios);
        this.error.set('Impossible de supprimer ce portefeuille');
      },
    });
  }

  viewPortfolio(portfolio: Portfolio) {
    this.router.navigate(['/portfolios', portfolio.id]);
  }

  closeCreateModal() {
    if (this.loading()) {
      return;
    }
    this.createModalOpen.set(false);
    this.editingPortfolio.set(null);
  }

  submitPortfolio() {
    this.submitted.set(true);
    this.error.set(null);

    if (this.createForm.invalid) {
      return;
    }

    this.loading.set(true);
    const payload = this.createForm.value;
    const editing = this.editingPortfolio();
    const previousPortfolios = this.portfolios();

    if (editing) {
      const updated: Portfolio = { ...editing, ...payload };
      this.portfolios.set(previousPortfolios.map((p) => (p.id === editing.id ? updated : p)));
      this.createModalOpen.set(false);

      this.portfolioService.updatePortfolio(editing.id, payload).subscribe({
        next: (savedPortfolio) => {
          this.loading.set(false);
          this.portfolios.set(
            this.portfolios().map((p) => (p.id === editing.id ? savedPortfolio : p)),
          );
          this.editingPortfolio.set(null);
        },
        error: (response) => {
          this.loading.set(false);
          this.createModalOpen.set(true);
          this.portfolios.set(previousPortfolios);
          this.error.set(response.error?.message || 'Erreur lors de la modification du portfolio');
        },
      });
      return;
    }

    const tempId = -Date.now();
    const tempPortfolio: Portfolio = { id: tempId, ...payload, properties_count: 0 };

    // Optimistic UI insert
    this.portfolios.set([...previousPortfolios, tempPortfolio]);
    this.createModalOpen.set(false);

    this.portfolioService.createPortfolio(payload).subscribe({
      next: (savedPortfolio) => {
        this.loading.set(false);
        // Replace temp with saved portfolio
        this.portfolios.set(this.portfolios().map((p) => (p.id === tempId ? savedPortfolio : p)));
        this.createForm.reset({ name: '', description: '' });
        this.submitted.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.createModalOpen.set(true); // reopen
        this.portfolios.set(previousPortfolios); // rollback
        this.error.set(response.error?.message || 'Erreur lors de la création du portfolio');
      },
    });
  }

  get name() {
    return this.createForm.get('name');
  }

  get description() {
    return this.createForm.get('description');
  }

  get modalTitle() {
    return this.editingPortfolio() ? 'Modifier le Portfolio' : 'Nouveau Portfolio';
  }

  get submitLabel() {
    return this.editingPortfolio() ? 'Enregistrer les modifications' : 'Créer le portfolio';
  }
}
