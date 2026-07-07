import { Component, inject, ChangeDetectionStrategy } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { ThemeService } from '../../../core/services/theme.service';

@Component({
  selector: 'app-navbar',
  standalone: true,
  imports: [RouterLink, RouterLinkActive],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="navbar-container">
      <div class="navbar-content">
        <div class="brand" (click)="navigateTo('/')">
          <span class="logo-text">ImmoPro</span>
        </div>

        @if (auth.isAuthenticated()) {
          <nav class="main-nav">
            <a routerLink="/dashboard" routerLinkActive="active">Dashboard</a>
            <a routerLink="/portfolios" routerLinkActive="active">Portfolios</a>
            <a routerLink="/tenants" routerLinkActive="active">Locataires</a>
            <a routerLink="/leases" routerLinkActive="active">Baux</a>
          </nav>
        }

        <div class="user-actions">
          <!-- Theme Switcher Button -->
          <button class="btn-theme" (click)="theme.toggleTheme()" [title]="theme.isLightTheme() ? 'Passer en mode sombre' : 'Passer en mode clair'">
            @if (theme.isLightTheme()) {
              <!-- Moon Icon (shown in light mode) -->
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            } @else {
              <!-- Sun Icon (shown in dark mode) -->
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
            }
          </button>

          @if (auth.isAuthenticated()) {
            @if (auth.currentUser(); as user) {
              <div class="user-info" routerLink="/profile" style="cursor: pointer;" title="Accéder au profil">
                <div class="avatar-ring">
                  <span class="user-initial">{{ user.name.charAt(0) || 'U' }}</span>
                </div>
                <span class="user-name">{{ user.name }}</span>
              </div>
            }

            <button class="btn-logout" (click)="logout()" title="Déconnexion">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
          }
        </div>
      </div>
    </header>
  `,
  styles: [],
})
export class NavbarComponent {
  protected auth = inject(AuthService);
  protected theme = inject(ThemeService);
  private router = inject(Router);

  logout() {
    this.auth.logout().subscribe(() => this.router.navigate(['/login']));
  }

  navigateTo(path: string) {
    this.router.navigate([path]);
  }
}
