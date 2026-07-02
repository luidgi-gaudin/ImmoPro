import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { PortfolioService, Portfolio } from '../../services/portfolio.service';
import { ImmoproCardComponent, ImmoproButtonComponent, ImmoproInputComponent } from 'ui-lib';

@Component({
  selector: 'app-portfolios',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ImmoproCardComponent, ImmoproButtonComponent, ImmoproInputComponent],
  templateUrl: './portfolios.component.html',
  styleUrl: './portfolios.component.scss'
})
export class PortfoliosComponent implements OnInit {
  private fb = inject(FormBuilder);
  private router = inject(Router);
  private portfolioService = inject(PortfolioService);
  portfolios = signal<Portfolio[]>([]);
  createForm: FormGroup;
  createModalOpen = false;
  loading = false;
  error: string | null = null;
  submitted = false;

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
    this.portfolioService.getPortfolios().subscribe(data => {
      this.portfolios.set(data);
    });
  }

  addPortfolio() {
    this.error = null;
    this.submitted = false;
    this.createForm.reset({ name: '', description: '' });
    this.createModalOpen = true;
  }

  viewPortfolio(portfolio: Portfolio) {
    this.router.navigate(['/portfolios', portfolio.id]);
  }

  closeCreateModal() {
    if (this.loading) {
      return;
    }

    this.createModalOpen = false;
  }

  submitPortfolio() {
    this.submitted = true;
    this.error = null;

    if (this.createForm.invalid) {
      return;
    }

    this.loading = true;

    this.portfolioService.createPortfolio(this.createForm.value).subscribe({
      next: () => {
        this.loading = false;
        this.createModalOpen = false;
        this.createForm.reset({ name: '', description: '' });
        this.loadPortfolios();
      },
      error: (response) => {
        this.loading = false;
        if (response.error?.errors) {
          this.error = Object.values(response.error.errors).flat().join(', ');
        } else {
          this.error = response.error?.message || 'Erreur lors de la création du portfolio';
        }
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
