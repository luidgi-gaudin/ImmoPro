import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ImmoproButtonComponent, ImmoproCardComponent, ImmoproInputComponent } from 'ui-lib';
import { CreatePropertyPayload, Portfolio, PortfolioService, Property } from '../../core/services/portfolio.service';

@Component({
  selector: 'app-portfolio-details',
  standalone: true,
  imports: [ReactiveFormsModule, ImmoproButtonComponent, ImmoproCardComponent, ImmoproInputComponent],
  templateUrl: './portfolio-details.component.html',
  styleUrl: './portfolio-details.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
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
  
  createModalOpen = signal(false);
  editingProperty = signal<Property | null>(null);
  loading = signal(false);
  propertiesLoading = signal(false);
  saving = signal(false);
  deletingId = signal<number | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);

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
      this.error.set('Portfolio introuvable');
      return;
    }

    this.loading.set(true);
    this.portfolioService.getPortfolio(this.portfolioId).subscribe({
      next: (portfolio) => {
        this.portfolio.set({
          ...portfolio,
          properties: [],
          properties_count: 0,
        });
        this.loading.set(false);
        this.propertiesLoading.set(true);

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
            this.propertiesLoading.set(false);
          },
          error: () => {
            this.error.set('Impossible de charger la liste des actifs');
            this.propertiesLoading.set(false);
          },
        });
      },
      error: () => {
        this.error.set('Impossible de charger ce portfolio');
        this.loading.set(false);
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

    const currentPortfolio = this.portfolio();
    if (!currentPortfolio) return;

    const previousProperties = currentPortfolio.properties || [];
    const updatedProperties = previousProperties.filter(p => p.id !== property.id);

    // Optimistic UI Deletion
    this.portfolio.set({
      ...currentPortfolio,
      properties: updatedProperties,
      properties_count: updatedProperties.length
    });
    this.deletingId.set(property.id);
    this.error.set(null);

    this.portfolioService.deleteProperty(this.portfolioId, property.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.reloadDataBackground();
      },
      error: () => {
        this.deletingId.set(null);
        // Rollback
        this.portfolio.set(currentPortfolio);
        this.error.set('Impossible de supprimer cet actif');
      },
    });
  }

  openPropertyModal(property: Property | null = null) {
    this.error.set(null);
    this.submitted.set(false);
    this.editingProperty.set(property);

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

    this.createModalOpen.set(true);
  }

  closePropertyModal() {
    if (this.saving()) {
      return;
    }
    this.createModalOpen.set(false);
    this.editingProperty.set(null);
  }

  submitProperty() {
    this.submitted.set(true);
    this.error.set(null);

    if (this.propertyForm.invalid) {
      return;
    }

    this.saving.set(true);
    const payload = this.propertyForm.value as CreatePropertyPayload;
    const isEdit = !!this.editingProperty();
    const currentPortfolio = this.portfolio();
    if (!currentPortfolio) return;

    const previousProperties = currentPortfolio.properties || [];

    if (isEdit) {
      const editId = this.editingProperty()!.id;
      const updatedProp: Property = {
        ...this.editingProperty()!,
        ...payload,
        has_balcony: payload.has_balcony || false,
        has_garden: payload.has_garden || false,
        has_parking: payload.has_parking || false,
        has_cave: payload.has_cave || false,
        is_rented: payload.is_rented || false,
        rooms: payload.rooms || null,
        area_sqm: payload.area_sqm || null,
        monthly_rent: payload.monthly_rent || null,
        description: payload.description || null
      };
      const updatedList = previousProperties.map(p => p.id === editId ? updatedProp : p);

      // Optimistic UI Update
      this.portfolio.set({ ...currentPortfolio, properties: updatedList });
      this.createModalOpen.set(false);

      this.portfolioService.updateProperty(this.portfolioId, editId, payload).subscribe({
        next: (savedProp) => {
          this.saving.set(false);
          this.portfolio.set({
            ...currentPortfolio,
            properties: this.portfolio()!.properties!.map(p => p.id === editId ? savedProp : p)
          });
          this.editingProperty.set(null);
          this.reloadDataBackground();
        },
        error: (err) => {
          this.saving.set(false);
          this.createModalOpen.set(true); // reopen
          this.portfolio.set(currentPortfolio); // rollback
          this.error.set(err.error?.message || 'Impossible de sauvegarder cet actif');
        }
      });
    } else {
      // Optimistic UI Creation with temp ID
      const tempId = -Date.now();
      const tempProp: Property = {
        id: tempId,
        ...payload,
        has_balcony: payload.has_balcony || false,
        has_garden: payload.has_garden || false,
        has_parking: payload.has_parking || false,
        has_cave: payload.has_cave || false,
        is_rented: payload.is_rented || false,
        rooms: payload.rooms || null,
        area_sqm: payload.area_sqm || null,
        monthly_rent: payload.monthly_rent || null,
        description: payload.description || null
      };
      
      const updatedList = [tempProp, ...previousProperties];
      this.portfolio.set({
        ...currentPortfolio,
        properties: updatedList,
        properties_count: updatedList.length
      });
      this.createModalOpen.set(false);

      this.portfolioService.createProperty(this.portfolioId, payload).subscribe({
        next: (savedProp) => {
          this.saving.set(false);
          this.portfolio.set({
            ...currentPortfolio,
            properties: this.portfolio()!.properties!.map(p => p.id === tempId ? savedProp : p),
            properties_count: this.portfolio()!.properties!.length
          });
          this.propertyForm.reset();
          this.submitted.set(false);
          this.reloadDataBackground();
        },
        error: (err) => {
          this.saving.set(false);
          this.createModalOpen.set(true); // reopen
          this.portfolio.set(currentPortfolio); // rollback
          this.error.set(err.error?.message || 'Impossible de sauvegarder cet actif');
        }
      });
    }
  }

  reloadDataBackground() {
    this.portfolioService.getPortfolio(this.portfolioId).subscribe({
      next: (portfolio) => {
        this.portfolioService.getPortfolioProperties(this.portfolioId).subscribe({
          next: (properties) => {
            this.portfolio.set({
              ...portfolio,
              properties,
              properties_count: properties.length
            });
          }
        });
      }
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
    return this.editingProperty() ? 'Modifier un actif' : 'Créer un actif';
  }

  get submitLabel() {
    return this.editingProperty() ? 'Enregistrer' : "Créer l'actif";
  }
}