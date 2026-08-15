import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';

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
    tenants: number;
    leases: number;
    active_leases: number;
    monthly_rent_expected: number;
  };
  recent: {
    portfolios: PortfolioSummary[];
    tenants: TenantSummary[];
    leases: LeaseSummary[];
  };
  alerts: {
    items: unknown[];
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
  recentPortfolios: PortfolioSummary[];
  recentTenants: TenantSummary[];
  recentLeases: LeaseSummary[];
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private http = inject(HttpClient);
  private apiBase = 'http://127.0.0.1:8000/api';

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
      map((response) => ({
        portfoliosCount: response.counts.portfolios,
        propertiesCount: response.counts.properties,
        tenantsCount: response.counts.tenants,
        leasesCount: response.counts.leases,
        activeLeasesCount: response.counts.active_leases,
        monthlyRentExpected: response.counts.monthly_rent_expected,
        recentPortfolios: response.recent.portfolios,
        recentTenants: response.recent.tenants,
        recentLeases: response.recent.leases,
      })),
    );
  }
}
