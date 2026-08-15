import { HttpParams } from '@angular/common/http';
import { Observable, expand, map, reduce, takeWhile } from 'rxjs';

/**
 * Enveloppe renvoyée par toutes les listes de l'API. Le backend s'appuie sur le
 * paginateur de Laravel, dont les champs sont à plat à côté de « data ».
 */
export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

/** Sens de tri accepté par l'API. */
export type SortDirection = 'asc' | 'desc';

/**
 * Paramètres d'URL compris par toutes les listes. `filters` porte les critères
 * propres à chaque ressource (type, dpe, statut…).
 */
export interface ListParams {
  page: number;
  per_page: number;
  search: string;
  sort: string;
  direction: SortDirection;
  filters: Record<string, string>;
}

/**
 * Traduit l'état d'une liste en paramètres HTTP, en omettant tout ce qui est
 * vide. Une URL qui ne transporte que les critères réellement actifs reste
 * lisible, et se partage sans traîner de `?search=&type=&dpe=`.
 */
export function toHttpParams(params: Partial<ListParams>): HttpParams {
  let httpParams = new HttpParams();

  if (params.page && params.page > 1) {
    httpParams = httpParams.set('page', String(params.page));
  }
  if (params.per_page) {
    httpParams = httpParams.set('per_page', String(params.per_page));
  }
  if (params.search) {
    httpParams = httpParams.set('search', params.search);
  }
  if (params.sort) {
    httpParams = httpParams.set('sort', params.sort);
    httpParams = httpParams.set('direction', params.direction ?? 'asc');
  }

  for (const [key, value] of Object.entries(params.filters ?? {})) {
    if (value !== '' && value !== null && value !== undefined) {
      httpParams = httpParams.set(key, value);
    }
  }

  return httpParams;
}

/**
 * Enchaîne les pages d'une liste jusqu'à les avoir toutes, et renvoie le
 * tableau complet.
 *
 * Réservé aux cas où une liste exhaustive est réellement nécessaire : alimenter
 * un menu déroulant, par exemple, où n'afficher que la première page reviendrait
 * à masquer des choix à l'utilisateur. Pour de l'affichage, il faut au contraire
 * s'en tenir à la pagination.
 *
 * Avec per_page à 100, un parc courant tient en un seul appel.
 */
export function fetchAllPages<T>(
  loadPage: (page: number) => Observable<PaginatedResponse<T>>,
): Observable<T[]> {
  return loadPage(1).pipe(
    expand((response) =>
      response.current_page < response.last_page
        ? loadPage(response.current_page + 1)
        : // Un observable vide arrête expand : sans cela, la récursion
          // continuerait indéfiniment sur la dernière page.
          [],
    ),
    takeWhile((response) => response.current_page <= response.last_page, true),
    map((response) => response.data),
    reduce((all: T[], page: T[]) => all.concat(page), []),
  );
}
