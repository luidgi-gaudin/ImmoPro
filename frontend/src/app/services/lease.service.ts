import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface Lease {
  id: number;
  property_id: number;
  tenant_id: number;
  start_date: string;
  end_date: string | null;
  monthly_rent: number;
  deposit: number | null;
  statut: string;
}

export interface CreateLeasePayload {
  property_id: number;
  tenant_id: number;
  start_date: string;
  end_date?: string | null;
  monthly_rent: number;
  deposit?: number | null;
  statut?: string;
}

@Injectable({
  providedIn: 'root',
})
export class LeaseService {
  private http = inject(HttpClient);
  private apiUrl = 'http://127.0.0.1:8000/api/leases';

  getLeases(): Observable<Lease[]> {
    return this.http.get<Lease[]>(this.apiUrl);
  }

  createLease(lease: CreateLeasePayload): Observable<Lease> {
    return this.http.post<Lease>(this.apiUrl, lease);
  }

  updateLease(id: number, lease: CreateLeasePayload): Observable<Lease> {
    return this.http.put<Lease>(`${this.apiUrl}/${id}`, lease);
  }

  deleteLease(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`);
  }
}
