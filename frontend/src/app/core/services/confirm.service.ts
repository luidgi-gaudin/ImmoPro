import { Injectable, signal } from '@angular/core';

export interface ConfirmRequest {
  title: string;
  message: string;
  /** Libellé du bouton d'action. « Supprimer » vaut mieux que « OK ». */
  confirmLabel?: string;
  cancelLabel?: string;
  /** Colore l'action en rouge et exige un second regard. */
  danger?: boolean;
  /**
   * Mot à saisir pour débloquer l'action, sur les suppressions en cascade.
   * Un portefeuille emporte ses biens, ses baux et ses quittances : cela ne
   * doit pas pouvoir se faire d'un clic distrait.
   */
  typeToConfirm?: string;
}

interface PendingConfirm extends ConfirmRequest {
  resolve: (confirmed: boolean) => void;
}

/**
 * Demandes de confirmation, à la place de `window.confirm`.
 *
 * `window.confirm` bloque le fil d'exécution du navigateur, ne se met pas au
 * thème de l'application, ne se traduit pas, ne se teste pas, et surtout
 * n'affiche qu'un texte brut : impossible d'y rappeler ce qui sera supprimé
 * avec l'élément. Or c'est précisément ce qu'il faut dire avant d'effacer un
 * portefeuille qui emporte quinze baux.
 */
@Injectable({ providedIn: 'root' })
export class ConfirmService {
  private readonly _pending = signal<PendingConfirm | null>(null);
  readonly pending = this._pending.asReadonly();

  ask(request: ConfirmRequest): Promise<boolean> {
    return new Promise<boolean>((resolve) => {
      this._pending.set({ ...request, resolve });
    });
  }

  answer(confirmed: boolean): void {
    const pending = this._pending();

    this._pending.set(null);
    pending?.resolve(confirmed);
  }
}
