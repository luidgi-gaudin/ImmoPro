import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
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
}

export interface Portfolio {
  id: number;
  name: string;
  description: string;
  properties_count?: number;
  properties?: Property[];
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

export interface CreatePortfolioPayload {
  name: string;
  description?: string | null;
}

@Injectable({
  providedIn: 'root',
})
export class PortfolioService {
  private http = inject(HttpClient);
  private apiUrl = 'http://127.0.0.1:8000/api/portfolios';

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

  getPortfolioProperties(
    id: number,
    params: Partial<ListParams> = {},
  ): Observable<PaginatedResponse<Property>> {
    return this.http.get<PaginatedResponse<Property>>(`${this.apiUrl}/${id}/properties`, {
      params: toHttpParams(params),
    });
  }

  /** Tous les biens d'un portefeuille, pour alimenter un menu déroulant. */
  getAllPortfolioProperties(id: number): Observable<Property[]> {
    return fetchAllPages((page) => this.getPortfolioProperties(id, { page, per_page: 100 }));
  }

  getProperty(portfolioId: number, propertyId: number): Observable<Property> {
    return this.http.get<Property>(`${this.apiUrl}/${portfolioId}/properties/${propertyId}`);
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
