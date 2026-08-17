import { Component, inject, signal, computed, ChangeDetectionStrategy } from '@angular/core';
import { Router, RouterOutlet, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs/operators';
import { SidebarComponent } from './shared/components/sidebar/sidebar.component';
import { TopbarComponent } from './shared/components/topbar/topbar.component';
import { GlobalSearchComponent } from './shared/components/global-search/global-search.component';
import { CookieBannerComponent } from './shared/components/cookie-banner/cookie-banner.component';
import { BreadcrumbComponent } from './shared/components/breadcrumb/breadcrumb.component';
import { StickyCtaComponent } from './shared/components/sticky-cta/sticky-cta.component';
import { AuthService } from './core/services/auth.service';
import { AnalyticsService } from './core/services/analytics.service';
import { BreadcrumbService } from './core/seo/breadcrumb.service';
import { SeoService, buildLocalBusinessJsonLd } from './core/seo/seo.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [
    RouterOutlet,
    SidebarComponent,
    TopbarComponent,
    GlobalSearchComponent,
    CookieBannerComponent,
    BreadcrumbComponent,
    StickyCtaComponent,
  ],
  templateUrl: './app.html',
  styleUrl: './app.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class App {
  private authService = inject(AuthService);
  private router = inject(Router);

  // Instanciés ici pour suivre la navigation dès le démarrage, y compris sur les
  // pages publiques où aucun composant ne les injecte.
  private readonly breadcrumbs = inject(BreadcrumbService);
  private readonly analytics = inject(AnalyticsService);

  protected readonly title = signal('frontend');

  // Track current URL as a Signal for Zoneless change detection compatibility
  private currentUrl = signal<string>('');

  // Le shell (sidebar + topbar) n'apparaît que sur l'espace applicatif : ni sur
  // la landing, ni sur l'authentification, ni sur les pages publiques (FAQ,
  // légales) qui portent leur propre mise en page.
  private readonly publicPrefixes = [
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/faq',
    '/confidentialite',
    '/cookies',
  ];

  protected showChrome = computed(() => {
    const url = this.currentUrl();
    return (
      this.authService.isAuthenticated() &&
      url !== '/' &&
      !this.publicPrefixes.some((p) => url.startsWith(p))
    );
  });

  constructor() {
    // Fiche entreprise : décrit l'éditeur, pas la page. Injectée à l'exécution
    // (Google exécute le JS), ce qui garde `site.config.ts` comme source unique.
    inject(SeoService).setJsonLd('local-business', buildLocalBusinessJsonLd());

    // Set initial URL
    this.currentUrl.set(this.router.url);

    // Track route changes
    this.router.events
      .pipe(filter((event) => event instanceof NavigationEnd))
      .subscribe((event: any) => {
        this.currentUrl.set(event.urlAfterRedirects || event.url);
      });
  }
}
