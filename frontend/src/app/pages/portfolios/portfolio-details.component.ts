import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ImmoproButtonComponent, ImmoproCardComponent, ImmoproInputComponent } from 'ui-lib';
import { CreatePropertyPayload, Portfolio, PortfolioService, Property } from '../../services/portfolio.service';

@Component({
  selector: 'app-portfolio-details',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ImmoproButtonComponent, ImmoproCardComponent, ImmoproInputComponent],
  templateUrl: './portfolio-details.component.html',
  styleUrl: './portfolio-details.component.scss',
})
export class PortfolioDetailsComponent implements OnInit {
  private fb = inject(FormBuilder);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private portfolioService = inject(PortfolioService);

  portfolio = signal<Portfolio | null>(null);
  portfolioId = 0;
  propertyTypes = [
    { label: 'Appartement', value: 'appartement' },
    { label: 'Maison', value: 'maison' },
    { label: 'Terrain', value: 'terrain' },
  ];
  dpeValues = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
  propertyForm: FormGroup;
  createModalOpen = false;
  editingProperty: Property | null = null;
  loading = false;
  propertiesLoading = false;
  saving = false;
  deletingId: number | null = null;
  error: string | null = null;
  submitted = false;

  constructor() {
    this.propertyForm = this.fb.group({
      title: ['', [Validators.required, Validators.minLength(2)]],
      property_type: ['', [Validators.required]],
      address: ['', [Validators.required]],
      city: ['', [Validators.required]],
      postal_code: ['', [Validators.required]],
      dpe: ['', [Validators.required]],
      rooms: [null],
      area_sqm: [null],
      has_balcony: [false],
      has_garden: [false],
      has_parking: [false],
      has_cave: [false],
      is_rented: [false],
      monthly_rent: [null],
      description: [''],
    });
  }

  ngOnInit() {
    this.portfolioId = Number(this.route.snapshot.paramMap.get('id'));

    if (!this.portfolioId) {
      this.error = 'Portfolio introuvable';
      return;
    }

    this.loading = true;
    this.portfolioService.getPortfolio(this.portfolioId).subscribe({
      next: (portfolio) => {
        this.portfolio.set({
          ...portfolio,
          properties: [],
          properties_count: 0,
        });
        this.loading = false;
        this.propertiesLoading = true;

        this.portfolioService.getPortfolioProperties(this.portfolioId).subscribe({
          next: (properties) => {
            this.portfolio.update((currentPortfolio) =>
              currentPortfolio
                ? {
                    ...currentPortfolio,
                    properties,
                    properties_count: properties.length,
                  }
                : currentPortfolio
            );
            this.propertiesLoading = false;
          },
          error: () => {
            this.error = 'Impossible de charger la liste des actifs';
            this.propertiesLoading = false;
          },
        });
      },
      error: () => {
        this.error = 'Impossible de charger ce portfolio';
        this.loading = false;
      },
    });
  }

  addProperty() {
    this.openPropertyModal();
  }

  editProperty(property: Property) {
    this.openPropertyModal(property);
  }

  deleteProperty(property: Property) {
    const confirmed = window.confirm(`Supprimer l'actif "${property.title}" ?`);

    if (!confirmed) {
      return;
    }

    this.deletingId = property.id;
    this.portfolioService.deleteProperty(this.portfolioId, property.id).subscribe({
      next: () => {
        this.deletingId = null;
        this.reloadData();
      },
      error: () => {
        this.deletingId = null;
        this.error = 'Impossible de supprimer cet actif';
      },
    });
  }

  openPropertyModal(property: Property | null = null) {
    this.error = null;
    this.submitted = false;
    this.editingProperty = property;

    this.propertyForm.reset({
      title: property?.title ?? '',
      property_type: property?.property_type ?? '',
      address: property?.address ?? '',
      city: property?.city ?? '',
      postal_code: property?.postal_code ?? '',
      dpe: property?.dpe ?? '',
      rooms: property?.rooms ?? null,
      area_sqm: property?.area_sqm ?? null,
      has_balcony: property?.has_balcony ?? false,
      has_garden: property?.has_garden ?? false,
      has_parking: property?.has_parking ?? false,
      has_cave: property?.has_cave ?? false,
      is_rented: property?.is_rented ?? false,
      monthly_rent: property?.monthly_rent ?? null,
      description: property?.description ?? '',
    });

    this.createModalOpen = true;
  }

  closePropertyModal() {
    if (this.saving) {
      return;
    }

    this.createModalOpen = false;
    this.editingProperty = null;
  }

  submitProperty() {
    this.submitted = true;
    this.error = null;

    if (this.propertyForm.invalid) {
      return;
    }

    this.saving = true;
    const payload = this.propertyForm.value as CreatePropertyPayload;
    const request = this.editingProperty
      ? this.portfolioService.updateProperty(this.portfolioId, this.editingProperty.id, payload)
      : this.portfolioService.createProperty(this.portfolioId, payload);

    request.subscribe({
      next: () => {
        this.saving = false;
        this.createModalOpen = false;
        this.editingProperty = null;
        this.propertyForm.reset({
          title: '',
          property_type: '',
          address: '',
          city: '',
          postal_code: '',
          dpe: '',
          rooms: null,
          area_sqm: null,
          has_balcony: false,
          has_garden: false,
          has_parking: false,
          has_cave: false,
          is_rented: false,
          monthly_rent: null,
          description: '',
        });
        this.reloadData();
      },
      error: (response) => {
        this.saving = false;
        if (response.error?.errors) {
          this.error = Object.values(response.error.errors).flat().join(', ');
        } else {
          this.error = response.error?.message || 'Impossible de sauvegarder cet actif';
        }
      },
    });
  }

  reloadData() {
    this.loading = true;
    this.propertiesLoading = false;
    this.portfolioService.getPortfolio(this.portfolioId).subscribe({
      next: (portfolio) => {
        this.portfolio.set({
          ...portfolio,
          properties: [],
          properties_count: 0,
        });
        this.loading = false;
        this.propertiesLoading = true;

        this.portfolioService.getPortfolioProperties(this.portfolioId).subscribe({
          next: (properties) => {
            this.portfolio.update((currentPortfolio) =>
              currentPortfolio
                ? {
                    ...currentPortfolio,
                    properties,
                    properties_count: properties.length,
                  }
                : currentPortfolio
            );
            this.propertiesLoading = false;
          },
          error: () => {
            this.error = 'Impossible de charger la liste des actifs';
            this.propertiesLoading = false;
          },
        });
      },
      error: () => {
        this.error = 'Impossible de charger ce portfolio';
        this.loading = false;
      },
    });
  }

  get title() {
    return this.propertyForm.get('title');
  }

  get propertyType() {
    return this.propertyForm.get('property_type');
  }

  get address() {
    return this.propertyForm.get('address');
  }

  get city() {
    return this.propertyForm.get('city');
  }

  get postalCode() {
    return this.propertyForm.get('postal_code');
  }

  get dpe() {
    return this.propertyForm.get('dpe');
  }

  goBack() {
    this.router.navigate(['/portfolios']);
  }

  get modalTitle() {
    return this.editingProperty ? 'Modifier un actif' : 'Créer un actif';
  }

  get submitLabel() {
    return this.editingProperty ? 'Enregistrer' : "Créer l'actif";
  }
}