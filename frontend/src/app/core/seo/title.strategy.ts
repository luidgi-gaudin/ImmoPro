import { Injectable, inject } from '@angular/core';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { SITE } from '../config/site.config';
import { SeoService } from './seo.service';
import { canonicalPath, resolvePageMeta } from './page-meta';

/**
 * Applique titre et métadonnées de la route active. Angular n'appelle cette
 * stratégie qu'une fois la navigation confirmée : pas de scintillement du titre.
 */
@Injectable()
export class ImmoproTitleStrategy extends TitleStrategy {
  private readonly seo = inject(SeoService);

  override updateTitle(snapshot: RouterStateSnapshot): void {
    const routeTitle = this.buildTitle(snapshot);
    const meta = resolvePageMeta(snapshot);

    this.seo.apply({
      title: formatTitle(routeTitle),
      description: meta.description ?? SITE.defaultDescription,
      path: canonicalPath(snapshot.url),
      noindex: meta.noindex ?? false,
    });
  }
}

/** « Locataires — ImmoPro », mais jamais « ImmoPro — ImmoPro ». */
function formatTitle(routeTitle: string | undefined): string {
  if (!routeTitle) return SITE.defaultTitle;
  if (routeTitle.includes(SITE.titleSuffix)) return routeTitle;
  return `${routeTitle} — ${SITE.titleSuffix}`;
}
