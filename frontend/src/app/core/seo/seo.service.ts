import { DOCUMENT, Injectable, inject } from '@angular/core';
import { Meta, Title } from '@angular/platform-browser';
import { SITE, absoluteUrl } from '../config/site.config';

export interface PageSeo {
  /** Titre complet, suffixe de marque compris. */
  title: string;
  description: string;
  /** Chemin canonique, commençant par « / ». */
  path: string;
  noindex: boolean;
}

/**
 * Pilote `title`, `description`, canonique, Open Graph, Twitter Card et les
 * blocs de données structurées.
 *
 * L'application est rendue côté navigateur : Google exécute le JavaScript et
 * voit ces balises, les robots des réseaux sociaux non. Les valeurs de partage
 * par défaut sont donc écrites en dur dans `index.html`.
 */
@Injectable({ providedIn: 'root' })
export class SeoService {
  private readonly doc = inject(DOCUMENT);
  private readonly title = inject(Title);
  private readonly meta = inject(Meta);

  apply(page: PageSeo): void {
    const url = absoluteUrl(page.path);

    this.title.setTitle(page.title);
    this.meta.updateTag({ name: 'description', content: page.description });

    this.meta.updateTag({ property: 'og:title', content: page.title });
    this.meta.updateTag({ property: 'og:description', content: page.description });
    this.meta.updateTag({ property: 'og:url', content: url });

    this.meta.updateTag({ name: 'twitter:title', content: page.title });
    this.meta.updateTag({ name: 'twitter:description', content: page.description });

    if (page.noindex) {
      this.meta.updateTag({ name: 'robots', content: 'noindex, nofollow' });
      this.removeCanonical();
    } else {
      this.meta.removeTag("name='robots'");
      this.setCanonical(url);
    }
  }

  /** Insère ou remplace un bloc JSON-LD identifié. `null` le retire. */
  setJsonLd(id: string, data: unknown | null): void {
    const selector = `script[type="application/ld+json"][data-seo-id="${id}"]`;
    const existing = this.doc.head.querySelector(selector);

    if (data === null) {
      existing?.remove();
      return;
    }

    const script = existing ?? this.doc.createElement('script');
    if (!existing) {
      script.setAttribute('type', 'application/ld+json');
      script.setAttribute('data-seo-id', id);
      this.doc.head.appendChild(script);
    }

    // `textContent` sur un script n'est jamais interprété comme du HTML.
    script.textContent = JSON.stringify(data);
  }

  private setCanonical(url: string): void {
    let link = this.doc.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
    if (!link) {
      link = this.doc.createElement('link');
      link.setAttribute('rel', 'canonical');
      this.doc.head.appendChild(link);
    }
    link.setAttribute('href', url);
  }

  private removeCanonical(): void {
    this.doc.head.querySelector('link[rel="canonical"]')?.remove();
  }
}

/** Fiche entreprise JSON-LD. Les champs non renseignés sont omis, pas émis vides. */
export function buildLocalBusinessJsonLd(): Record<string, unknown> {
  const b = SITE.business;

  const address = compact({
    '@type': 'PostalAddress',
    streetAddress: b.streetAddress,
    postalCode: b.postalCode,
    addressLocality: b.addressLocality,
    addressCountry: b.addressCountry,
  });

  return compact({
    '@context': 'https://schema.org',
    '@type': 'LocalBusiness',
    '@id': `${SITE.origin}/#business`,
    name: SITE.name,
    legalName: b.legalName,
    description: SITE.defaultDescription,
    url: SITE.origin,
    image: absoluteUrl(SITE.ogImagePath),
    logo: absoluteUrl(SITE.ogImagePath),
    telephone: b.telephone,
    email: b.email,
    // Une adresse réduite au pays n'apporte rien.
    address: b.addressLocality ? address : undefined,
    openingHours: b.openingHours.length ? b.openingHours : undefined,
    sameAs: b.sameAs.length ? b.sameAs : undefined,
    areaServed: 'FR',
    knowsLanguage: 'fr-FR',
  });
}

/** Retire les clés vides, nulles ou indéfinies. */
function compact<T extends Record<string, unknown>>(obj: T): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(obj).filter(
      ([, value]) => value !== undefined && value !== null && value !== '',
    ),
  );
}
