import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';

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

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

@Injectable({
  providedIn: 'root',
})
export class TenantService {
  private http = inject(HttpClient);
  private apiUrl = 'http://127.0.0.1:8000/api/tenants';

  getTenants(page: number = 1): Observable<PaginatedResponse<Tenant>> {
    const params = new HttpParams().set('page', page.toString());
    return this.http.get<PaginatedResponse<Tenant>>(this.apiUrl, { params });
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
