import { Component, inject, ChangeDetectionStrategy } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { LogoComponent } from '../logo/logo.component';
import { AlertService } from '../../../core/services/alert.service';
import { LayoutService } from '../../../core/services/layout.service';
import { NavContextService } from '../../../core/services/nav-context.service';

/**
 * Barre latérale de navigation persistante (desktop) / drawer (mobile).
 * Navigation principale de l'espace de gestion locative, complétée par une
 * section contextuelle quand une page (ex: un portefeuille) en publie une,
 * et un aperçu des fonctionnalités à venir.
 */
@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [RouterLink, RouterLinkActive, LogoComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <aside class="sidebar" [class.open]="layout.sidebarOpen()">
      <a class="sidebar-brand" routerLink="/dashboard" (click)="layout.closeSidebar()">
        <app-logo [size]="32" />
      </a>

      @if (navContext.context(); as ctx) {
        <div class="sidebar-context">
          <a class="context-back" [routerLink]="ctx.backLink" (click)="layout.closeSidebar()">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            <span>{{ ctx.backLabel }}</span>
          </a>
          <div class="context-title">{{ ctx.title }}</div>
          <nav class="context-nav" aria-label="Navigation du contexte courant">
            @for (link of ctx.links; track link.path) {
              <a [routerLink]="link.path" routerLinkActive="active" (click)="layout.closeSidebar()">{{ link.label }}</a>
            }
          </nav>
        </div>
      }

      <nav class="sidebar-nav" aria-label="Navigation principale">
        <a routerLink="/dashboard" routerLinkActive="active" (click)="layout.closeSidebar()">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
          <span>Tableau de bord</span>
        </a>

        <a routerLink="/portfolios" routerLinkActive="active" (click)="layout.closeSidebar()">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 11h.01"/><path d="M15 11h.01"/></svg>
          <span>Portefeuilles</span>
        </a>

        <a routerLink="/tenants" routerLinkActive="active" (click)="layout.closeSidebar()">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span>Locataires</span>
        </a>

        <a routerLink="/leases" routerLinkActive="active" (click)="layout.closeSidebar()">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
          <span>Baux</span>
        </a>

        <a routerLink="/alerts" routerLinkActive="active" (click)="layout.closeSidebar()">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          <span>Alertes</span>
          @if (alerts.unreadCount() > 0) {
            <span class="nav-badge">{{ alerts.unreadCount() > 99 ? '99+' : alerts.unreadCount() }}</span>
          }
        </a>
      </nav>

      <div class="sidebar-roadmap">
        <span class="roadmap-label">Bientôt disponible</span>
        <span class="roadmap-item" title="Stockage des diagnostics, baux signés et justificatifs — à venir">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
          <span>Documents</span>
          <span class="soon-badge">Bientôt</span>
        </span>
        <span class="roadmap-item" title="Rapports financiers et exports comptables — à venir">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
          <span>Rapports</span>
          <span class="soon-badge">Bientôt</span>
        </span>
      </div>

      <div class="sidebar-footer">
        <a routerLink="/profile" routerLinkActive="active" (click)="layout.closeSidebar()">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Mon profil</span>
        </a>
      </div>
    </aside>

    <div class="sidebar-scrim" [class.show]="layout.sidebarOpen()" (click)="layout.closeSidebar()"></div>
  `,
  styleUrl: './sidebar.component.scss',
})
export class SidebarComponent {
  protected alerts = inject(AlertService);
  protected layout = inject(LayoutService);
  protected navContext = inject(NavContextService);
}
