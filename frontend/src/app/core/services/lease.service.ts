import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

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
}
