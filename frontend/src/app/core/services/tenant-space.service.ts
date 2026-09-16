import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { apiUrl } from '../config/api.config';
import { PaginatedResponse } from '../list/pagination.model';

export interface TenantProfileSummary {
  id: number;
  full_name: string;
  email: string | null;
  phone: string | null;
}

export interface TenantSpaceProperty {
  id: number;
  title: string;
  property_type: string;
  property_type_label: string;
  full_address: string;
  address: string;
  address_complement: string | null;
  floor: string | null;
  apartment_number: string | null;
  postal_code: string;
  city: string;
  area_sqm: number | null;
  rooms: number | null;
  is_furnished: boolean;
  has_balcony: boolean;
  has_terrace: boolean;
  has_garden: boolean;
  has_parking: boolean;
  has_garage: boolean;
  has_cave: boolean;
  dpe: string | null;
  ges: string | null;
  dpe_date: string | null;
  dpe_expires_on: string | null;
}

export interface TenantSpacePayment {
  id: number;
  period: string | null;
  amount_rent: number;
  amount_charges: number;
  total: number;
  status: string;
  paid_at: string | null;
}

export interface TenantSpaceLease {
  id: number;
  type: string;
  type_label: string;
  statut: string;
  start_date: string;
  end_date: string | null;
  duration_months: number | null;
  monthly_rent: number;
  charges: number;
  total_due: number;
  deposit: number | null;
  payment_day: number | null;
  property: TenantSpaceProperty | null;
  payments?: TenantSpacePayment[];
}

/**
 * Chiffres de tête de l'espace locataire.
 *
 * « En retard » et « à venir » sont deux choses distinctes, et les confondre
 * annonçait une dette d'un an de loyers à quelqu'un qui ne devait rien :
 * l'échéancier est engendré des mois à l'avance.
 */
export interface TenantSpaceSummary {
  active_leases: number;

  /** Échéances dont la date de paiement est passée : le seul vrai arriéré. */
  overdue_count: number;
  overdue_amount: number;

  /** Échéances futures : des rendez-vous, pas des dettes. */
  upcoming_count: number;

  next_due: {
    lease_id: number;
    period: string | null;
    amount: number;
    status: string;
  } | null;
}

export interface TenantSpaceOverview {
  profiles: TenantProfileSummary[];
  leases: TenantSpaceLease[];
  summary: TenantSpaceSummary;
}

/**
 * Pièce du dossier, telle que l'API la rend.
 *
 * Même forme que côté bailleur : c'est la même ressource, vue depuis l'autre
 * bout. Le lien d'aperçu est signé et expire en quelques minutes ; il est
 * régénéré à chaque lecture, donc toujours frais à l'ouverture de l'écran.
 */
export interface TenantSpaceDocument {
  id: number;
  category: string;
  category_label: string;
  name: string;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  size_label: string;
  issued_on: string | null;
  expires_on: string | null;
  is_expired: boolean;
  expires_soon: boolean;
  notes: string | null;
  attached_to: { type: string | null; id: number; label: string | null };
  download_url: string;
  preview_url: string | null;
  created_at: string | null;
}

export interface TenantUploadableCategory {
  value: string;
  label: string;
  attachable_to: string[];
  validity_months: number | null;
}

/**
 * Espace locataire.
 *
 * Le point de vue s'inverse par rapport au reste de l'application : rien ne
 * part d'un portefeuille, tout part des dossiers rattachés au compte connecté.
 * Ces routes sont distinctes de celles du bailleur, et ne rendent que ce qui le
 * regarde — ni le parc, ni les coordonnées bancaires, ni les pièces de gestion.
 */
@Injectable({ providedIn: 'root' })
export class TenantSpaceService {
  private http = inject(HttpClient);
  private base = apiUrl('tenant-space');

  overview(): Observable<{ data: TenantSpaceOverview; message?: string }> {
    return this.http.get<{ data: TenantSpaceOverview; message?: string }>(this.base);
  }

  lease(id: number): Observable<{ data: TenantSpaceLease }> {
    return this.http.get<{ data: TenantSpaceLease }>(`${this.base}/leases/${id}`);
  }

  documents(
    params: Record<string, string> = {},
  ): Observable<PaginatedResponse<TenantSpaceDocument>> {
    return this.http.get<PaginatedResponse<TenantSpaceDocument>>(`${this.base}/documents`, {
      params,
    });
  }

  uploadableCategories(): Observable<{
    categories: TenantUploadableCategory[];
    max_size_kb: number;
    accepted_mimes: string[];
  }> {
    return this.http.get<{
      categories: TenantUploadableCategory[];
      max_size_kb: number;
      accepted_mimes: string[];
    }>(`${this.base}/documents/categories`);
  }

  upload(payload: {
    documentable_type: 'lease' | 'tenant';
    documentable_id: number;
    category: string;
    file: File;
    name?: string;
    issued_on?: string;
    expires_on?: string;
    notes?: string;
  }): Observable<any> {
    const body = new FormData();

    body.append('documentable_type', payload.documentable_type);
    body.append('documentable_id', String(payload.documentable_id));
    body.append('category', payload.category);
    body.append('file', payload.file);

    // Les champs vides ne sont pas envoyés : une chaîne vide sur une date
    // échouerait à la validation, alors que le champ est facultatif.
    for (const key of ['name', 'issued_on', 'expires_on', 'notes'] as const) {
      const value = payload[key];

      if (value) {
        body.append(key, value);
      }
    }

    return this.http.post(`${this.base}/documents`, body);
  }

  /**
   * Télécharge une pièce.
   *
   * Passe par le client HTTP, et non par un lien `<a href download>` : le jeton
   * voyage dans un en-tête d'autorisation qu'une navigation du navigateur ne
   * porte pas. Le lien direct répondrait 401 sans rien dire de compréhensible.
   */
  download(documentId: number): Observable<Blob> {
    return this.http.get(`${this.base}/documents/${documentId}/download`, {
      responseType: 'blob',
    });
  }
}
