import { Injectable, computed, inject, signal } from '@angular/core';
import {
  CreatePropertyPayload,
  Portfolio,
  PortfolioService,
  Property,
} from '../../core/services/portfolio.service';

/**
 * État partagé par le shell d'un portefeuille et ses sous-pages (Vue d'ensemble,
 * Actifs, détail d'un actif). Fourni au niveau du shell : une instance fraîche
 * naît à chaque entrée dans /portfolios/:id et meurt en sortant.
 *
 * Le portefeuille n'est **jamais** chargé pour lui-même quand une sous-page le
 * ramène déjà. La liste de ses biens, comme la fiche d'un bien, le rapportent
 * avec ses compteurs — le serveur a dû le charger de toute façon pour vérifier
 * le droit d'accès. Ces écrans appellent donc `adopt()`, et seuls ceux qui
 * n'ont rien d'autre à demander appellent `ensureLoaded()`.
 *
 * C'est ce qui fait tenir l'écran des actifs en un seul appel HTTP là où il en
 * demandait trois : la fiche du portefeuille, l'intégralité de ses biens pour
 * en calculer les statistiques, puis la page à afficher.
 */
@Injectable()
export class PortfolioContextService {
  private portfolioService = inject(PortfolioService);

  portfolioId = signal<number | null>(null);
  portfolio = signal<Portfolio | null>(null);

  loading = signal(false);
  error = signal<string | null>(null);
  deletingId = signal<number | null>(null);

  /**
   * Compteurs calculés par la base, sur l'ensemble du portefeuille.
   *
   * Ils l'étaient auparavant en mémoire, à partir de la liste complète des
   * biens : cela obligeait à tout rapatrier pour afficher trois chiffres, et le
   * total devenait faux dès que le parc dépassait la taille d'une page.
   */
  readonly stats = computed(() => {
    const portfolio = this.portfolio();

    return {
      total: portfolio?.properties_count ?? 0,
      rented: portfolio?.occupied_properties_count ?? 0,
      available: portfolio?.vacant_properties_count ?? 0,
      monthlyRent: portfolio?.expected_rent ?? 0,
    };
  });

  /** Prend en compte le portefeuille rapporté par une autre requête. */
  adopt(portfolio: Portfolio | null | undefined): void {
    if (!portfolio) {
      return;
    }

    this.portfolio.set(portfolio);
    this.portfolioId.set(portfolio.id);
    this.loading.set(false);
  }

  /** Charge le portefeuille seul, pour les écrans qui n'ont rien d'autre à demander. */
  ensureLoaded(): void {
    const id = this.portfolioId();

    if (id === null || this.portfolio()?.id === id || this.loading()) {
      return;
    }

    this.loading.set(true);
    this.error.set(null);

    this.portfolioService.getPortfolioSummary(id).subscribe({
      next: (portfolio) => {
        this.portfolio.set(portfolio);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Impossible de charger ce portefeuille');
        this.loading.set(false);
      },
    });
  }

  /** Recharge les compteurs après une création ou une suppression de bien. */
  refreshStats(): void {
    const id = this.portfolioId();

    if (id === null) {
      return;
    }

    this.portfolioService.getPortfolioSummary(id).subscribe({
      next: (portfolio) => this.portfolio.set(portfolio),
    });
  }

  setPortfolioId(id: number): void {
    if (this.portfolioId() !== id) {
      this.portfolio.set(null);
    }

    this.portfolioId.set(id);
  }

  createProperty(payload: CreatePropertyPayload) {
    const id = this.portfolioId();
    if (!id) throw new Error('Aucun portefeuille actif');
    return this.portfolioService.createProperty(id, payload);
  }

  updateProperty(propertyId: number, payload: CreatePropertyPayload) {
    const id = this.portfolioId();
    if (!id) throw new Error('Aucun portefeuille actif');
    return this.portfolioService.updateProperty(id, propertyId, payload);
  }

  deleteProperty(property: Property) {
    const id = this.portfolioId();
    if (!id) throw new Error('Aucun portefeuille actif');

    this.deletingId.set(property.id);
    this.error.set(null);

    return this.portfolioService.deleteProperty(id, property.id);
  }
}
