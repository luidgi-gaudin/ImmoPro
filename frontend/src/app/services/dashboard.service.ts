import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { forkJoin, map, Observable, switchMap } from 'rxjs';

export interface DashboardData {
  portfoliosCount: number;
  propertiesCount: number;
  tenantsCount: number;
  leasesCount: number;
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private http = inject(HttpClient);
  private apiBase = 'http://127.0.0.1:8000/api';

  getPortfolios() {
    return this.http.get<any[]>(`${this.apiBase}/portfolios`);
  }

  getProperties(portfolioId: number) {
    return this.http.get<any[]>(`${this.apiBase}/portfolios/${portfolioId}/properties`);
  }

  getTenants() {
    return this.http.get<any[]>(`${this.apiBase}/tenants`);
  }

  getLeases() {
    return this.http.get<any[]>(`${this.apiBase}/leases`);
  }

  getDashboard(): Observable<DashboardData> {
    return this.getPortfolios().pipe(
      switchMap((portfolios) => {
        const portfolioCount = portfolios.length;

        const propertiesCalls = portfolios.map((p) => this.getProperties(p.id));

        return forkJoin({
          propertiesAll: propertiesCalls.length ? forkJoin(propertiesCalls) : new Observable<any>((sub) => { sub.next([]); sub.complete(); }),
          tenants: this.getTenants(),
          leases: this.getLeases(),
          portfolioCount: new Observable<number>((sub) => { sub.next(portfolioCount); sub.complete(); }),
        });
      }),
      map((res: any) => {
        const propertiesCount = Array.isArray(res.propertiesAll)
          ? res.propertiesAll.flat().length
          : 0;

        return {
          portfoliosCount: res.portfolioCount ?? 0,
          propertiesCount,
          tenantsCount: Array.isArray(res.tenants) ? res.tenants.length : 0,
          leasesCount: Array.isArray(res.leases) ? res.leases.length : 0,
        } as DashboardData;
      })
    );
  }
}
