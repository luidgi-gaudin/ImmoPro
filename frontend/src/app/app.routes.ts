import { Routes } from '@angular/router';
import { DashboardComponent } from './pages/dashboard/dashboard.component';
import { PortfoliosComponent } from './pages/portfolios/portfolios.component';
import { PortfolioDetailsComponent } from './pages/portfolios/portfolio-details.component';
import { LeasesComponent } from './pages/leases/leases.component';
import { TenantsComponent } from './pages/tenants/tenants.component';
import { LoginComponent } from './pages/login/login.component';
import { RegisterComponent } from './pages/register/register.component';
import { authGuard } from './services/auth.guard';

export const routes: Routes = [
  { path: '', redirectTo: '/login', pathMatch: 'full' },
  {
    path: '',
    canActivateChild: [authGuard],
    children: [
      { path: 'dashboard', component: DashboardComponent },
      { path: 'portfolios', component: PortfoliosComponent },
      { path: 'portfolios/:id', component: PortfolioDetailsComponent },
      { path: 'tenants', component: TenantsComponent },
      { path: 'leases', component: LeasesComponent },
    ],
  },
  { path: 'login', component: LoginComponent },
  { path: 'register', component: RegisterComponent },
];
