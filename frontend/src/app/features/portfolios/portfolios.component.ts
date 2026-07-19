import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { Router } from '@angular/router';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { PortfolioService, Portfolio } from '../../core/services/portfolio.service';
import { ImmoproCardComponent, ImmoproButtonComponent, ImmoproInputComponent, ImmoproPageHeaderComponent, ImmoproIconButtonComponent } from 'ui-lib';

@Component({
  selector: 'app-portfolios',
  standalone: true,
  imports: [ReactiveFormsModule, ImmoproCardComponent, ImmoproButtonComponent, ImmoproInputComponent, ImmoproPageHeaderComponent, ImmoproIconButtonComponent],
  templateUrl: './portfolios.component.html',
  styleUrl: './portfolios.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PortfoliosComponent implements OnInit {
  private fb = inject(FormBuilder);
  private router = inject(Router);
  private portfolioService = inject(PortfolioService);

  portfolios = signal<Portfolio[]>([]);
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
  }

  ngOnInit() {
    this.loadPortfolios();
  }

  loadPortfolios() {
    this.portfolioService.getPortfolios().subscribe({
      next: (data) => {
        this.portfolios.set(data);
      },
      error: () => {
        this.error.set('Impossible de charger les portfolios');
      }
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

  deletePortfolio(portfolio: Portfolio, event: MouseEvent) {
    event.stopPropagation();
    const confirmed = window.confirm(`Supprimer définitivement le portefeuille "${portfolio.name}" et tous ses actifs ?`);
    if (!confirmed) return;

    const previousPortfolios = this.portfolios();
    this.portfolios.set(previousPortfolios.filter((p) => p.id !== portfolio.id));
    this.deletingId.set(portfolio.id);

    this.portfolioService.deletePortfolio(portfolio.id).subscribe({
      next: () => {
        this.deletingId.set(null);
      },
      error: () => {
        this.deletingId.set(null);
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
          this.portfolios.set(this.portfolios().map((p) => (p.id === editing.id ? savedPortfolio : p)));
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
        this.portfolios.set(this.portfolios().map(p => p.id === tempId ? savedPortfolio : p));
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
