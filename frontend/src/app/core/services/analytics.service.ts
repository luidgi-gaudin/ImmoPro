import { DOCUMENT, Injectable, effect, inject } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { Subscription, filter } from 'rxjs';
import { SITE } from '../config/site.config';
import { CookieConsentService } from './cookie-consent.service';

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

/**
 * Google Analytics 4, subordonné au consentement.
 *
 * Aucun script n'est chargé avant accord explicite, et le retrait du
 * consentement coupe les envois puis supprime les cookies `_ga*`. Sans
 * identifiant dans `site.config.ts`, le service ne fait rien.
 */
@Injectable({ providedIn: 'root' })
export class AnalyticsService {
  private readonly consent = inject(CookieConsentService);
  private readonly router = inject(Router);
  private readonly doc = inject(DOCUMENT);

  private scriptLoaded = false;
  private pageViews?: Subscription;

  constructor() {
    effect(() => {
      const allowed = this.consent.allowsStatistics();

      if (!SITE.gaMeasurementId) return;

      if (allowed) {
        this.enable();
      } else {
        this.disable();
      }
    });
  }

  /** Événement métier ponctuel. Sans consentement, gtag n'existe pas : sans effet. */
  track(name: string, params: Record<string, unknown> = {}): void {
    this.doc.defaultView?.gtag?.('event', name, params);
  }

  private enable(): void {
    const view = this.doc.defaultView;
    if (!view) return;

    // Un refus puis un accord dans la même session doit repartir.
    this.setDisableFlag(view, false);

    if (!this.scriptLoaded) {
      this.loadScript(view);
      this.scriptLoaded = true;
    }

    this.pageViews ??= this.router.events
      .pipe(filter((event): event is NavigationEnd => event instanceof NavigationEnd))
      .subscribe((event) => this.sendPageView(event.urlAfterRedirects || event.url));

    // La navigation en cours n'émettra plus de `NavigationEnd` : sans cet appel,
    // la vue serait perdue pour qui accepte depuis le bandeau après son arrivée.
    this.sendPageView(this.router.url);
  }

  private loadScript(view: Window): void {
    const dataLayer = (view.dataLayer ??= []);

    view.gtag = function gtag() {
      // gtag.js attend l'objet `arguments` lui-même, comme l'extrait officiel.
      // eslint-disable-next-line prefer-rest-params
      dataLayer.push(arguments);
    };

    view.gtag('js', new Date());
    view.gtag('config', SITE.gaMeasurementId, {
      // Envoi manuel : sur une application à page unique, l'automatique ne
      // compterait que le premier écran.
      send_page_view: false,
    });

    const script = this.doc.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(
      SITE.gaMeasurementId,
    )}`;
    this.doc.head.appendChild(script);
  }

  private sendPageView(url: string): void {
    const view = this.doc.defaultView;
    view?.gtag?.('event', 'page_view', {
      page_path: url,
      page_title: this.doc.title,
      page_location: view.location.href,
    });
  }

  private disable(): void {
    const view = this.doc.defaultView;
    if (!view) return;

    this.pageViews?.unsubscribe();
    this.pageViews = undefined;

    this.setDisableFlag(view, true);
    this.purgeCookies(view);
  }

  /** `window['ga-disable-G-XXXX']` : coupure documentée par Google, même script chargé. */
  private setDisableFlag(view: Window, disabled: boolean): void {
    (view as unknown as Record<string, boolean>)[`ga-disable-${SITE.gaMeasurementId}`] = disabled;
  }

  /**
   * Un cookie ne s'efface qu'en réécrivant le même couple domaine / chemin. GA
   * les pose sur le domaine enregistrable, d'où le balayage des suffixes.
   */
  private purgeCookies(view: Window): void {
    const names = this.doc.cookie
      .split(';')
      .map((entry) => entry.split('=')[0].trim())
      .filter((name) => name.startsWith('_ga') || name.startsWith('_gid'));

    if (!names.length) return;

    const parts = view.location.hostname.split('.');
    const domains = ['', ...parts.map((_, index) => `.${parts.slice(index).join('.')}`)];

    for (const name of names) {
      for (const domain of domains) {
        this.doc.cookie = `${name}=; Max-Age=0; path=/${domain ? `; domain=${domain}` : ''}`;
      }
    }
  }
}
