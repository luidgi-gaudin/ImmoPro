import { Injectable, signal } from '@angular/core';

export type NotificationTone = 'success' | 'error' | 'info';

export interface AppNotification {
  id: number;
  tone: NotificationTone;
  message: string;
  /** Action facultative offerte dans le bandeau (« Annuler », « Voir »). */
  action?: { label: string; run: () => void };
}

/**
 * Messages transitoires — enregistrement réussi, échec d'une action.
 *
 * Ces retours étaient jusqu'ici des chaînes stockées dans chaque composant,
 * affichées dans un encart en haut de page. Trois défauts : le message
 * disparaissait au changement d'écran alors que l'action venait d'aboutir, il
 * poussait le contenu vers le bas en apparaissant, et chaque écran réimplémentait
 * sa propre variante.
 *
 * Les erreurs restent affichées deux fois plus longtemps que les succès : un
 * succès se constate à l'écran, une erreur se lit.
 */
@Injectable({ providedIn: 'root' })
export class NotificationService {
  private readonly _items = signal<AppNotification[]>([]);
  readonly items = this._items.asReadonly();

  private nextId = 1;

  success(message: string, action?: AppNotification['action']): void {
    this.push('success', message, action, 4000);
  }

  error(message: string, action?: AppNotification['action']): void {
    this.push('error', message, action, 8000);
  }

  info(message: string, action?: AppNotification['action']): void {
    this.push('info', message, action, 5000);
  }

  /**
   * Traduit une erreur HTTP en une phrase compréhensible.
   *
   * Laravel renvoie tantôt `message`, tantôt un dictionnaire `errors` par champ.
   * Afficher « Http failure response for … 422 » n'apprend rien à personne ;
   * afficher la première erreur de validation, si.
   */
  fromHttp(error: unknown, fallback = 'Une erreur est survenue.'): void {
    this.error(this.describe(error, fallback));
  }

  describe(error: unknown, fallback = 'Une erreur est survenue.'): string {
    const payload = (error as { error?: unknown })?.error as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined;

    const firstFieldError = payload?.errors
      ? Object.values(payload.errors).flat().find(Boolean)
      : undefined;

    if (firstFieldError) {
      return firstFieldError;
    }

    if (payload?.message) {
      return payload.message;
    }

    const status = (error as { status?: number })?.status;

    if (status === 0) {
      return 'Le serveur est injoignable. Vérifiez votre connexion.';
    }

    return fallback;
  }

  dismiss(id: number): void {
    this._items.update((items) => items.filter((item) => item.id !== id));
  }

  private push(
    tone: NotificationTone,
    message: string,
    action: AppNotification['action'] | undefined,
    lifetimeMs: number,
  ): void {
    const id = this.nextId++;

    this._items.update((items) => [...items, { id, tone, message, action }]);

    setTimeout(() => this.dismiss(id), lifetimeMs);
  }
}
