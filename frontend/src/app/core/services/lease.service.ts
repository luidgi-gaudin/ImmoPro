import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface LeaseCoTenant {
  id: number;
  first_name: string;
  last_name: string;
  pivot: { rent_share: number | null };
}

export interface LeasePhoto {
  id: number;
  lease_id: number;
  type: 'entree' | 'sortie';
  original_name: string | null;
  url: string;
  created_at: string;
}

export interface Lease {
  id: number;
  property_id: number;
  tenant_id: number;
  type: 'nu' | 'meuble' | 'etudiant' | 'mobilite';
  start_date: string;
  end_date: string | null;
  monthly_rent: number;
  charges: number;
  deposit: number | null;
  payment_day: number | null;
  statut: 'actif' | 'en_attente' | 'termine';
  last_rent_revision_at?: string | null;
  co_tenants?: LeaseCoTenant[];
  photos?: LeasePhoto[];
}

export interface CoTenantPayload {
  tenant_id: number;
  rent_share?: number | null;
}

export interface CreateLeasePayload {
  property_id: number;
  tenant_id: number;
  type: string;
  start_date: string;
  end_date?: string | null;
  monthly_rent: number;
  charges?: number | null;
  deposit?: number | null;
  payment_day?: number | null;
  statut?: string;
  co_tenants?: CoTenantPayload[];
}

export interface RentPayment {
  id: number;
  lease_id: number;
  period: string;
  amount_rent: number;
  amount_charges: number;
  paid_at: string | null;
  payment_method: string | null;
  status: 'paye' | 'en_retard' | 'en_attente';
  total: number;
}

export interface QuittanceData {
  quittance: {
    numero: string;
    bailleur: { nom: string };
    locataire: { nom: string };
    bien: { adresse: string; code_postal: string; ville: string };
    periode: { debut: string; fin: string };
    detail: { loyer: number; charges: number; total: number };
    date_paiement: string;
    date_emission: string;
    mention_legale: string;
  };
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

  getLease(id: number): Observable<Lease> {
    return this.http.get<Lease>(`${this.apiUrl}/${id}`);
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

  // Lease Actions
  terminateLease(id: number, endDate: string): Observable<Lease> {
    return this.http.post<Lease>(`${this.apiUrl}/${id}/terminate`, { end_date: endDate });
  }

  reviseRent(id: number, irlOld: number, irlNew: number): Observable<{ message: string; old_rent: number; new_rent: number; data: Lease }> {
    return this.http.post<{ message: string; old_rent: number; new_rent: number; data: Lease }>(`${this.apiUrl}/${id}/revise-rent`, {
      irl_old: irlOld,
      irl_new: irlNew,
    });
  }

  // Rent Payments API
  getPayments(leaseId: number): Observable<RentPayment[]> {
    return this.http.get<RentPayment[]>(`${this.apiUrl}/${leaseId}/payments`);
  }

  getPayment(leaseId: number, paymentId: number): Observable<RentPayment> {
    return this.http.get<RentPayment>(`${this.apiUrl}/${leaseId}/payments/${paymentId}`);
  }

  createPayment(leaseId: number, payload: any): Observable<RentPayment> {
    return this.http.post<RentPayment>(`${this.apiUrl}/${leaseId}/payments`, payload);
  }

  updatePayment(leaseId: number, paymentId: number, payload: any): Observable<RentPayment> {
    return this.http.put<RentPayment>(`${this.apiUrl}/${leaseId}/payments/${paymentId}`, payload);
  }

  deletePayment(leaseId: number, paymentId: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${leaseId}/payments/${paymentId}`);
  }

  getQuittance(leaseId: number, paymentId: number): Observable<QuittanceData> {
    return this.http.get<QuittanceData>(`${this.apiUrl}/${leaseId}/payments/${paymentId}/quittance`);
  }

  // État des lieux (photos d'entrée / de sortie)
  uploadLeasePhoto(leaseId: number, type: 'entree' | 'sortie', file: File): Observable<LeasePhoto> {
    const formData = new FormData();
    formData.append('type', type);
    formData.append('photo', file);
    return this.http.post<LeasePhoto>(`${this.apiUrl}/${leaseId}/photos`, formData);
  }

  deleteLeasePhoto(leaseId: number, photoId: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${leaseId}/photos/${photoId}`);
  }
}
