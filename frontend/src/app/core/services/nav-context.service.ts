import { Injectable, signal } from '@angular/core';

export interface ContextNavLink {
  label: string;
  path: string;
  exact?: boolean;
}

export interface NavContext {
  /** Libellé du groupe contextuel (ex: nom du portefeuille courant). */
  title: string;
  /** Lien de retour vers la liste parente. */
  backLabel: string;
  backLink: string;
  /** Liens secondaires propres au contexte courant (ex: Vue d'ensemble, Actifs). */
  links: ContextNavLink[];
}

/**
 * Permet à une page « feature » de faire apparaître une section de navigation
 * contextuelle dans le menu latéral (ex: sous-navigation d'un portefeuille).
 * La page appelante doit nettoyer le contexte dans son ngOnDestroy.
 */
@Injectable({ providedIn: 'root' })
export class NavContextService {
  readonly context = signal<NavContext | null>(null);

  set(context: NavContext): void {
    this.context.set(context);
  }

  clear(): void {
    this.context.set(null);
  }
}
