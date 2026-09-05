import { Injectable, signal } from '@angular/core';

/** Seuil au-delà duquel le shell affiche la barre latérale et les vues à deux colonnes. */
const NARROW_QUERY = '(max-width: 900px)';

/**
 * État de mise en page partagé par le shell applicatif.
 *
 * Pilote l'ouverture du menu latéral sur mobile (drawer) et publie la largeur
 * de l'écran sous forme de signal.
 *
 * La bascule liste / fiche des écrans maître-détail se fait en CSS. Mais
 * certains gestes qui l'accompagnent n'ont de sens qu'à l'étroit et ne
 * s'expriment pas en CSS : sur téléphone, ouvrir une fiche doit remonter la
 * page en haut, faute de quoi on arrive au milieu du contenu, à la hauteur où
 * l'on avait touché la carte. Le composant a donc besoin de savoir, en
 * TypeScript, s'il est en vue étroite.
 */
@Injectable({ providedIn: 'root' })
export class LayoutService {
  /** Menu latéral ouvert (uniquement pertinent en vue mobile). */
  readonly sidebarOpen = signal(false);

  /** Vrai sous 900 px : la barre latérale est un tiroir, les vues s'empilent. */
  readonly isNarrow = signal(false);

  constructor() {
    // `matchMedia` n'existe pas au rendu côté serveur ; l'écran est alors
    // supposé large, ce qui rend la version la plus complète.
    if (typeof window === 'undefined' || !window.matchMedia) {
      return;
    }

    const query = window.matchMedia(NARROW_QUERY);

    this.isNarrow.set(query.matches);

    // Aucun désabonnement : le service est fourni à la racine, il vit aussi
    // longtemps que l'application. Un `DestroyRef` ici ne servirait qu'à
    // déclarer une intention que rien n'exécute jamais.
    query.addEventListener('change', (event) => this.isNarrow.set(event.matches));
  }

  toggleSidebar(): void {
    this.sidebarOpen.update((open) => !open);
  }

  closeSidebar(): void {
    this.sidebarOpen.set(false);
  }
}
