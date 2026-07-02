import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

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

  getPortfolios(): Observable<Portfolio[]> {
    return this.http.get<Portfolio[]>(this.apiUrl);
  }

  getPortfolio(id: number): Observable<Portfolio> {
    return this.http.get<Portfolio>(`${this.apiUrl}/${id}`);
  }

  getPortfolioProperties(id: number): Observable<Property[]> {
    return this.http.get<Property[]>(`${this.apiUrl}/${id}/properties`);
  }

  createProperty(portfolioId: number, property: CreatePropertyPayload): Observable<Property> {
    return this.http.post<Property>(`${this.apiUrl}/${portfolioId}/properties`, property);
  }

  updateProperty(portfolioId: number, propertyId: number, property: CreatePropertyPayload): Observable<Property> {
    return this.http.put<Property>(`${this.apiUrl}/${portfolioId}/properties/${propertyId}`, property);
  }

  deleteProperty(portfolioId: number, propertyId: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${portfolioId}/properties/${propertyId}`);
  }

  createPortfolio(portfolio: CreatePortfolioPayload): Observable<Portfolio> {
    return this.http.post<Portfolio>(this.apiUrl, portfolio);
  }
}
