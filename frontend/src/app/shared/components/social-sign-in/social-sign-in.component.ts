import { ChangeDetectionStrategy, Component, inject, input, output, signal } from '@angular/core';
import { SocialProvider } from '../../../core/services/auth.service';
import { SocialSignInService } from '../../../core/services/social-sign-in.service';

export interface SocialCredential {
  provider: 'google' | 'apple';
  id_token: string;
  nonce: string;
}

/**
 * Boutons « Continuer avec Google / Apple ».
 *
 * Ne se contente pas de masquer un bouton non configuré : il n'est pas rendu du
 * tout. Un bouton qui ouvre une fenêtre pour afficher une erreur de
 * configuration est plus déroutant qu'un bouton absent.
 *
 * Le composant obtient le jeton et le remonte ; il n'appelle pas l'API
 * lui-même. L'inscription doit y joindre le profil choisi, la connexion non, et
 * un composant partagé n'a pas à connaître cette différence.
 */
@Component({
  selector: 'app-social-sign-in',
  standalone: true,
  imports: [],
  templateUrl: './social-sign-in.component.html',
  styleUrls: ['./social-sign-in.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialSignInComponent {
  private social = inject(SocialSignInService);

  /** Texte du bouton : « Continuer avec » à la connexion, « S'inscrire avec » sinon. */
  readonly verb = input<string>('Continuer avec');

  /** Bloque les boutons pendant qu'un autre parcours est en cours. */
  readonly disabled = input<boolean>(false);

  readonly credential = output<SocialCredential>();
  readonly failed = output<string>();

  readonly providers = signal<SocialProvider[]>([]);
  readonly pending = signal<string | null>(null);

  constructor() {
    void this.social.available().then((providers) => this.providers.set(providers));
  }

  async choose(provider: 'google' | 'apple'): Promise<void> {
    if (this.disabled() || this.pending()) {
      return;
    }

    this.pending.set(provider);

    try {
      const { id_token, nonce } = await this.social.requestIdToken(provider);

      this.credential.emit({ provider, id_token, nonce });
    } catch (error) {
      /*
       * Une fenêtre fermée à la main n'est pas une panne : l'annoncer comme une
       * erreur ferait croire à un dysfonctionnement là où l'utilisateur a
       * simplement changé d'avis.
       */
      const message = error instanceof Error ? error.message : 'La connexion a été interrompue.';

      if (!/popup_closed|user_cancel|AbortError/i.test(message)) {
        this.failed.emit(message);
      }
    } finally {
      this.pending.set(null);
    }
  }
}
