# Charte graphique & bibliothèque de composants ImmoPro

Ce document décrit l'identité visuelle d'**ImmoPro** et l'API réelle des composants de `ui-lib`.

> Il est relu depuis le code (`src/styles.scss` et `projects/ui-lib/`). En cas de
> divergence, c'est le code qui fait foi : mettre ce fichier à jour, pas l'inverse.

---

## Identité visuelle — « Sober Institutional »

ImmoPro vise la sobriété d'un outil professionnel : lisible, calme, sans effet
gratuit. L'identité repose sur un **vert profond** et deux ambiances complètes,
sombre et claire, traitées à égalité — la claire n'est pas une variante dégradée
de la sombre, elle a sa propre chaleur.

### 1. Palette

Toutes les couleurs passent par des variables CSS définies dans `src/styles.scss`.
**Aucune valeur ne doit être écrite en dur dans un composant** : c'est ce qui
permet au thème clair de fonctionner sans surcharge par composant.

| Rôle | Token | Sombre (défaut) | Clair (`.light-theme`) |
| :--- | :--- | :--- | :--- |
| Couleur signature | `--primary` | `#46a07d` Refined Emerald | `#2f7d5a` Deep Pine |
| Survol signature | `--primary-hover` | `#57b591` | `#266449` |
| Halo signature | `--primary-glow` | `rgba(70,160,125,.10)` | `rgba(47,125,90,.09)` |
| Texte sur signature | `--on-primary` | `#05130d` | `#ffffff` |
| Fond de page | `--bg-color` | `#0b0f0e` | `#faf6ee` Warm Ivory |
| Surface de carte | `--surface-card` | `#121917` | `#ffffff` |
| Barre latérale | `--surface-sidebar` | `#0e1413` | `#f3ead9` Warm Sand |
| Bordure | `--border` | `rgba(120,165,148,.10)` | `rgba(97,76,48,.14)` |
| Bordure au survol | `--border-hover` | `rgba(120,165,148,.24)` | `rgba(97,76,48,.28)` |
| Texte principal | `--text-primary` | `#f3f6f4` | `#2a2318` Warm Espresso |
| Texte secondaire | `--text-secondary` | `#93a39c` Slate Sage | `#6f6252` Warm Taupe |
| Texte atténué | `--text-muted` | `#5f6d67` | `#a69a86` Warm Stone |
| Erreur | `--error` | `#e5717f` | `#b3261e` |
| Succès | `--success` | `#6fc79a` | `#2e7d52` |
| Information | `--info` | `#6aa9e0` | `#1c62b0` |

**Voiles et ombres.** `--overlay-soft`, `--overlay-softer` et `--overlay-strong`
remplacent les `rgba(255,255,255,…)` : en thème clair, un voile blanc serait
invisible, ces tokens passent donc sur un taupe chaud. Les ombres se composent à
partir de `--shadow-rgb` (noir en sombre, brun chaud `64,46,24` en clair) — une
ombre noire pure sur l'ivoire donne un gris sale.

### 2. Typographie

Deux familles chargées depuis Google Fonts (`src/index.html`).

* **Titres** (`h1`–`h6`, `.luxury-title`) : **Space Grotesk**, graisse 600,
  `letter-spacing: -0.02em`. Le resserrement fait tenir les titres et leur donne
  leur caractère : ne pas le retirer.
* **Corps, labels, données** : **Inter**, 16 px, interlignage 1.6.

Échelle : `h1` 2.4rem · `h2` 1.9rem · `h3` 1.45rem · `h4` 1.2rem.

### 3. Règles de design

* **Pas d'emoji dans l'interface.** Icônes SVG au trait, 16 px ou 24 px,
  `stroke-width` 1.5 à 2.
* **Une seule courbe d'animation** : `cubic-bezier(0.16, 1, 0.3, 1)`, via
  `--transition-fast` (200 ms) et `--transition-smooth` (300 ms). Ne jamais
  écrire `ease` ou `ease-in-out` à la main.
* **Animations décoratives désactivées** sous `prefers-reduced-motion: reduce`.
  Cela concerne les scintillements de chargement et les entrées de fenêtres.
* **Bordures de 1 px**, jamais plus. Les rayons viennent de `--radius-sm` (6 px),
  `--radius-md` (10 px) et `--radius-lg` (14 px).
* **Pas de faux texte.** Les libellés de démonstration doivent être réalistes.
* **Gabarit** : `--sidebar-width` 240 px, `--header-height` 68 px.

---

## Bibliothèque `ui-lib`

Tous les composants sont **standalone**, en `OnPush` ou compatibles
**zoneless**, et exposent leur API par des **signals** (`input()` / `output()`).

### Bouton — `immopro-button`

`variant` et `color` sont deux axes distincts : le premier règle le remplissage,
le second la teinte.

* `variant`: `'solid' | 'outline' | 'ghost'` — défaut `'solid'`
* `color`: `'primary' | 'secondary' | 'error'` — défaut `'primary'`
* `type`: `'button' | 'submit'` — défaut `'button'`
* `disabled`, `loading`: `boolean` — `loading` affiche un indicateur circulaire fin et bloque le clic
* `(onClick)`: `MouseEvent`

```html
<immopro-button variant="outline" color="error" [loading]="saving()" (onClick)="save()">
  Supprimer le bail
</immopro-button>
```

### Champ de saisie — `immopro-input`

Implémente `ControlValueAccessor` : utilisable avec `formControlName`.
Avec `type="password"`, un bouton œil d'affichage/masquage est ajouté seul.

* `id`, `label`, `placeholder`: `string`
* `type`: `string` — défaut `'text'`
* `error`: `string | null` — affiche un message sous le champ

### Liste déroulante — `immopro-select`

Enveloppe un `<select>` natif projeté, pour garder le comportement du système.

* `inputId`, `label`: `string`
* `error`: `string | null`

```html
<immopro-select label="Type" inputId="filtre-type">
  <select id="filtre-type" [value]="valeur()" (change)="onChange($event)">
    <option value="">Tous</option>
  </select>
</immopro-select>
```

### Carte — `immopro-card`

* `hoverable`: `boolean` — translation verticale et bordure accentuée au survol
* `glow`: `boolean` — halo `--primary-glow`
* Slots : `card-header`, `card-footer`

### Carte d'authentification — `immopro-auth-card`

* `title`: `string` **requis**
* `subtitle`: `string`

### Carte de statistique — `immopro-stat-card`

* `label`: `string` **requis**
* `value`: `string | number` **requis**
* `trend`: `string` — variation affichée à côté de la valeur
* `trendType`: `'success' | 'info'` — défaut `'info'`

### Tableau — `immopro-table`

Enveloppe un `<table>` projeté et gère l'état de chargement.

* `loading`: `boolean`
* `loadingText`: `string` — défaut `'Chargement...'`
* Classes utilitaires fournies : `.ip-table-actions`, `.ip-table-empty`,
  `.ip-sortable` (en-tête cliquable), `.ip-skeleton-row`

### Barre de filtres — `immopro-filter-bar`

Recherche temporisée (300 ms), filtres projetés et compteur de résultats.

* `searchValue`, `searchPlaceholder`, `itemLabel`, `itemLabelPlural`: `string`
* `canReset`, `loading`: `boolean`
* `total`: `number | null` — `null` masque le compteur
* `activeChips`: `FilterChip[]` — pastilles des filtres actifs
* `(searchChange)`: `string` · `(removeChip)`: `string` · `(reset)`: `void`

### Pagination — `immopro-pagination`

* `currentPage`, `lastPage`: `number` **requis**
* `from`, `to`, `total`: `number | null` — affichent « 21 à 40 sur 137 »
* `perPage`: `number` — à `0`, le sélecteur de taille est masqué
* `perPageChoices`: `readonly number[]` — défaut `[10, 15, 25, 50, 100]`
* `(pageChange)`, `(perPageChange)`: `number`

### Squelette de chargement — `immopro-skeleton`

* `variant`: `'line' | 'title' | 'avatar' | 'badge' | 'block'` — défaut `'line'`
* `width`, `height`: `string` (CSS)
* `count`: `number` — nombre de blocs empilés

À réserver au **premier** chargement d'un écran. Sur un changement de filtre, on
laisse la liste en place et on la fait pâlir : remplacer un contenu déjà lisible
par des blocs gris à chaque frappe donne une impression de clignotement.

### État vide — `immopro-empty-state`

* `title`: `string | null` · `message`: `string`
* `variant`: `'plain' | 'dashed'`
* Slot `icon` pour un SVG

Distinguer toujours « rien à afficher » de « aucun résultat pour ce filtre » :
un utilisateur qui filtre trop finement doit comprendre que ses données existent.

### Pastille — `immopro-badge`

* `tone`: `'success' | 'warning' | 'danger' | 'info' | 'neutral'` — défaut `'neutral'`

### Étiquette DPE — `immopro-dpe-badge`

* `value`: `string` — `'A'` à `'G'`, coloration réglementaire

### Avatar — `immopro-avatar`

* `initials`: `string`
* `size`: `'sm' | 'md' | 'lg' | 'xl'` — défaut `'md'`
* `tone`: `'primary' | 'neutral' | 'gradient'` — défaut `'primary'`

### Bouton d'icône — `immopro-icon-button`

* `tone`: `'default' | 'danger'` · `size`: `'sm' | 'md'`
* `active`, `disabled`: `boolean`
* `label`: `string` — **obligatoire en pratique** : sert de libellé accessible
* `(onClick)`: `MouseEvent`

### En-tête de page — `immopro-page-header`

* `title`: `string` · `subtitle`: `string | null`
* Slot `actions` pour les boutons de droite

### Bascule de thème — `immopro-theme-toggle`

Sans entrée. S'appuie sur `ThemeService`, qui n'écrit la préférence dans le
stockage local **que si l'utilisateur a accepté la catégorie « préférences »**
dans le bandeau de consentement.

---

## Intégration

```typescript
import { Component } from '@angular/core';
import { ImmoproButtonComponent, ImmoproInputComponent } from 'ui-lib';

@Component({
  selector: 'app-ma-page',
  standalone: true,
  imports: [ImmoproButtonComponent, ImmoproInputComponent],
  templateUrl: './ma-page.component.html',
})
export class MaPageComponent {}
```

Le `styles.scss` global doit être chargé : c'est lui qui définit les variables
CSS dont dépendent tous les composants.
