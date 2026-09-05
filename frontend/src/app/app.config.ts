import {
  ApplicationConfig,
  DEFAULT_CURRENCY_CODE,
  LOCALE_ID,
  provideZonelessChangeDetection,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { TitleStrategy, provideRouter, withInMemoryScrolling } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';

import { routes } from './app.routes';
import { authInterceptor } from './core/interceptors/auth.interceptor';
import { ImmoproTitleStrategy } from './core/seo/title.strategy';

/*
 * Locale française, posée une fois pour toute l'application.
 *
 * Sans elle, Angular retombe sur `en-US` : un échéancier affichait « October
 * 2026 » et « March 2026 » dans une application par ailleurs entièrement en
 * français, et les montants sortaient en $ dès qu'on passait par le pipe
 * `currency`. C'est un réglage global, pas une correction à faire écran par
 * écran.
 */
registerLocaleData(localeFr, 'fr-FR');

export const appConfig: ApplicationConfig = {
  providers: [
    provideZonelessChangeDetection(),
    provideBrowserGlobalErrorListeners(),
    provideRouter(
      routes,
      // Sinon on arrive au milieu de la page suivante, CTA du haut hors écran.
      withInMemoryScrolling({ scrollPositionRestoration: 'enabled', anchorScrolling: 'enabled' }),
    ),
    provideHttpClient(withInterceptors([authInterceptor])),
    // Titre, meta description, canonique et balises de partage à chaque navigation.
    { provide: TitleStrategy, useClass: ImmoproTitleStrategy },
    { provide: LOCALE_ID, useValue: 'fr-FR' },
    { provide: DEFAULT_CURRENCY_CODE, useValue: 'EUR' },
  ],
};
