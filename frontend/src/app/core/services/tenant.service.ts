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

  /** Composé par le serveur, pour que l'affichage ne recolle pas les morceaux. */
  full_name?: string;

  email: string;
  phone: string;

  birth_date?: string | null;
  birth_place?: string | null;
  identity_document_type?: string | null;
  identity_document_number?: string | null;

  iban?: string | null;
  bic?: string | null;
  country?: string | null;
  address?: string | null;

  /**
   * Dossier clos, sorti des listes de travail sans quitter la base.
   *
   * Distinct de la suppression : la prescription des loyers court sur trois
   * ans, et le dépôt de garantie se réclame longtemps après le départ.
   */
  archived_at?: string | null;
  is_archived?: boolean;

  /** Le locataire a ouvert son espace et son dossier lui est rattaché. */
  has_account?: boolean;
  account_user_id?: number | null;

  guarantors?: Guarantor[];
  guarantors_count?: number;
  active_leases_count?: number;
  documents_count?: number;
}

export interface CreateTenantPayload {
  first_name: string;
  last_name: string;
  birth_date?: string | null;
  birth_place?: string | null;
  identity_document_type?: string | null;
  identity_document_number?: string | null;
  email?: string | null;
  phone?: string | null;
  iban?: string | null;
  bic?: string | null;
  country?: string | null;
  address?: string | null;
}

/**
 * Garant d'un locataire.
 *
 * Le type de garantie n'est pas une étiquette : il commande ce que le bailleur
 * peut faire en cas d'impayé. Une caution simple oblige à poursuivre d'abord le
 * locataire ; une caution solidaire permet de réclamer directement au garant ;
 * Visale et une assurance loyers impayés se réclament à un organisme, sur
 * dossier.
 */
export interface Guarantor {
  id: number;
  tenant_id: number;
  guarantee_type: string;

  first_name?: string | null;
  last_name?: string | null;
  birth_date?: string | null;
  profession?: string | null;
  company_name?: string | null;

  email?: string | null;
  phone?: string | null;
  address?: string | null;
  postal_code?: string | null;
  city?: string | null;
  country?: string | null;

  monthly_income?: number | string | null;
  contract_reference?: string | null;

  starts_on?: string | null;
  ends_on?: string | null;
  max_amount?: number | string | null;
  notes?: string | null;

  /** Nom de la personne, ou de l'organisme quand il n'y en a pas. */
  display_name?: string;

  /** Un cautionnement échu ne couvre plus rien, et la fiche doit le dire. */
  is_expired?: boolean;
}

export type GuarantorPayload = Omit<Guarantor, 'id' | 'tenant_id' | 'display_name' | 'is_expired'>;

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

  /* ----------------------------------------------------------------------
   | Archivage
   |----------------------------------------------------------------------*/

  archiveTenant(id: number): Observable<{ data: Tenant }> {
    return this.http.post<{ data: Tenant }>(`${this.apiUrl}/${id}/archive`, {});
  }

  unarchiveTenant(id: number): Observable<{ data: Tenant }> {
    return this.http.delete<{ data: Tenant }>(`${this.apiUrl}/${id}/archive`);
  }

  /**
   * Invite le locataire à ouvrir son espace.
   *
   * Le message ne porte aucun accès direct : il renvoie vers l'inscription, et
   * c'est la vérification de l'adresse qui rattachera le dossier.
   */
  inviteTenant(id: number): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.apiUrl}/${id}/invite`, {});
  }

  /* ----------------------------------------------------------------------
   | Garants
   |----------------------------------------------------------------------*/

  getGuarantors(tenantId: number): Observable<{ data: Guarantor[] }> {
    return this.http.get<{ data: Guarantor[] }>(`${this.apiUrl}/${tenantId}/guarantors`);
  }

  createGuarantor(tenantId: number, payload: GuarantorPayload): Observable<{ data: Guarantor }> {
    return this.http.post<{ data: Guarantor }>(`${this.apiUrl}/${tenantId}/guarantors`, payload);
  }

  updateGuarantor(
    tenantId: number,
    guarantorId: number,
    payload: GuarantorPayload,
  ): Observable<{ data: Guarantor }> {
    return this.http.put<{ data: Guarantor }>(
      `${this.apiUrl}/${tenantId}/guarantors/${guarantorId}`,
      payload,
    );
  }

  deleteGuarantor(tenantId: number, guarantorId: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${tenantId}/guarantors/${guarantorId}`);
  }
}
