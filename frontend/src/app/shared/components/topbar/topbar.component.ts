import { Component, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs/operators';
import { AuthService } from '../../../core/services/auth.service';
import { ThemeService } from '../../../core/services/theme.service';
import { AlertService } from '../../../core/services/alert.service';
import { LayoutService } from '../../../core/services/layout.service';

/**
 * Barre supérieure du shell : ouverture du menu (mobile), titre de section,
 * accès aux alertes, bascule de thème et menu utilisateur.
 */
@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [RouterLink, RouterLinkActive],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="topbar">
      <div class="topbar-left">
        <button class="icon-btn burger" (click)="layout.toggleSidebar()" aria-label="Ouvrir le menu">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <h1 class="section-title">{{ sectionLabel() }}</h1>
      </div>

      <div class="topbar-right">
        <a class="icon-btn bell" routerLink="/alerts" routerLinkActive="active"
           [title]="alerts.unreadCount() > 0 ? alerts.unreadCount() + ' alerte(s) non lue(s)' : 'Voir mes alertes'"
           aria-label="Voir mes alertes">
          <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          @if (alerts.unreadCount() > 0) {
            <span class="badge-dot">{{ alerts.unreadCount() > 9 ? '9+' : alerts.unreadCount() }}</span>
          }
        </a>

        <button class="icon-btn" (click)="theme.toggleTheme()"
                [title]="theme.isLightTheme() ? 'Mode sombre' : 'Mode clair'" aria-label="Changer de thème">
          @if (theme.isLightTheme()) {
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
          } @else {
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
          }
        </button>

        @if (auth.currentUser(); as user) {
          <div class="user-menu">
            <button class="user-chip" (click)="toggleMenu()" [class.open]="menuOpen()" aria-haspopup="menu">
              <span class="user-avatar">{{ user.name.charAt(0) || 'U' }}</span>
              <span class="user-name-label">{{ user.name }}</span>
              <svg class="chevron" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            @if (menuOpen()) {
              <div class="menu-backdrop" (click)="closeMenu()"></div>
              <div class="menu-dropdown" role="menu">
                <div class="menu-head">
                  <strong>{{ user.name }}</strong>
                  <span class="text-muted">{{ user.email }}</span>
                </div>
                <a routerLink="/profile" (click)="closeMenu()" role="menuitem">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  Mon profil
                </a>
                <button class="danger" (click)="logout()" role="menuitem">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                  Déconnexion
                </button>
              </div>
            }
          </div>
        }
      </div>
    </header>
  `,
  styleUrl: './topbar.component.scss',
})
export class TopbarComponent {
  protected auth = inject(AuthService);
  protected theme = inject(ThemeService);
  protected alerts = inject(AlertService);
  protected layout = inject(LayoutService);
  private router = inject(Router);

  protected readonly menuOpen = signal(false);
  protected readonly sectionLabel = signal<string>(this.labelFor(this.router.url));

  constructor() {
    this.router.events
      .pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd))
      .subscribe((e) => this.sectionLabel.set(this.labelFor(e.urlAfterRedirects || e.url)));
  }

  toggleMenu(): void {
    this.menuOpen.update((open) => !open);
  }

  closeMenu(): void {
    this.menuOpen.set(false);
  }

  logout(): void {
    this.closeMenu();
    this.auth.logout().subscribe(() => this.router.navigate(['/login']));
  }

  /** Libellé de la section courante affiché dans la barre. */
  private labelFor(url: string): string {
    const path = url.split('?')[0];
    if (path.startsWith('/portfolios/')) return 'Détail du portefeuille';
    const map: Record<string, string> = {
      '/dashboard': 'Tableau de bord',
      '/portfolios': 'Portefeuilles',
      '/tenants': 'Locataires',
      '/leases': 'Baux',
      '/alerts': 'Alertes',
      '/profile': 'Mon profil',
    };
    return map[path] ?? 'ImmoPro';
  }
}
