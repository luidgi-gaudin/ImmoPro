import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { ImmoproButtonComponent } from 'ui-lib';
import { AuthService } from '../../core/services/auth.service';

/**
 * Page 404. Les sorties proposées dépendent de l'état de connexion : vitrine et
 * FAQ pour un visiteur, écrans applicatifs pour un utilisateur connecté.
 */
@Component({
  selector: 'app-not-found',
  standalone: true,
  imports: [RouterLink, ImmoproButtonComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="nf-page">
      <p class="nf-code" aria-hidden="true">404</p>

      <h1 class="nf-title">Cette page n’existe pas</h1>
      <p class="nf-lead">
        L’adresse <code>{{ requestedPath }}</code> ne correspond à aucune page d’ImmoPro. Elle a pu
        être renommée, ou le lien qui vous a mené ici comporte une coquille.
      </p>

      <div class="nf-actions">
        @if (auth.isAuthenticated()) {
          <a routerLink="/dashboard">
            <immopro-button>Retour au tableau de bord</immopro-button>
          </a>
          <a routerLink="/">
            <immopro-button color="secondary">Page d’accueil</immopro-button>
          </a>
        } @else {
          <a routerLink="/">
            <immopro-button>Retour à l’accueil</immopro-button>
          </a>
          <a routerLink="/login">
            <immopro-button color="secondary">Se connecter</immopro-button>
          </a>
        }
      </div>

      <nav class="nf-suggestions" aria-label="Pages utiles">
        <h2>Vous cherchiez peut-être</h2>
        <ul>
          @if (auth.isAuthenticated()) {
            <li><a routerLink="/portfolios">Mes portefeuilles</a></li>
            <li><a routerLink="/tenants">Mes locataires</a></li>
            <li><a routerLink="/leases">Mes baux</a></li>
            <li><a routerLink="/reports">Mes rapports</a></li>
          } @else {
            <li><a routerLink="/register">Créer un compte</a></li>
          }
          <li><a routerLink="/faq">Questions fréquentes</a></li>
          <li><a routerLink="/confidentialite">Politique de confidentialité</a></li>
          <li><a routerLink="/cookies">Gestion des cookies</a></li>
        </ul>
      </nav>
    </div>
  `,
  styles: `
    .nf-page {
      max-width: 620px;
      margin: 0 auto;
      padding: clamp(56px, 12vh, 120px) 24px 80px;
      text-align: center;
    }

    .nf-code {
      margin: 0 0 10px;
      font-family: 'Space Grotesk', system-ui, sans-serif;
      font-size: clamp(4.5rem, 16vw, 7rem);
      font-weight: 700;
      line-height: 1;
      letter-spacing: -0.05em;
      color: var(--primary);
      opacity: 0.22;
    }

    .nf-title {
      margin: 0 0 16px;
      font-size: clamp(1.6rem, 4vw, 2.2rem);
      font-weight: 500;
    }

    .nf-lead {
      margin: 0 auto 34px;
      max-width: 52ch;
      color: var(--text-secondary);
      font-size: 1rem;
      line-height: 1.7;

      code {
        padding: 2px 7px;
        border-radius: var(--radius-sm);
        background: var(--overlay-strong);
        border: 1px solid var(--border);
        color: var(--text-primary);
        font-size: 0.88em;
        /* Une URL longue ne doit pas élargir la page. */
        overflow-wrap: anywhere;
      }
    }

    .nf-actions {
      display: flex;
      justify-content: center;
      gap: 14px;
      flex-wrap: wrap;

      @media (max-width: 480px) {
        flex-direction: column;
        align-items: stretch;
      }
    }

    .nf-suggestions {
      margin-top: 56px;
      padding-top: 32px;
      border-top: 1px solid var(--border);

      h2 {
        margin: 0 0 16px;
        font-size: 0.78rem;
        font-weight: 600;
        letter-spacing: 0.09em;
        text-transform: uppercase;
        color: var(--text-muted);
      }

      ul {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 10px 22px;
        margin: 0;
        padding: 0;
        list-style: none;
      }

      a {
        color: var(--text-secondary);
        font-size: 0.9rem;
        text-decoration: none;
        border-bottom: 1px solid transparent;

        &:hover {
          color: var(--primary);
          border-bottom-color: var(--primary);
        }
      }
    }
  `,
})
export class NotFoundComponent {
  protected readonly auth = inject(AuthService);

  /** Interpolé, donc échappé par Angular : une URL forgée n'injecte rien. */
  protected readonly requestedPath = inject(Router).url.split(/[?#]/)[0];
}
