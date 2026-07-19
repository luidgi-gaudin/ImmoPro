import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { Router } from '@angular/router';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { PortfolioService, Portfolio } from '../../core/services/portfolio.service';
import { ImmoproCardComponent, ImmoproButtonComponent, ImmoproInputComponent, ImmoproPageHeaderComponent } from 'ui-lib';

@Component({
  selector: 'app-portfolios',
  standalone: true,
  imports: [ReactiveFormsModule, ImmoproCardComponent, ImmoproButtonComponent, ImmoproInputComponent, ImmoproPageHeaderComponent],
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
  loading = signal(false);
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
    this.error.set(null);
    this.submitted.set(false);
    this.createForm.reset({ name: '', description: '' });
    this.createModalOpen.set(true);
  }

  viewPortfolio(portfolio: Portfolio) {
    this.router.navigate(['/portfolios', portfolio.id]);
  }

  closeCreateModal() {
    if (this.loading()) {
      return;
    }
    this.createModalOpen.set(false);
  }

  submitPortfolio() {
    this.submitted.set(true);
    this.error.set(null);

    if (this.createForm.invalid) {
      return;
    }

    this.loading.set(true);
    const payload = this.createForm.value;
    const previousPortfolios = this.portfolios();

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
}
