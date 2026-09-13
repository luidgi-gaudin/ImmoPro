import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { AuthService, SocialProvider } from './auth.service';

/**
 * Obtention d'un jeton d'identité auprès de Google ou d'Apple.
 *
 * Le navigateur dialogue avec le fournisseur ; le serveur, lui, vérifie le
 * jeton rapporté. Rien de ce qui se passe ici ne vaut preuve : un jeton non
 * vérifié n'est qu'un texte, et c'est le contrôle de signature côté serveur qui
 * en fait une identité.
 *
 * Les bibliothèques des fournisseurs sont chargées à la demande, au premier
 * clic. Les inclure dans `index.html` ferait payer deux scripts tiers à chaque
 * visiteur, y compris à ceux qui se connectent par mot de passe et à ceux qui
 * ne se connectent pas du tout.
 */
@Injectable({ providedIn: 'root' })
export class SocialSignInService {
  private auth = inject(AuthService);

  /** Fournisseurs paramétrés côté serveur, chargés une seule fois. */
  readonly providers = signal<SocialProvider[]>([]);

  private loaded: Promise<SocialProvider[]> | null = null;
  private scripts = new Map<string, Promise<void>>();

  /**
   * Fournisseurs utilisables. Mémorisé : l'écran de connexion et celui
   * d'inscription posent la même question, et la réponse ne change pas.
   */
  async available(): Promise<SocialProvider[]> {
    this.loaded ??= firstValueFrom(this.auth.socialProviders())
      .then((response) =>
        response.data.filter((provider) => provider.enabled && provider.client_id),
      )
      .catch(() => [] as SocialProvider[]);

    const providers = await this.loaded;
    this.providers.set(providers);

    return providers;
  }

  /**
   * Ouvre la fenêtre du fournisseur et rend le jeton d'identité obtenu.
   *
   * Le `nonce` est engendré ici et repart avec le jeton : le serveur vérifie
   * qu'il s'y retrouve. C'est ce qui distingue une connexion en cours d'un
   * jeton intercepté puis rejoué plus tard.
   */
  async requestIdToken(provider: 'google' | 'apple'): Promise<{ id_token: string; nonce: string }> {
    const configured = (await this.available()).find((entry) => entry.value === provider);

    if (!configured?.client_id) {
      throw new Error(`La connexion ${provider} n'est pas disponible.`);
    }

    const nonce = this.nonce();

    const idToken =
      provider === 'google'
        ? await this.google(configured.client_id, nonce)
        : await this.apple(configured.client_id, nonce);

    return { id_token: idToken, nonce };
  }

  /* --------------------------------------------------------------------
   | Google Identity Services
   |--------------------------------------------------------------------*/

  private async google(clientId: string, nonce: string): Promise<string> {
    await this.script('google', 'https://accounts.google.com/gsi/client');

    const google = (window as any).google;

    if (!google?.accounts?.id) {
      throw new Error("La bibliothèque Google n'a pas pu être chargée.");
    }

    return new Promise<string>((resolve, reject) => {
      google.accounts.id.initialize({
        client_id: clientId,
        nonce,
        callback: (response: { credential?: string }) => {
          response.credential
            ? resolve(response.credential)
            : reject(new Error("Google n'a pas renvoyé de jeton."));
        },
      });

      /*
       * `prompt()` peut ne rien afficher — bloqueur de fenêtres, cookies
       * tiers refusés, ou refus répétés mémorisés par Google. Sans ce
       * rappel, le bouton resterait en chargement indéfiniment et
       * l'utilisateur n'aurait aucune idée de ce qui se passe.
       */
      google.accounts.id.prompt((notification: any) => {
        if (notification?.isNotDisplayed?.() || notification?.isSkippedMoment?.()) {
          reject(
            new Error(
              "La fenêtre Google ne s'est pas ouverte. Vérifiez que les cookies tiers " +
                'et les fenêtres surgissantes sont autorisés.',
            ),
          );
        }
      });
    });
  }

  /* --------------------------------------------------------------------
   | Sign in with Apple
   |--------------------------------------------------------------------*/

  private async apple(clientId: string, nonce: string): Promise<string> {
    await this.script(
      'apple',
      'https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/fr_FR/appleid.auth.js',
    );

    const apple = (window as any).AppleID;

    if (!apple?.auth) {
      throw new Error("La bibliothèque Apple n'a pas pu être chargée.");
    }

    apple.auth.init({
      clientId,
      scope: 'name email',
      redirectURI: window.location.origin,
      nonce,
      usePopup: true,
    });

    const response = await apple.auth.signIn();
    const idToken = response?.authorization?.id_token;

    if (!idToken) {
      throw new Error("Apple n'a pas renvoyé de jeton.");
    }

    return idToken;
  }

  /* --------------------------------------------------------------------
   | Assises
   |--------------------------------------------------------------------*/

  /** Charge un script tiers une seule fois, même sur plusieurs clics. */
  private script(key: string, src: string): Promise<void> {
    const existing = this.scripts.get(key);

    if (existing) {
      return existing;
    }

    const loading = new Promise<void>((resolve, reject) => {
      const element = document.createElement('script');
      element.src = src;
      element.async = true;
      element.defer = true;
      element.onload = () => resolve();
      element.onerror = () => {
        // Le retirer de la mémoire autorise une nouvelle tentative : un échec
        // réseau ne doit pas condamner le bouton pour toute la session.
        this.scripts.delete(key);
        reject(new Error('Le service de connexion externe est injoignable.'));
      };

      document.head.appendChild(element);
    });

    this.scripts.set(key, loading);

    return loading;
  }

  /**
   * Valeur à usage unique, tirée d'une source cryptographique.
   *
   * `Math.random` ne convient pas : elle est prévisible à partir de quelques
   * tirages, ce qui priverait le nonce de tout intérêt.
   */
  private nonce(): string {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  }
}
