import { ActivatedRouteSnapshot, RouterStateSnapshot } from '@angular/router';

/**
 * Métadonnées portées par une route. Le titre passe par la propriété `title`
 * native du routeur, lue par la `TitleStrategy`.
 */
export interface PageMeta {
  /** Meta description, 140 à 160 caractères. */
  description?: string;

  /** Libellé du maillon de fil d'Ariane pour ce segment. */
  breadcrumb?: string;

  /**
   * Maillons à insérer avant celui de la route, quand la hiérarchie logique ne
   * suit pas l'imbrication des routes (`tenants/:id` est déclarée à côté de
   * `tenants`). L'URL accepte des jetons `:param`.
   */
  breadcrumbParents?: { label: string; url: string }[];

  /** Écrans privés, pages d'erreur, tunnels d'authentification. */
  noindex?: boolean;
}

/** Fusionne les `data` de la racine jusqu'à la feuille active. */
export function resolvePageMeta(snapshot: RouterStateSnapshot): PageMeta {
  let route: ActivatedRouteSnapshot | null = snapshot.root;
  const meta: PageMeta = {};

  while (route) {
    const data = route.data as PageMeta;
    if (data.description !== undefined) meta.description = data.description;
    if (data.noindex !== undefined) meta.noindex = data.noindex;
    route = route.firstChild;
  }

  return meta;
}

/** Chemin canonique : ni paramètres de requête, ni ancre, ni slash final. */
export function canonicalPath(url: string): string {
  const path = url.split(/[?#]/)[0];
  if (path === '/' || path === '') return '/';
  return path.replace(/\/+$/, '');
}
