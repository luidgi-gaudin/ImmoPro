import { Routes } from '@angular/router';
import { DashboardComponent } from './features/dashboard/dashboard.component';
import { PortfoliosComponent } from './features/portfolios/portfolios.component';
import { PortfolioDetailsComponent } from './features/portfolios/portfolio-details.component';
import { TenantsComponent } from './features/tenants/tenants.component';
import { LeasesComponent } from './features/leases/leases.component';
import { ProfileComponent } from './features/profile/profile.component';
import { AlertsComponent } from './features/alerts/alerts.component';
import { LoginComponent } from './features/auth/login/login.component';
import { RegisterComponent } from './features/auth/register/register.component';
import { ForgotPasswordComponent } from './features/auth/login/forgot-password.component';
import { ResetPasswordComponent } from './features/auth/login/reset-password.component';
import { WelcomeComponent } from './features/welcome/welcome.component';
import { authGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  { path: '', component: WelcomeComponent, pathMatch: 'full' },
  {
    path: '',
    canActivateChild: [authGuard],
    children: [
      { path: 'dashboard', component: DashboardComponent },
      { path: 'portfolios', component: PortfoliosComponent },
      { path: 'portfolios/:id', component: PortfolioDetailsComponent },
      { path: 'tenants', component: TenantsComponent },
      { path: 'leases', component: LeasesComponent },
      { path: 'alerts', component: AlertsComponent },
      { path: 'profile', component: ProfileComponent },
    ],
  },
  { path: 'login', component: LoginComponent },
  { path: 'register', component: RegisterComponent },
  { path: 'forgot-password', component: ForgotPasswordComponent },
  { path: 'reset-password', component: ResetPasswordComponent },
];
