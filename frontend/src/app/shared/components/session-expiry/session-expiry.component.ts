import { ChangeDetectionStrategy, Component, effect, inject } from '@angular/core';
import { Router } from '@angular/router';
import { ImmoproButtonComponent, ImmoproModalComponent } from 'ui-lib';
import { AuthService } from '../../../core/services/auth.service';
import { SessionService } from '../../../core/services/session.service';

/**
 * Avertissement avant la fermeture de session.
 *
 * Une session qui s'éteint sans prévenir se découvre en validant un formulaire :
 * l'appel part, revient en 401, et la saisie est perdue. Le préavis existe pour
 * cela — pas pour informer, mais pour laisser le temps d'agir.
 *
 * Deux cas, deux messages. L'inactivité se rattrape d'un clic ; la durée
 * maximale, non — proposer « rester connecté » y serait mensonger, on invite
 * donc à enregistrer avant de se reconnecter.
 */
@Component({
  selector: 'app-session-expiry',
  standalone: true,
  imports: [ImmoproModalComponent, ImmoproButtonComponent],
  templateUrl: './session-expiry.component.html',
  styleUrl: './session-expiry.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SessionExpiryComponent {
  protected readonly session = inject(SessionService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  constructor() {
    // Le compte à rebours atteint zéro : la session est close côté serveur, on
    // range la nôtre plutôt que d'attendre le premier 401 pour le découvrir.
    effect(() => {
      if (this.session.hasEnded()) {
        this.auth.clearSession();
        this.session.clear();
        this.router.navigate(['/login'], {
          queryParams: { expired: 1, returnUrl: this.router.url },
        });
      }
    });
  }

  protected stayConnected(): void {
    this.session.extend();
  }

  protected signOutNow(): void {
    this.auth.logout().subscribe({
      next: () => this.router.navigate(['/login']),
      error: () => this.router.navigate(['/login']),
    });
  }
}
