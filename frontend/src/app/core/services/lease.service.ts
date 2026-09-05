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

/** Bien loué, tel que l'API le joint à chaque bail. */
export interface LeaseProperty {
  id: number;
  title: string;
  address: string | null;
  city: string | null;
  portfolio_id: number | null;
  portfolio_name: string | null;
}

/** Locataire principal, joint au bail. */
export interface LeaseTenant {
  id: number;
  first_name: string;
  last_name: string;
  email: string | null;
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

  /**
   * Bien et locataire, joints par l'API.
   *
   * L'écran des baux les retrouvait auparavant côté client, ce qui l'obligeait
   * à charger d'abord tous les portefeuilles, tous les locataires, puis les
   * biens de chaque portefeuille — un appel HTTP par portefeuille avant même
   * d'afficher la première ligne.
   */
  property?: LeaseProperty | null;
  tenant?: LeaseTenant | null;

  /** Plafond légal du dépôt, calculé côté serveur d'après le type de bail. */
  deposit_cap?: number;

  /** Éligibilité à la révision annuelle (art. 17-1, loi n° 89-462). */
  can_revise_rent?: boolean;

  /** Durée contractuelle en mois, null pour un bail à tacite reconduction. */
  duration_months?: number | null;

  /**
   * Réserve de conformité sur la durée, calculée par le serveur.
   *
   * Une durée inférieure au droit commun n'est pas refusée : elle est licite
   * dans des cas que le logiciel ne peut pas vérifier (motif de reprise pour un
   * bail vide, art. 11). Elle est signalée, et l'écran se contente d'afficher
   * ce que le serveur répond.
   */
  duration_notice?: string | null;

  documents_count?: number | null;

  co_tenants?: LeaseCoTenant[];
  photos?: LeasePhoto[];

  /** Voir LeaseSchedule : présent sur la fiche, absent des listes. */
  schedule?: LeaseSchedule | null;
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

  /**
   * Pose l'échéancier dès la création (comportement par défaut côté serveur).
   * Un bail sans échéancier oblige à ressaisir à la main des mois que le
   * contrat détermine entièrement.
   */
  generate_schedule?: boolean;
  schedule_months?: number;
}

/**
 * État de l'échéancier d'un bail, calculé par le serveur dans la requête qui
 * charge déjà la fiche. Absent des listes, qui ne l'affichent pas.
 */
export interface LeaseSchedule {
  count: number;
  paid_count: number;
  unpaid_count: number;
  overdue_count: number;
  outstanding_amount: number;
  /** Dernier mois couvert (premier jour du mois), null si aucun. */
  last_period: string | null;
  /** Premier mois manquant : sert à préremplir la génération. */
  next_period: string;
}

export interface GenerateScheduleResult {
  message: string;
  created: number;
  skipped: number;
  from: string;
  to: string;
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
  private apiUrl = apiUrl('leases');

  getLeases(params: Partial<ListParams> = {}): Observable<PaginatedResponse<Lease>> {
    return this.http.get<PaginatedResponse<Lease>>(this.apiUrl, {
      params: toHttpParams(params),
    });
  }

  /** Tous les baux, pour les écrans qui ont besoin de la vue complète. */
  getAllLeases(): Observable<Lease[]> {
    return fetchAllPages((page) => this.getLeases({ page, per_page: 100 }));
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

  /**
   * `acknowledge` lève le refus opposé aux baux dont des loyers ont été
   * encaissés. Le serveur ne l'accepte que sur un bail déjà résilié : un bail
   * en cours se résilie, il ne se supprime pas.
   */
  deleteLease(id: number, acknowledge = false): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`, {
      body: acknowledge ? { acknowledge: true } : undefined,
    });
  }

  // Lease Actions
  terminateLease(
    id: number,
    endDate: string,
  ): Observable<{ message: string; dropped_payments: number; data: Lease }> {
    return this.http.post<{ message: string; dropped_payments: number; data: Lease }>(
      `${this.apiUrl}/${id}/terminate`,
      { end_date: endDate },
    );
  }

  reviseRent(
    id: number,
    irlOld: number,
    irlNew: number,
  ): Observable<{
    message: string;
    old_rent: number;
    new_rent: number;
    repriced_payments: number;
    data: Lease;
  }> {
    return this.http.post<{
      message: string;
      old_rent: number;
      new_rent: number;
      repriced_payments: number;
      data: Lease;
    }>(`${this.apiUrl}/${id}/revise-rent`, {
      irl_old: irlOld,
      irl_new: irlNew,
    });
  }

  // Rent Payments API
  getPayments(
    leaseId: number,
    params: Partial<ListParams> = {},
  ): Observable<PaginatedResponse<RentPayment>> {
    return this.http.get<PaginatedResponse<RentPayment>>(`${this.apiUrl}/${leaseId}/payments`, {
      params: toHttpParams(params),
    });
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

  /**
   * Complète l'échéancier : un appel au lieu de N formulaires.
   *
   * L'opération est idempotente côté serveur — les mois déjà présents sont
   * comptés comme ignorés, jamais dupliqués — ce qui permet d'exposer le bouton
   * en permanence plutôt que de le réserver à un bail vierge.
   */
  generateSchedule(
    leaseId: number,
    options: { months?: number; from?: string; to?: string; prorate?: boolean } = {},
  ): Observable<GenerateScheduleResult> {
    return this.http.post<GenerateScheduleResult>(
      `${this.apiUrl}/${leaseId}/payments/generate`,
      options,
    );
  }

  /** Pointe un règlement. Sans date, le serveur retient aujourd'hui. */
  markPaid(
    leaseId: number,
    paymentId: number,
    payload: { paid_at?: string; payment_method?: string } = {},
  ): Observable<RentPayment> {
    return this.http.post<RentPayment>(
      `${this.apiUrl}/${leaseId}/payments/${paymentId}/pay`,
      payload,
    );
  }

  /** Annule un pointage fait par erreur. */
  markUnpaid(leaseId: number, paymentId: number): Observable<RentPayment> {
    return this.http.post<RentPayment>(`${this.apiUrl}/${leaseId}/payments/${paymentId}/unpay`, {});
  }

  /** Pointe plusieurs échéances en une écriture (rapprochement bancaire). */
  bulkMarkPaid(
    leaseId: number,
    ids: number[],
    payload: { paid_at?: string; payment_method?: string } = {},
  ): Observable<{ message: string; updated: number }> {
    return this.http.post<{ message: string; updated: number }>(
      `${this.apiUrl}/${leaseId}/payments/bulk-pay`,
      { ids, ...payload },
    );
  }

  getQuittance(leaseId: number, paymentId: number): Observable<QuittanceData> {
    return this.http.get<QuittanceData>(
      `${this.apiUrl}/${leaseId}/payments/${paymentId}/quittance`,
    );
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
