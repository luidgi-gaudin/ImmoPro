import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { apiUrl } from '../config/api.config';
import { Observable } from 'rxjs';

export interface BailleurReport {
  total_portfolios: number;
  total_properties: number;
  occupied_properties: number;
  vacant_properties: number;
  total_tenants: number;
  total_leases: number;
  active_leases: number;
  monthly_rent_expected: number;
  this_month: {
    paid_amount: number;
    paid_count: number;
    late_amount: number;
    late_count: number;
    pending_amount: number;
    pending_count: number;
  };
}

export interface PropertyReportRow {
  id: number;
  title: string;
  city: string | null;
  portfolio_id: number | null;
  portfolio_name: string | null;
  is_rented: boolean;
  monthly_rent: number;
  /** Bail actif du bien, pour ouvrir le dossier directement depuis le rapport. */
  lease_id: number | null;
  tenant_id: number | null;
  tenant_name: string | null;
}

export interface TenantReportRow {
  id: number;
  name: string;
  total_paid: number;
  total_due: number;
  late_count: number;
  active_lease_id: number | null;
}

export interface ReportOverview {
  bailleur: BailleurReport;
  par_bien: PropertyReportRow[];
  par_locataire: TenantReportRow[];
}

@Injectable({ providedIn: 'root' })
export class ReportService {
  private http = inject(HttpClient);
  private apiUrl = apiUrl('reports');

  getOverview(): Observable<ReportOverview> {
    return this.http.get<ReportOverview>(`${this.apiUrl}/overview`);
  }
}
