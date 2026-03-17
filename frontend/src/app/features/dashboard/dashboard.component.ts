import { Component, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { BreakpointObserver, Breakpoints } from '@angular/cdk/layout';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';
import { NgClass } from '@angular/common';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { MatCardModule } from '@angular/material/card';
import { MatMenuModule } from '@angular/material/menu';

import { APP_ROUTES } from '../../core/constants/app.constants';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-dashboard',
  imports: [
    NgClass,
    RouterLink,
    RouterLinkActive,
    MatSidenavModule,
    MatToolbarModule,
    MatButtonModule,
    MatIconModule,
    MatListModule,
    MatCardModule,
    MatMenuModule,
  ],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent {
  readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  readonly isHandset = toSignal(
    inject(BreakpointObserver)
      .observe(Breakpoints.Handset)
      .pipe(map((result) => result.matches)),
    { initialValue: false },
  );

  readonly navItems = [
    { icon: 'dashboard', label: 'Tableau de bord', path: '/dashboard' },
    { icon: 'search', label: 'Rechercher', path: '/dashboard' },
    { icon: 'favorite', label: 'Mes favoris', path: '/dashboard' },
    { icon: 'notifications', label: 'Mes alertes', path: '/dashboard' },
    { icon: 'chat', label: 'Messages', path: '/dashboard' },
    { icon: 'settings', label: 'Paramètres', path: '/dashboard' },
  ];

  readonly stats = [
    { label: 'Biens consultés', value: '0', icon: 'home', colorClass: 'stat-icon-wrap-blue' },
    { label: 'Favoris', value: '0', icon: 'favorite', colorClass: 'stat-icon-wrap-red' },
    {
      label: 'Alertes actives',
      value: '0',
      icon: 'notifications',
      colorClass: 'stat-icon-wrap-amber',
    },
    { label: 'Messages', value: '0', icon: 'chat', colorClass: 'stat-icon-wrap-green' },
  ];

  logout(): void {
    this.authService.logout().subscribe({
      complete: () => void this.router.navigate([APP_ROUTES.LOGIN]),
    });
  }
}
