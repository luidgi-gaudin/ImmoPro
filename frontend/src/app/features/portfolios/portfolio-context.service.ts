import { Injectable, inject, signal, computed } from '@angular/core';
import {
  CreatePropertyPayload,
  Portfolio,
  PortfolioService,
  Property,
} from '../../core/services/portfolio.service';

/**
 * État partagé par le shell d'un portefeuille et ses sous-pages (Vue d'ensemble,
 * Actifs, détail d'un actif). Fourni au niveau du composant shell : une instance
 * fraîche est créée à chaque entrée dans /portfolios/:id et détruite en sortant.
 */
@Injectable()
export class PortfolioContextService {
  private portfolioService = inject(PortfolioService);

  portfolioId = signal<number | null>(null);
  portfolio = signal<Portfolio | null>(null);
  properties = computed(() => this.portfolio()?.properties ?? []);

  loading = signal(false);
  propertiesLoading = signal(false);
  error = signal<string | null>(null);
  deletingId = signal<number | null>(null);

  readonly stats = computed(() => {
    const props = this.properties();
    const rented = props.filter((p) => p.is_rented).length;
    return {
      total: props.length,
      rented,
      available: props.length - rented,
      monthlyRent: props.reduce((sum, p) => sum + (p.monthly_rent ?? 0), 0),
    };
  });

  load(id: number): void {
    this.portfolioId.set(id);
    this.portfolio.set(null);
    this.error.set(null);
    this.loading.set(true);

    this.portfolioService.getPortfolio(id).subscribe({
      next: (portfolio) => {
        this.portfolio.set({ ...portfolio, properties: [], properties_count: 0 });
        this.loading.set(false);
        this.loadProperties(id);
      },
      error: () => {
        this.error.set('Impossible de charger ce portfolio');
        this.loading.set(false);
      },
    });
  }

  private loadProperties(id: number): void {
    this.propertiesLoading.set(true);
    this.portfolioService.getPortfolioProperties(id).subscribe({
      next: (properties) => {
        this.portfolio.update((current) =>
          current ? { ...current, properties, properties_count: properties.length } : current
        );
        this.propertiesLoading.set(false);
      },
      error: () => {
        this.error.set('Impossible de charger la liste des actifs');
        this.propertiesLoading.set(false);
      },
    });
  }

  reloadBackground(): void {
    const id = this.portfolioId();
    if (!id) return;

    this.portfolioService.getPortfolio(id).subscribe({
      next: (portfolio) => {
        this.portfolioService.getPortfolioProperties(id).subscribe({
          next: (properties) => {
            this.portfolio.set({ ...portfolio, properties, properties_count: properties.length });
          },
        });
      },
    });
  }

  findProperty(propertyId: number): Property | undefined {
    return this.properties().find((p) => p.id === propertyId);
  }

  createProperty(payload: CreatePropertyPayload) {
    const id = this.portfolioId();
    if (!id) throw new Error('Aucun portfolio actif');
    return this.portfolioService.createProperty(id, payload);
  }

  updateProperty(propertyId: number, payload: CreatePropertyPayload) {
    const id = this.portfolioId();
    if (!id) throw new Error('Aucun portfolio actif');
    return this.portfolioService.updateProperty(id, propertyId, payload);
  }

  setProperties(properties: Property[]): void {
    this.portfolio.update((current) =>
      current ? { ...current, properties, properties_count: properties.length } : current
    );
  }

  deleteProperty(property: Property): void {
    const currentPortfolio = this.portfolio();
    const id = this.portfolioId();
    if (!currentPortfolio || !id) return;

    const previousProperties = currentPortfolio.properties ?? [];
    const updatedProperties = previousProperties.filter((p) => p.id !== property.id);

    this.portfolio.set({
      ...currentPortfolio,
      properties: updatedProperties,
      properties_count: updatedProperties.length,
    });
    this.deletingId.set(property.id);
    this.error.set(null);

    this.portfolioService.deleteProperty(id, property.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.reloadBackground();
      },
      error: () => {
        this.deletingId.set(null);
        this.portfolio.set(currentPortfolio);
        this.error.set("Impossible de supprimer cet actif");
      },
    });
  }
}
