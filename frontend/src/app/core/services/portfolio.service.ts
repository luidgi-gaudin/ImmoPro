import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { apiUrl } from '../config/api.config';
import { Observable, map, of, switchMap } from 'rxjs';
import {
  ListParams,
  PaginatedResponse,
  fetchAllPages,
  toHttpParams,
} from '../list/pagination.model';

export interface Property {
  id: number;
  title: string;
  property_type: string;
  address: string;
  city: string;
  postal_code: string;
  dpe: string;
  rooms: number | null;
  area_sqm: number | null;
  has_balcony: boolean;
  has_garden: boolean;
  has_parking: boolean;
  has_cave: boolean;
  is_rented: boolean;
  monthly_rent: number | null;
  description: string | null;
  active_leases_count?: number;
  documents_count?: number;
}

/** Aperçu d'un bien tel qu'il apparaît sur la carte d'un portefeuille. */
export interface PropertyPreview {
  id: number;
  title: string;
  city: string | null;
  is_rented: boolean | number;
}

export interface Portfolio {
  id: number;
  name: string;
  description: string;
  properties_count?: number;
  occupied_properties_count?: number;
  vacant_properties_count?: number;
  /** Somme des loyers prévus des biens du portefeuille, calculée par l'API. */
  expected_rent?: number;
  documents_count?: number;

  /**
   * Trois premiers biens, agrégés par l'API dans la requête de liste.
   *
   * Remplace l'ancien `properties`, qui obligeait le serveur à une seconde
   * requête pour charger la relation. La forme est volontairement réduite : la
   * carte n'affiche qu'un titre et une ville.
   */
  properties_preview?: PropertyPreview[];
}

export interface CreatePropertyPayload {
  title: string;
  property_type: string;
  address: string;
  city: string;
  postal_code: string;
  dpe: string;
  rooms?: number | null;
  area_sqm?: number | null;
  has_balcony?: boolean;
  has_garden?: boolean;
  has_parking?: boolean;
  has_cave?: boolean;
  is_rented?: boolean;
  monthly_rent?: number | null;
  description?: string | null;
}

/**
 * Réponse de la liste des biens d'un portefeuille : l'enveloppe de pagination
 * habituelle, plus le portefeuille lui-même.
 */
export interface PropertiesPage extends PaginatedResponse<Property> {
  portfolio?: Portfolio;
}

export interface CreatePortfolioPayload {
  name: string;
  description?: string | null;
}

@Injectable({
  providedIn: 'root',
})
export class PortfolioService {
  private http = inject(HttpClient);
  private apiUrl = apiUrl('portfolios');

  getPortfolios(params: Partial<ListParams> = {}): Observable<PaginatedResponse<Portfolio>> {
    return this.http.get<PaginatedResponse<Portfolio>>(this.apiUrl, {
      params: toHttpParams(params),
    });
  }

  /** Tous les portefeuilles, pour alimenter un menu déroulant. */
  getAllPortfolios(): Observable<Portfolio[]> {
    return fetchAllPages((page) => this.getPortfolios({ page, per_page: 100 }));
  }

  getPortfolio(id: number): Observable<Portfolio> {
    return this.http.get<Portfolio>(`${this.apiUrl}/${id}`);
  }

  updatePortfolio(id: number, portfolio: CreatePortfolioPayload): Observable<Portfolio> {
    return this.http.put<Portfolio>(`${this.apiUrl}/${id}`, portfolio);
  }

  deletePortfolio(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`);
  }

  getPortfolioProperties(id: number, params: Partial<ListParams> = {}): Observable<PropertiesPage> {
    return this.http.get<PropertiesPage>(`${this.apiUrl}/${id}/properties`, {
      params: toHttpParams(params),
    });
  }

  /** Tous les biens d'un portefeuille, pour alimenter un menu déroulant. */
  getAllPortfolioProperties(id: number): Observable<Property[]> {
    return fetchAllPages((page) => this.getPortfolioProperties(id, { page, per_page: 100 }));
  }

  /**
   * Le portefeuille seul, avec ses compteurs, sans rapatrier ses biens.
   *
   * Sert aux écrans qui n'affichent que le bandeau et les statistiques — la
   * vue d'ensemble, par exemple. On demande une page d'un seul bien : c'est le
   * portefeuille joint à la réponse qui nous intéresse, pas les lignes.
   */
  getPortfolioSummary(id: number): Observable<Portfolio | null> {
    return this.getPortfolioProperties(id, { page: 1, per_page: 1 }).pipe(
      map((page) => page.portfolio ?? null),
    );
  }

  /**
   * Le portefeuille et l'ensemble de ses biens, en un seul appel.
   *
   * L'écran d'un portefeuille les demandait séparément : un appel pour la
   * fiche, un autre pour les biens. Or la réponse de la liste des biens porte
   * désormais le portefeuille, puisque le serveur a dû le charger de toute
   * façon pour vérifier le droit d'accès. Un aller-retour HTTP complet
   * disparaît — authentification et ouverture de connexion comprises.
   *
   * Les biens sont demandés en entier, et c'est nécessaire : les statistiques
   * du portefeuille portent sur l'ensemble, pas sur la page affichée.
   */
  getPortfolioWithProperties(
    id: number,
  ): Observable<{ portfolio: Portfolio | null; properties: Property[] }> {
    return this.getPortfolioProperties(id, { page: 1, per_page: 100 }).pipe(
      switchMap((first) => {
        const portfolio = first.portfolio ?? null;

        if (first.current_page >= first.last_page) {
          return of({ portfolio, properties: first.data });
        }

        // Au-delà de cent biens, on complète — cas rare, mais une statistique
        // fausse est pire qu'un second appel.
        return fetchAllPages((page) =>
          this.getPortfolioProperties(id, { page, per_page: 100 }),
        ).pipe(map((properties) => ({ portfolio, properties })));
      }),
    );
  }

  /**
   * Fiche d'un bien, avec le portefeuille auquel il appartient.
   *
   * Le serveur charge déjà le portefeuille pour vérifier le droit d'accès : le
   * joindre à la réponse évite à l'écran parent un appel HTTP dédié pour son
   * bandeau et ses compteurs.
   */
  getProperty(
    portfolioId: number,
    propertyId: number,
  ): Observable<Property & { portfolio?: Portfolio }> {
    return this.http.get<Property & { portfolio?: Portfolio }>(
      `${this.apiUrl}/${portfolioId}/properties/${propertyId}`,
    );
  }

  createProperty(portfolioId: number, property: CreatePropertyPayload): Observable<Property> {
    return this.http.post<Property>(`${this.apiUrl}/${portfolioId}/properties`, property);
  }

  updateProperty(
    portfolioId: number,
    propertyId: number,
    property: CreatePropertyPayload,
  ): Observable<Property> {
    return this.http.put<Property>(
      `${this.apiUrl}/${portfolioId}/properties/${propertyId}`,
      property,
    );
  }

  deleteProperty(portfolioId: number, propertyId: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${portfolioId}/properties/${propertyId}`);
  }

  createPortfolio(portfolio: CreatePortfolioPayload): Observable<Portfolio> {
    return this.http.post<Portfolio>(this.apiUrl, portfolio);
  }
}
