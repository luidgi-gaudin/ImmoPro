import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./features/welcome/welcome.component').then((m) => m.WelcomeComponent),
    pathMatch: 'full',
  },
  {
    path: '',
    canActivateChild: [authGuard],
    children: [
      {
        path: 'dashboard',
        loadComponent: () =>
          import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
      },
      {
        path: 'portfolios',
        loadComponent: () =>
          import('./features/portfolios/portfolios.component').then((m) => m.PortfoliosComponent),
      },
      {
        path: 'portfolios/:id',
        loadComponent: () =>
          import('./features/portfolios/portfolio-shell.component').then(
            (m) => m.PortfolioShellComponent,
          ),
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'overview' },
          {
            path: 'overview',
            loadComponent: () =>
              import('./features/portfolios/portfolio-overview.component').then(
                (m) => m.PortfolioOverviewComponent,
              ),
          },
          {
            path: 'properties',
            loadComponent: () =>
              import('./features/portfolios/portfolio-properties.component').then(
                (m) => m.PortfolioPropertiesComponent,
              ),
          },
          {
            path: 'properties/:propertyId',
            loadComponent: () =>
              import('./features/portfolios/portfolio-property-detail.component').then(
                (m) => m.PortfolioPropertyDetailComponent,
              ),
          },
        ],
      },
      {
        path: 'tenants',
        loadComponent: () =>
          import('./features/tenants/tenants.component').then((m) => m.TenantsComponent),
      },
      {
        path: 'tenants/:id',
        loadComponent: () =>
          import('./features/tenants/tenant-detail.component').then((m) => m.TenantDetailComponent),
      },
      {
        path: 'leases',
        loadComponent: () =>
          import('./features/leases/leases.component').then((m) => m.LeasesComponent),
      },
      {
        path: 'alerts',
        loadComponent: () =>
          import('./features/alerts/alerts.component').then((m) => m.AlertsComponent),
      },
      {
        path: 'reports',
        loadComponent: () =>
          import('./features/reports/reports.component').then((m) => m.ReportsComponent),
      },
      {
        path: 'profile',
        loadComponent: () =>
          import('./features/profile/profile.component').then((m) => m.ProfileComponent),
      },
    ],
  },
  // Pages légales : accessibles sans compte, comme l'exige leur raison d'être.
  {
    path: 'confidentialite',
    loadComponent: () =>
      import('./features/legal/privacy.component').then((m) => m.PrivacyComponent),
  },
  {
    path: 'cookies',
    loadComponent: () =>
      import('./features/legal/cookies.component').then((m) => m.CookiesComponent),
  },
  {
    path: 'login',
    loadComponent: () =>
      import('./features/auth/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'register',
    loadComponent: () =>
      import('./features/auth/register/register.component').then((m) => m.RegisterComponent),
  },
  {
    path: 'forgot-password',
    loadComponent: () =>
      import('./features/auth/login/forgot-password.component').then(
        (m) => m.ForgotPasswordComponent,
      ),
  },
  {
    path: 'reset-password',
    loadComponent: () =>
      import('./features/auth/login/reset-password.component').then(
        (m) => m.ResetPasswordComponent,
      ),
  },
];
