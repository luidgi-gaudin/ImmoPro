import { DestroyRef, Injector, computed, effect, inject, signal, untracked } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { ListParams, SortDirection } from './pagination.model';

export interface ListQueryOptions {
  /** Colonne triée tant que l'utilisateur n'a rien demandé. */
  defaultSort?: string;
  defaultDirection?: SortDirection;
  /** Taille de page initiale. */
  perPage?: number;
  /** Filtres propres à la ressource, pour ne lire de l'URL que ce qui la concerne. */
  filterKeys?: string[];
}

const PER_PAGE_CHOICES = [10, 15, 25, 50, 100] as const;

/**
 * État d'une liste : page, recherche, tri et filtres, exposés en signals et
 * reflétés dans l'URL.
 *
 * L'URL est la mémoire de l'écran. On peut recharger la page, mettre un filtre
 * en favori, l'envoyer à quelqu'un ou revenir en arrière : la vue se reconstitue
 * à l'identique. C'est aussi ce qui rend le bouton Précédent du navigateur
 * cohérent avec ce que l'utilisateur vient de faire.
 */
export class ListQuery {
  readonly page = signal(1);
  readonly perPage = signal(15);
  readonly search = signal('');
  readonly sort = signal('');
  readonly direction = signal<SortDirection>('asc');
  readonly filters = signal<Record<string, string>>({});

  /** Incrémenté par refresh() pour reprovoquer un chargement à critères égaux. */
  private readonly nonce = signal(0);

  readonly perPageChoices = PER_PAGE_CHOICES;

  /** Ce qui part vers l'API. */
  readonly params = computed<ListParams>(() => ({
    page: this.page(),
    per_page: this.perPage(),
    search: this.search(),
    sort: this.sort(),
    direction: this.direction(),
    filters: this.filters(),
  }));

  /**
   * Ce que l'on observe pour déclencher un chargement : les critères, plus le
   * compteur de rafraîchissement. Sans ce dernier, redemander la même page
   * après une suppression ne relancerait aucune requête.
   */
  readonly trigger = computed(() => ({ params: this.params(), nonce: this.nonce() }));

  /** Vrai dès qu'un critère s'écarte de l'état initial : pilote le bouton Réinitialiser. */
  readonly isFiltered = computed(
    () => this.search() !== '' || Object.values(this.filters()).some((value) => value !== ''),
  );

  constructor(
    private readonly options: ListQueryOptions,
    private readonly router: Router,
    private readonly route: ActivatedRoute,
  ) {
    this.sort.set(options.defaultSort ?? '');
    this.direction.set(options.defaultDirection ?? 'asc');
    this.perPage.set(options.perPage ?? 15);
  }

  // ── Mutations ─────────────────────────────────────────────────────────────
  // Toute modification de critère ramène à la page 1. Sans cela, filtrer depuis
  // la page 4 affiche un écran vide alors que des résultats existent.

  setSearch(value: string): void {
    if (value === untracked(this.search)) {
      return;
    }
    this.search.set(value);
    this.page.set(1);
  }

  /**
   * Valeur d'un filtre, vide s'il n'est pas positionné.
   *
   * Passe par une méthode plutôt que par un accès direct dans le template :
   * l'index d'un Record est typé « string » même pour une clé absente, si bien
   * qu'un `?? ''` écrit côté template est signalé comme inutile alors qu'il
   * couvre un cas bien réel.
   */
  filterValue(key: string): string {
    return this.filters()[key] ?? '';
  }

  setFilter(key: string, value: string): void {
    this.filters.update((current) => ({ ...current, [key]: value }));
    this.page.set(1);
  }

  removeFilter(key: string): void {
    this.filters.update((current) => {
      const copy = { ...current };
      delete copy[key];
      return copy;
    });
    this.page.set(1);
  }

  /**
   * Trie sur une colonne. Recliquer la même colonne inverse le sens, ce qui est
   * le comportement attendu d'un en-tête de tableau.
   */
  toggleSort(column: string): void {
    if (untracked(this.sort) === column) {
      this.direction.update((current) => (current === 'asc' ? 'desc' : 'asc'));
    } else {
      this.sort.set(column);
      this.direction.set('asc');
    }
    this.page.set(1);
  }

  setPage(page: number): void {
    this.page.set(Math.max(1, page));
  }

  setPerPage(size: number): void {
    this.perPage.set(size);
    this.page.set(1);
  }

  /** Remet recherche et filtres à zéro, en conservant le tri choisi. */
  reset(): void {
    this.search.set('');
    this.filters.set({});
    this.page.set(1);
  }

  /** Relance le chargement sans changer de critères (après une suppression). */
  refresh(): void {
    this.nonce.update((value) => value + 1);
  }

  // ── Synchronisation avec l'URL ────────────────────────────────────────────

  /** Lit l'état initial depuis l'URL, puis y reflète chaque changement. */
  bindToUrl(injector: Injector): void {
    this.readFromUrl();

    // L'URL peut aussi changer sans nous : bouton Précédent, lien collé.
    this.route.queryParamMap
      .pipe(takeUntilDestroyed(injector.get(DestroyRef)))
      .subscribe(() => this.readFromUrl());

    effect(
      () => {
        const query = this.toQueryParams();

        // On ne navigue que si l'URL ne dit pas déjà la même chose. C'est ce qui
        // empêche la boucle entre « je lis l'URL » et « j'écris l'URL ».
        if (this.sameAsUrl(query)) {
          return;
        }

        this.router.navigate([], {
          relativeTo: this.route,
          queryParams: query,
          // replaceUrl : cocher trois filtres ne doit pas obliger à appuyer
          // trois fois sur Précédent pour revenir à l'écran d'avant.
          replaceUrl: true,
          queryParamsHandling: 'merge',
        });
      },
      { injector },
    );
  }

  private toQueryParams(): Record<string, string | null> {
    const query: Record<string, string | null> = {
      // null demande à Angular de retirer le paramètre de l'URL.
      page: this.page() > 1 ? String(this.page()) : null,
      per_page: this.perPage() !== (this.options.perPage ?? 15) ? String(this.perPage()) : null,
      search: this.search() || null,
      sort: this.sort() !== (this.options.defaultSort ?? '') ? this.sort() || null : null,
      direction:
        this.direction() !== (this.options.defaultDirection ?? 'asc') ? this.direction() : null,
    };

    for (const key of this.options.filterKeys ?? []) {
      query[key] = this.filters()[key] || null;
    }

    return query;
  }

  private sameAsUrl(query: Record<string, string | null>): boolean {
    const current = this.route.snapshot.queryParamMap;

    return Object.entries(query).every(([key, value]) => (current.get(key) ?? null) === value);
  }

  private readFromUrl(): void {
    const map = this.route.snapshot.queryParamMap;

    const page = Number(map.get('page'));
    this.page.set(Number.isFinite(page) && page > 0 ? page : 1);

    const perPage = Number(map.get('per_page'));
    this.perPage.set(perPage > 0 ? perPage : (this.options.perPage ?? 15));

    this.search.set(map.get('search') ?? '');
    this.sort.set(map.get('sort') ?? this.options.defaultSort ?? '');

    const direction = map.get('direction');
    this.direction.set(
      direction === 'asc' || direction === 'desc'
        ? direction
        : (this.options.defaultDirection ?? 'asc'),
    );

    const filters: Record<string, string> = {};
    for (const key of this.options.filterKeys ?? []) {
      filters[key] = map.get(key) ?? '';
    }
    this.filters.set(filters);
  }
}

/**
 * À appeler dans un contexte d'injection (initialisation de champ de composant) :
 *
 *   private readonly list = createListQuery({ defaultSort: 'last_name' });
 */
export function createListQuery(options: ListQueryOptions = {}): ListQuery {
  const query = new ListQuery(options, inject(Router), inject(ActivatedRoute));
  query.bindToUrl(inject(Injector));

  return query;
}
