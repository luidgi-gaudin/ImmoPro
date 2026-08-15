import { Injectable, computed, signal } from '@angular/core';

/**
 * Catégories de stockage réellement utilisées par ImmoPro.
 *
 * Volontairement limitées à ce que l'application dépose pour de vrai. Annoncer
 * une catégorie « statistiques » ou « marketing » inexistante serait faux, et le
 * RGPD impose d'informer sur les traceurs effectivement présents.
 */
export type ConsentCategory = 'necessaires' | 'preferences';

export interface ConsentChoices {
  /** Session et authentification. Toujours actif : sans lui, pas de connexion. */
  necessaires: true;
  /** Mémorisation du thème clair/sombre. */
  preferences: boolean;
}

interface StoredConsent {
  version: number;
  decidedAt: string;
  choices: ConsentChoices;
}

const STORAGE_KEY = 'cookie_consent';

/**
 * Incrémenter cette version remet tout le monde devant le bandeau. À faire
 * uniquement si les finalités changent — pas à chaque retouche de texte.
 */
const CONSENT_VERSION = 1;

@Injectable({ providedIn: 'root' })
export class CookieConsentService {
  private readonly stored = signal<StoredConsent | null>(this.read());

  /** Le bandeau ne s'affiche que tant qu'aucun choix valide n'a été enregistré. */
  readonly needsDecision = computed(() => this.stored() === null);

  readonly choices = computed<ConsentChoices>(
    () => this.stored()?.choices ?? { necessaires: true, preferences: false },
  );

  readonly decidedAt = computed(() => this.stored()?.decidedAt ?? null);

  /** Le thème n'est mémorisé que si l'utilisateur l'a accepté. */
  readonly allowsPreferences = computed(() => this.choices().preferences);

  acceptAll(): void {
    this.save({ necessaires: true, preferences: true });
  }

  /**
   * Refus : seul le strictement nécessaire subsiste.
   *
   * Le refus doit rester aussi simple que l'acceptation — un clic, au même
   * niveau de lecture que « Tout accepter ». C'est une exigence de la CNIL, pas
   * une préférence de design.
   */
  rejectAll(): void {
    this.save({ necessaires: true, preferences: false });
  }

  save(choices: ConsentChoices): void {
    const record: StoredConsent = {
      version: CONSENT_VERSION,
      decidedAt: new Date().toISOString(),
      choices: { ...choices, necessaires: true },
    };

    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(record));
    } catch {
      // Navigation privée ou stockage saturé : on garde le choix en mémoire
      // pour la session en cours plutôt que de bloquer l'utilisateur.
    }

    this.stored.set(record);

    if (!record.choices.preferences) {
      this.purgePreferences();
    }
  }

  /** Rouvre le bandeau : utilisé par le lien « Modifier mes choix ». */
  reopen(): void {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch {
      // Ignoré : l'état en mémoire suffit à réafficher le bandeau.
    }
    this.stored.set(null);
  }

  /**
   * Retire ce qui n'est plus consenti.
   *
   * Un refus qui laisserait les données en place ne vaudrait rien : le retrait
   * du consentement doit avoir un effet concret et immédiat.
   */
  private purgePreferences(): void {
    try {
      localStorage.removeItem('theme');
    } catch {
      // Ignoré.
    }
  }

  private read(): StoredConsent | null {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return null;
      }

      const parsed = JSON.parse(raw) as StoredConsent;

      // Un enregistrement d'une version antérieure ne vaut plus consentement.
      if (parsed?.version !== CONSENT_VERSION) {
        return null;
      }

      return parsed;
    } catch {
      return null;
    }
  }
}
