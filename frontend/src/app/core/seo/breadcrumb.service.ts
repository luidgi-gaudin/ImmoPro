import { Injectable, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRouteSnapshot, NavigationEnd, Router } from '@angular/router';
import { filter } from 'rxjs/operators';
import { absoluteUrl } from '../config/site.config';
import { PageMeta, resolvePageMeta } from './page-meta';
import { SeoService } from './seo.service';

export interface Crumb {
  label: string;
  url: string;
}

/**
 * Construit le fil d'Ariane et le balisage `BreadcrumbList` de la page courante,
 * à partir des `data` des routes traversées.
 */
@Injectable({ providedIn: 'root' })
export class BreadcrumbService {
  private readonly router = inject(Router);
  private readonly seo = inject(SeoService);

  /** Libellés réels poussés par les pages de détail, indexés par URL. */
  private readonly overrides = signal<Record<string, string>>({});
  private readonly routeTrail = signal<Crumb[]>([]);

  readonly trail = computed<Crumb[]>(() => {
    const overrides = this.overrides();
    return this.routeTrail().map((crumb) => ({
      ...crumb,
      label: overrides[crumb.url] ?? crumb.label,
    }));
  });

  constructor() {
    this.rebuild();

    this.router.events
      .pipe(
        filter((event): event is NavigationEnd => event instanceof NavigationEnd),
        takeUntilDestroyed(),
      )
      .subscribe(() => {
        // Sinon le nom du portefeuille précédent resterait affiché le temps du chargement.
        this.overrides.set({});
        this.rebuild();
      });
  }

  /** Remplace un libellé statique par sa valeur réelle (« Résidence Beaumont »). */
  setLabel(url: string, label: string): void {
    if (!label) return;
    this.overrides.update((current) =>
      current[url] === label ? current : { ...current, [url]: label },
    );
  }

  private rebuild(): void {
    const snapshot = this.router.routerState.snapshot;
    const crumbs: Crumb[] = [{ label: 'Accueil', url: '/' }];

    let route: ActivatedRouteSnapshot | null = snapshot.root;
    let path = '';
    // Cumulés le long de la branche : le fil d'un actif a besoin de `:id` autant
    // que de `:propertyId`.
    const params: Record<string, string> = {};

    while (route) {
      const segment = route.url.map((s) => s.path).join('/');
      if (segment) path += `/${segment}`;
      Object.assign(params, route.params);

      const data = route.data as PageMeta;
      for (const parent of data.breadcrumbParents ?? []) {
        crumbs.push({ label: parent.label, url: fillParams(parent.url, params) });
      }
      if (data.breadcrumb) {
        crumbs.push({ label: data.breadcrumb, url: path || '/' });
      }

      route = route.firstChild;
    }

    this.routeTrail.set(dedupe(crumbs));
    this.publishJsonLd(resolvePageMeta(snapshot));
  }

  /** Le balisage n'a de sens que sur une page indexable et réellement imbriquée. */
  private publishJsonLd(meta: PageMeta): void {
    const trail = this.trail();

    if (meta.noindex || trail.length < 2) {
      this.seo.setJsonLd('breadcrumb', null);
      return;
    }

    this.seo.setJsonLd('breadcrumb', {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: trail.map((crumb, index) => ({
        '@type': 'ListItem',
        position: index + 1,
        name: crumb.label,
        item: absoluteUrl(crumb.url),
      })),
    });
  }
}

/** Remplace les jetons `:param` d'une URL de maillon parent. */
function fillParams(url: string, params: Record<string, string>): string {
  return url.replace(/:([A-Za-z0-9_]+)/g, (match, name: string) => params[name] ?? match);
}

/** Deux maillons pointant vers la même URL feraient doublon. */
function dedupe(crumbs: Crumb[]): Crumb[] {
  return crumbs.filter((crumb, index) => crumbs.findIndex((c) => c.url === crumb.url) === index);
}
