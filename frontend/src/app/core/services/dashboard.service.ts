import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, forkJoin, map } from 'rxjs';

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

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
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

  getPortfolios(): Observable<PortfolioSummary[]> {
    return this.http.get<PortfolioSummary[]>(`${this.apiBase}/portfolios`);
  }

  getTenants(): Observable<PaginatedResponse<TenantSummary>> {
    return this.http.get<PaginatedResponse<TenantSummary>>(`${this.apiBase}/tenants`);
  }

  getLeases(): Observable<LeaseSummary[]> {
    return this.http.get<LeaseSummary[]>(`${this.apiBase}/leases`);
  }

  getDashboard(): Observable<DashboardData> {
    return forkJoin({
      portfolios: this.getPortfolios(),
      tenants: this.getTenants(),
      leases: this.getLeases(),
    }).pipe(
      map(({ portfolios, tenants, leases }) => {
        const propertiesCount = portfolios.reduce(
          (total, portfolio) => total + (portfolio.properties_count ?? 0),
          0
        );

        const activeLeases = leases.filter((lease) => lease.statut === 'actif');
        const monthlyRentExpected = activeLeases.reduce(
          (total, lease) => total + Number(lease.monthly_rent ?? 0),
          0
        );

        return {
          portfoliosCount: portfolios.length,
          propertiesCount,
          tenantsCount: tenants.total ?? tenants.data.length,
          leasesCount: leases.length,
          activeLeasesCount: activeLeases.length,
          monthlyRentExpected,
          recentPortfolios: portfolios.slice(0, 3),
          recentTenants: tenants.data.slice(0, 3),
          recentLeases: leases.slice(0, 3),
        };
      })
    );
  }
}
