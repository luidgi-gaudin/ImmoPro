import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { apiUrl } from '../config/api.config';
import { Observable } from 'rxjs';
import {
  ListParams,
  PaginatedResponse,
  fetchAllPages,
  toHttpParams,
} from '../list/pagination.model';

export interface Tenant {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  iban?: string | null;
  bic?: string | null;
  country?: string | null;
  address?: string | null;
}

export interface CreateTenantPayload {
  first_name: string;
  last_name: string;
  email?: string | null;
  phone?: string | null;
  iban?: string | null;
  bic?: string | null;
  country?: string | null;
  address?: string | null;
}

// Réexport pour ne pas casser les imports existants ; la définition de
// référence vit désormais dans core/list/pagination.model.ts.
export type { PaginatedResponse };

@Injectable({
  providedIn: 'root',
})
export class TenantService {
  private http = inject(HttpClient);
  private apiUrl = apiUrl('tenants');

  getTenants(params: Partial<ListParams> = {}): Observable<PaginatedResponse<Tenant>> {
    return this.http.get<PaginatedResponse<Tenant>>(this.apiUrl, {
      params: toHttpParams(params),
    });
  }

  /**
   * Tous les locataires, pour alimenter un menu déroulant.
   *
   * À ne pas utiliser pour afficher une liste : c'est précisément ce que la
   * pagination sert à éviter.
   */
  getAllTenants(): Observable<Tenant[]> {
    return fetchAllPages((page) => this.getTenants({ page, per_page: 100 }));
  }

  getTenant(id: number): Observable<Tenant> {
    return this.http.get<Tenant>(`${this.apiUrl}/${id}`);
  }

  createTenant(tenant: CreateTenantPayload): Observable<Tenant> {
    return this.http.post<Tenant>(this.apiUrl, tenant);
  }

  updateTenant(id: number, tenant: CreateTenantPayload): Observable<Tenant> {
    return this.http.put<Tenant>(`${this.apiUrl}/${id}`, tenant);
  }

  deleteTenant(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`);
  }
}
