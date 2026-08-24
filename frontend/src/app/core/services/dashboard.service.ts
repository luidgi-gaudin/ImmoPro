import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { API_BASE_URL } from '../config/api.config';
import { Observable, map, tap } from 'rxjs';
import { AlertService, AppAlert } from './alert.service';

export interface PortfolioSummary {
  id: number;
  name: string;
  description: string | null;
  properties_count?: number;
}

export interface TenantSummary {
  id: number;
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string | null;
}

export interface LeaseSummary {
  id: number;
  property_id: number;
  tenant_id: number;
  /** Libellés joints par l'API : « Bail #12 » ne dit rien à un gestionnaire. */
  property_title: string | null;
  tenant_name: string | null;
  start_date: string;
  end_date: string | null;
  monthly_rent: number;
  deposit: number | null;
  statut: string;
}

/** Réponse de /api/dashboard : tout l'écran en un seul appel. */
interface DashboardResponse {
  counts: {
    portfolios: number;
    properties: number;
    occupied_properties: number;
    vacant_properties: number;
    tenants: number;
    leases: number;
    active_leases: number;
    monthly_rent_expected: number;
    documents: number;
  };
  recent: {
    portfolios: PortfolioSummary[];
    tenants: TenantSummary[];
    leases: LeaseSummary[];
  };
  alerts: {
    items: AppAlert[];
    unread_count: number;
  };
}

export interface DashboardData {
  portfoliosCount: number;
  propertiesCount: number;
  tenantsCount: number;
  leasesCount: number;
  activeLeasesCount: number;
  monthlyRentExpected: number;
  occupiedPropertiesCount: number;
  vacantPropertiesCount: number;
  documentsCount: number;
  recentPortfolios: PortfolioSummary[];
  recentTenants: TenantSummary[];
  recentLeases: LeaseSummary[];
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private http = inject(HttpClient);
  private alerts = inject(AlertService);
  private apiBase = API_BASE_URL;

  /**
   * Un seul appel pour tout l'écran.
   *
   * Cette méthode enchaînait auparavant quatre requêtes en parallèle (rapport
   * agrégé + trois listes « récents »). Sur une base distante, chacune payait de
   * nouveau l'authentification et l'ouverture de connexion, et elles ne se
   * recouvraient pas : environ 2,7 s au total, contre 1,2 s pour l'appel unique.
   */
  getDashboard(): Observable<DashboardData> {
    return this.http.get<DashboardResponse>(`${this.apiBase}/dashboard`).pipe(
      // Les alertes de l'aperçu et le compteur de non-lues sont déjà dans cette
      // réponse. L'écran les redemandait pourtant par deux appels HTTP séparés,
      // qui repayaient chacun l'authentification et l'ouverture de connexion
      // pour des données déjà arrivées. Trois appels devenaient un.
      tap((response) => this.alerts.adopt(response.alerts.items, response.alerts.unread_count)),
      map((response) => ({
        portfoliosCount: response.counts.portfolios,
        propertiesCount: response.counts.properties,
        occupiedPropertiesCount: response.counts.occupied_properties,
        vacantPropertiesCount: response.counts.vacant_properties,
        tenantsCount: response.counts.tenants,
        leasesCount: response.counts.leases,
        activeLeasesCount: response.counts.active_leases,
        monthlyRentExpected: response.counts.monthly_rent_expected,
        documentsCount: response.counts.documents,
        recentPortfolios: response.recent.portfolios,
        recentTenants: response.recent.tenants,
        recentLeases: response.recent.leases,
      })),
    );
  }
}
