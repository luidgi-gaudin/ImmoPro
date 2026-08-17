import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { filter } from 'rxjs/operators';
import { AuthService } from '../../../core/services/auth.service';
import { CookieConsentService } from '../../../core/services/cookie-consent.service';

/** Pages vitrines : les seules où une barre d'appel à l'action a du sens. */
const PUBLIC_PATHS = ['/', '/faq', '/confidentialite', '/cookies'];

/**
 * Barre d'appel à l'action collante, mobile et pages vitrines uniquement.
 * Elle s'efface tant que le bandeau de consentement attend une réponse : deux
 * panneaux superposés en bas d'écran se neutraliseraient.
 */
@Component({
  selector: 'app-sticky-cta',
  standalone: true,
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  // L'hôte sert d'espaceur en flux : la barre étant en position fixe, elle
  // recouvrirait sinon le pied de page.
  host: { '[class.is-visible]': 'visible()' },
  template: `
    @if (visible()) {
      <div class="ip-sticky-cta">
        @if (auth.isAuthenticated()) {
          <a class="cta cta--primary" routerLink="/dashboard">Accéder au tableau de bord</a>
        } @else {
          <a class="cta cta--ghost" routerLink="/login">Se connecter</a>
          <a class="cta cta--primary" routerLink="/register">Créer un compte</a>
        }
      </div>
    }
  `,
  styles: `
    :host {
      display: block;
    }

    :host(.is-visible) {
      height: calc(70px + env(safe-area-inset-bottom));
    }

    @media (min-width: 901px) {
      :host(.is-visible) {
        height: 0;
      }
    }

    .ip-sticky-cta {
      position: fixed;
      inset: auto 0 0 0;
      z-index: 90;
      display: flex;
      gap: 10px;
      padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
      background: color-mix(in srgb, var(--surface-card) 88%, transparent);
      backdrop-filter: blur(14px);
      border-top: 1px solid var(--border);
      box-shadow: 0 -8px 24px -18px rgba(var(--shadow-rgb), 0.6);
      animation: ipStickyRise 320ms cubic-bezier(0.16, 1, 0.3, 1);

      /* Au-delà du pli mobile, le bouton du héros est déjà visible. */
      @media (min-width: 901px) {
        display: none;
      }
    }

    @keyframes ipStickyRise {
      from {
        transform: translateY(100%);
      }
      to {
        transform: translateY(0);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .ip-sticky-cta {
        animation: none;
      }
    }

    .cta {
      flex: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      /* Au-dessus du minimum de 44px pour une cible tactile. */
      min-height: 46px;
      padding: 0 18px;
      border-radius: var(--radius-sm);
      border: 1px solid transparent;
      font-family: inherit;
      font-size: 0.92rem;
      font-weight: 600;
      text-decoration: none;
      transition: background-color var(--transition-fast);

      &:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
      }
    }

    .cta--primary {
      background: var(--primary);
      border-color: var(--primary);
      color: var(--on-primary);

      &:hover,
      &:active {
        background: var(--primary-hover);
        color: var(--on-primary);
      }
    }

    .cta--ghost {
      flex: 0 1 40%;
      background: transparent;
      border-color: var(--border-hover);
      color: var(--text-primary);

      &:hover,
      &:active {
        color: var(--primary);
        border-color: var(--primary);
      }
    }
  `,
})
export class StickyCtaComponent {
  protected readonly auth = inject(AuthService);
  private readonly consent = inject(CookieConsentService);
  private readonly router = inject(Router);

  private readonly path = signal(stripQuery(this.router.url));

  protected readonly visible = computed(
    () => PUBLIC_PATHS.includes(this.path()) && !this.consent.needsDecision(),
  );

  constructor() {
    this.router.events
      .pipe(
        filter((event): event is NavigationEnd => event instanceof NavigationEnd),
        takeUntilDestroyed(),
      )
      .subscribe((event) => this.path.set(stripQuery(event.urlAfterRedirects || event.url)));
  }
}

function stripQuery(url: string): string {
  const path = url.split(/[?#]/)[0];
  return path.length > 1 ? path.replace(/\/+$/, '') : path;
}
