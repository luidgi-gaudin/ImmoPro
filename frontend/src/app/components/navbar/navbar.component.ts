import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-navbar',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <header class="navbar-container">
      <div class="navbar-content">
        <div class="brand" (click)="navigateTo('/')">
          <span class="logo-icon">IP</span>
          <span class="logo-text">ImmoPro</span>
        </div>
        
        <nav class="main-nav">
          <a routerLink="/dashboard" routerLinkActive="active">Dashboard</a>
          <a routerLink="/portfolios" routerLinkActive="active">Portfolios</a>
          <a routerLink="/tenants" routerLinkActive="active">Locataires</a>
          <a routerLink="/leases" routerLinkActive="active">Baux</a>
        </nav>

        <div class="user-actions">
          <div class="user-info" *ngIf="user">
            <span class="user-name">{{ user.name }}</span>
          </div>
          <button class="btn-logout" *ngIf="isAuthenticated" (click)="logout()" title="Déconnexion">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          </button>
        </div>
      </div>
    </header>
  `,
  styles: [
    `
      .navbar-container {
        height: var(--header-height);
        background: rgba(9, 9, 11, 0.8);
        backdrop-filter: blur(8px);
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 100;
      }

      .navbar-content {
        max-width: 1200px;
        height: 100%;
        margin: 0 auto;
        padding: 0 40px;
        display: flex;
        align-items: center;
        justify-content: space-between;
      }

      .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        
        .logo-icon {
          background: var(--primary);
          color: white;
          width: 32px;
          height: 32px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: var(--radius-sm);
          font-weight: 700;
          font-size: 0.75rem;
        }
        
        .logo-text {
          font-weight: 600;
          font-size: 1.125rem;
          letter-spacing: -0.02em;
        }
      }

      .main-nav {
        display: flex;
        gap: 8px;
        
        a {
          padding: 6px 12px;
          border-radius: var(--radius-sm);
          color: var(--text-secondary);
          font-size: 0.875rem;
          font-weight: 500;
          transition: var(--transition-fast);
          
          &:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.05);
          }
          
          &.active {
            color: var(--text-primary);
            background: rgba(99, 102, 241, 0.1);
          }
        }
      }

      .user-actions {
        display: flex;
        align-items: center;
        gap: 16px;
      }

      .user-info {
        .user-name {
          font-size: 0.875rem;
          font-weight: 500;
          color: var(--text-secondary);
        }
      }

      .btn-logout {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-secondary);
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius-sm);
        cursor: pointer;
        transition: var(--transition-fast);
        
        &:hover {
          color: var(--error);
          border-color: rgba(239, 68, 68, 0.3);
          background: rgba(239, 68, 68, 0.05);
        }
      }
    `,
  ],
})
export class NavbarComponent {
  private auth = inject(AuthService);
  private router = inject(Router);

  get user() {
    return this.auth.getCurrentUser();
  }

  get isAuthenticated() {
    return this.auth.isAuthenticated();
  }

  logout() {
    this.auth.logout().subscribe(() => this.router.navigate(['/login']));
  }

  navigateTo(path: string) {
    this.router.navigate([path]);
  }
}
