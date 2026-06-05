import { Routes } from '@angular/router';
import { DashboardComponent } from './pages/dashboard/dashboard.component';
import { LoginComponent } from './pages/login/login.component';
import { RegisterComponent } from './pages/register/register.component';
import { authGuard } from './services/auth.guard';

export const routes: Routes = [
  { path: '', redirectTo: '/login', pathMatch: 'full' },
  {
    path: '',
    canActivateChild: [authGuard],
    children: [{ path: 'dashboard', component: DashboardComponent }],
  },
  { path: 'login', component: LoginComponent },
  { path: 'register', component: RegisterComponent },
];
