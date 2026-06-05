import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TenantService, Tenant, PaginatedResponse } from '../../services/tenant.service';
import { ImmoproButtonComponent } from 'ui-lib';

@Component({
  selector: 'app-tenants',
  standalone: true,
  imports: [CommonModule, ImmoproButtonComponent],
  templateUrl: './tenants.component.html',
  styleUrl: './tenants.component.scss'
})
export class TenantsComponent implements OnInit {
  private tenantService = inject(TenantService);
  tenants = signal<Tenant[]>([]);
  pagination = signal<PaginatedResponse<Tenant> | undefined>(undefined);

  ngOnInit() {
    this.loadTenants();
  }

  loadTenants(page: number = 1) {
    this.tenantService.getTenants(page).subscribe(response => {
      this.tenants.set(response.data);
      this.pagination.set(response);
    });
  }

  changePage(page: number) {
    this.loadTenants(page);
  }

  addTenant() {
    console.log('Add tenant');
  }

  editTenant(tenant: Tenant) {
    console.log('Edit tenant', tenant.id);
  }
}
