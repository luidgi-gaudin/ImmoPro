/** Réglages publics du site : domaine, partage social, fiche entreprise, mesure d'audience. */

/** Domaine de production, sans slash final (évite les URLs canoniques en double slash). */
const ORIGIN = 'https://immopro.fr'.replace(/\/+$/, '');

export interface BusinessInfo {
  legalName: string;
  streetAddress: string;
  postalCode: string;
  addressLocality: string;
  /** Code pays ISO 3166-1 alpha-2. */
  addressCountry: string;
  /** Format international, ex. +33123456789. */
  telephone: string;
  email: string;
  /** Format schema.org, ex. 'Mo-Fr 09:00-18:00'. */
  openingHours: string[];
  sameAs: string[];
}

export const SITE = {
  name: 'ImmoPro',
  origin: ORIGIN,

  defaultTitle: 'ImmoPro — Logiciel de gestion locative pour professionnels',

  /** Suffixe des titres de page : « Locataires — ImmoPro ». */
  titleSuffix: 'ImmoPro',

  defaultDescription:
    'ImmoPro centralise vos biens, vos baux réglementaires et vos quittances de loyer. ' +
    'Le logiciel de gestion locative des professionnels de l’immobilier.',

  /** Image de partage réseaux sociaux, 1200x630. */
  ogImagePath: '/og-image.png',
  ogImageWidth: 1200,
  ogImageHeight: 630,
  ogImageAlt: 'ImmoPro — La gestion immobilière, maîtrisée.',

  locale: 'fr_FR',
  lang: 'fr',

  /** Identifiant GA4 (G-XXXXXXXXXX). Vide = aucun script tiers chargé. */
  gaMeasurementId: '',

  business: {
    legalName: 'ImmoPro',
    // À RENSEIGNER : sans ces valeurs, le balisage reste valide mais n'ouvre
    // droit à aucun résultat enrichi.
    streetAddress: '',
    postalCode: '',
    addressLocality: '',
    addressCountry: 'FR',
    telephone: '',
    email: '',
    openingHours: ['Mo-Fr 09:00-18:00'],
    sameAs: [],
  } as BusinessInfo,
};

/** Chemin applicatif vers URL absolue. */
export function absoluteUrl(path: string): string {
  return `${SITE.origin}${path.startsWith('/') ? path : `/${path}`}`;
}
