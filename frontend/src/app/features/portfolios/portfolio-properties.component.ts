import { Component, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  ImmoproButtonComponent,
  ImmoproInputComponent,
  ImmoproTableComponent,
  ImmoproAvatarComponent,
  ImmoproIconButtonComponent,
  ImmoproBadgeComponent,
  ImmoproDpeBadgeComponent,
  ImmoproSelectComponent,
} from 'ui-lib';
import { CreatePropertyPayload, Property } from '../../core/services/portfolio.service';
import { PortfolioContextService } from './portfolio-context.service';

@Component({
  selector: 'app-portfolio-properties',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    ImmoproButtonComponent,
    ImmoproInputComponent,
    ImmoproTableComponent,
    ImmoproAvatarComponent,
    ImmoproIconButtonComponent,
    ImmoproBadgeComponent,
    ImmoproDpeBadgeComponent,
    ImmoproSelectComponent,
  ],
  templateUrl: './portfolio-properties.component.html',
  styleUrl: './portfolio-properties.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PortfolioPropertiesComponent {
  private fb = inject(FormBuilder);
  protected ctx = inject(PortfolioContextService);

  propertyTypes = [
    { label: 'Appartement', value: 'appartement' },
    { label: 'Maison', value: 'maison' },
    { label: 'Terrain', value: 'terrain' },
  ];
  dpeValues = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
  propertyForm: FormGroup;

  createModalOpen = signal(false);
  editingProperty = signal<Property | null>(null);
  saving = signal(false);
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

  addProperty() {
    this.openPropertyModal();
  }

  editProperty(property: Property) {
    this.openPropertyModal(property);
  }

  deleteProperty(property: Property) {
    const confirmed = window.confirm(`Supprimer l'actif "${property.title}" ?`);
    if (!confirmed) return;
    this.ctx.deleteProperty(property);
  }

  openPropertyModal(property: Property | null = null) {
    this.ctx.error.set(null);
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
    if (this.saving()) return;
    this.createModalOpen.set(false);
    this.editingProperty.set(null);
  }

  submitProperty() {
    this.submitted.set(true);
    this.ctx.error.set(null);

    if (this.propertyForm.invalid) return;

    this.saving.set(true);
    const payload = this.propertyForm.value as CreatePropertyPayload;
    const isEdit = !!this.editingProperty();
    const previousProperties = this.ctx.properties();

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
        description: payload.description || null,
      };

      this.ctx.setProperties(previousProperties.map((p) => (p.id === editId ? updatedProp : p)));
      this.createModalOpen.set(false);

      this.ctx.updateProperty(editId, payload).subscribe({
        next: (savedProp) => {
          this.saving.set(false);
          this.ctx.setProperties(this.ctx.properties().map((p) => (p.id === editId ? savedProp : p)));
          this.editingProperty.set(null);
          this.ctx.reloadBackground();
        },
        error: (err) => {
          this.saving.set(false);
          this.createModalOpen.set(true);
          this.ctx.setProperties(previousProperties);
          this.ctx.error.set(err.error?.message || 'Impossible de sauvegarder cet actif');
        },
      });
    } else {
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
        description: payload.description || null,
      };

      this.ctx.setProperties([tempProp, ...previousProperties]);
      this.createModalOpen.set(false);

      this.ctx.createProperty(payload).subscribe({
        next: (savedProp) => {
          this.saving.set(false);
          this.ctx.setProperties(this.ctx.properties().map((p) => (p.id === tempId ? savedProp : p)));
          this.propertyForm.reset();
          this.submitted.set(false);
          this.ctx.reloadBackground();
        },
        error: (err) => {
          this.saving.set(false);
          this.createModalOpen.set(true);
          this.ctx.setProperties(previousProperties);
          this.ctx.error.set(err.error?.message || 'Impossible de sauvegarder cet actif');
        },
      });
    }
  }

  get title() { return this.propertyForm.get('title'); }
  get propertyType() { return this.propertyForm.get('property_type'); }
  get address() { return this.propertyForm.get('address'); }
  get city() { return this.propertyForm.get('city'); }
  get postalCode() { return this.propertyForm.get('postal_code'); }
  get dpe() { return this.propertyForm.get('dpe'); }

  get modalTitle() {
    return this.editingProperty() ? 'Modifier un actif' : 'Créer un actif';
  }

  get submitLabel() {
    return this.editingProperty() ? 'Enregistrer' : "Créer l'actif";
  }
}
