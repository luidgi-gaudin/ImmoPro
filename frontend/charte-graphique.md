# ⚜️ Charte Graphique & Librairie de Composants ImmoPro

Cette charte définit l'identité visuelle de marque **ImmoPro** et documente la bibliothèque de composants Angular réutilisables.

---

## 🎨 Identité Visuelle ("Quiet Luxury")

ImmoPro incarne l'esthétique du luxe moderne, inspirée des grandes maisons (Aesop, Cartier, Loro Piana) : épurée, rigoureuse, intemporelle et sans surcharge visuelle ("no AI slop").

### 1. Palette de Couleurs

L'identité repose sur des contrastes fins et une palette de couleurs équilibrée.

| Couleur | Token CSS | Rôle |
| :--- | :--- | :--- |
| **Champagne Gold** | `--primary` (`#c5a880`) | Couleur signature. Utilisée pour les titres, les éléments actifs et les accents de prestige. |
| **Deep Charcoal** | `--bg-dark` (`#121212`) | Fond du site en mode sombre. Offre une ambiance intime. |
| **Soft White** | `--bg-light` (`#fafafa`) | Fond du site en mode clair. Épuré et lumineux. |
| **Surface Card** | `--surface-card` | Conteneurs et cartes (Sombre : `#1a1a1a` \| Clair : `#ffffff`). |
| **Bordures** | `--border` | Séparateurs et contours discrets (Sombre : `#2a2a2a` \| Clair : `#e5e5e5`). |
| **Texte Principal** | `--text-primary` | Lisibilité maximale (Sombre : `#f5f5f5` \| Clair : `#1c1c1c`). |
| **Texte Secondaire**| `--text-secondary` | Informations annexes (Sombre : `#a0a0a0` \| Clair : `#666666`). |

### 2. Typographie

Nous associons une Serif classique italienne à une Sans-Serif géométrique contemporaine.

*   **Titres d'exception (h1, h2, h3, .luxury-title)** : `Cormorant Garamond`
    *   *Style* : Serif éditoriale, élégante, espacée, respirante.
    *   *Usage* : Titres principaux de pages, en-têtes de cartes de prestige.
*   **Corps de texte, Labels et Données (body, label, input)** : `Outfit`
    *   *Style* : Sans-Serif géométrique, épurée, ultra-lisible.

### 3. Règles Fondamentales de Design
*   **Pas d'Emojis dans l'UI** : Utiliser des icônes SVG fines et précises de 18px/24px.
*   **Courbe de Transition Unique** : Toutes les animations d'interactivité (hover, focus, modal) utilisent la même courbe d'accélération fluide : `cubic-bezier(0.16, 1, 0.3, 1)`.
*   **Bordures Fines** : Contour de 1px maximum pour séparer le contenu. Pas d'ombres portées disproportionnées.
*   **Pas de Lorem Ipsum** : Tous les textes et labels de démo doivent être réalistes.

---

## 📦 Bibliothèque de Composants Angular (`ui-lib`)

Tous les composants de la bibliothèque sont **standalone**, optimisés en performance et compatibles avec **Zoneless change detection** grâce à l'utilisation des **Angular Signals**.

### 1. Bouton (`immopro-button`)
Bouton interactif avec gestion d'état de chargement et plusieurs variantes de design de prestige.

*   **Sélecteur** : `immopro-button`
*   **Props (`input`)** :
    *   `type`: `'button' | 'submit'` (par défaut: `'button'`)
    *   `variant`: `'solid' | 'outline' | 'ghost' | 'error' | 'secondary'` (par défaut: `'solid'`)
    *   `disabled`: `boolean` (par défaut: `false`)
    *   `loading`: `boolean` (par défaut: `false` - affiche un spinner CSS fin)
*   **Events (`output`)** :
    *   `onClick`: `EventEmitter<MouseEvent>`
*   **Exemple d'utilisation** :
    ```html
    <immopro-button variant="solid" [loading]="saving()" (onClick)="save()">
      Enregistrer le bien
    </immopro-button>
    ```

### 2. Champ de Saisie (`immopro-input`)
Champ de saisie gérant tous les types d'inputs classiques et intégrant **un masque de mot de passe interactif automatique**.

*   **Sélecteur** : `immopro-input`
*   **Props (`input`)** :
    *   `id`: `string`
    *   `label`: `string` (affiche un label supérieur en majuscules espacées)
    *   `type`: `'text' | 'number' | 'password' | 'email' | 'date' | 'month'` (par défaut: `'text'`)
    *   `placeholder`: `string`
    *   `error`: `string | null` (affiche un message d'erreur rouge fin sous l'input si présent)
*   **Particularités** :
    *   Implémente `ControlValueAccessor` (compatible avec `ReactiveFormsModule` / `formControlName`).
    *   Si `type="password"`, il intègre automatiquement **un bouton d'affichage/masquage du mot de passe avec une icône d'œil interactif**.
*   **Exemple d'utilisation** :
    ```html
    <immopro-input
      id="login-password"
      label="Mot de passe"
      type="password"
      formControlName="password"
      placeholder="••••••••"
      [error]="submitted() && passwordForm.get('password')?.invalid ? 'Requis' : null"
    ></immopro-input>
    ```

### 3. Carte de Prestige (`immopro-card`)
Conteneur structuré simulant un panneau de verre ou une boîte de présentation luxueuse.

*   **Sélecteur** : `immopro-card`
*   **Props (`input`)** :
    *   `hoverable`: `boolean` (par défaut: `false` - si `true`, applique une translation verticale de -4px fluide et une bordure dorée au survol)
*   **Slots de projection (`ng-content`)** :
    *   `card-header` : En-tête de la carte.
    *   `card-footer` : Actions ou informations de bas de carte.
*   **Exemple d'utilisation** :
    ```html
    <immopro-card [hoverable]="true">
      <div card-header>
        <h3 class="luxury-title">Résidence Riviera</h3>
      </div>
      <p class="text-secondary">Prestations haut de gamme sur la Côte d'Azur.</p>
      <div card-footer>
        <immopro-button variant="ghost">Voir les détails</immopro-button>
      </div>
    </immopro-card>
    ```

### 4. Carte d'Authentification (`immopro-auth-card`)
Version spécialisée de la carte de conteneur, optimisée pour afficher les formulaires de connexion, inscription et double authentification au centre de l'écran avec une transition douce.

*   **Sélecteur** : `immopro-auth-card`
*   **Exemple d'utilisation** :
    ```html
    <immopro-auth-card>
      <h2 class="luxury-title">Se connecter</h2>
      <!-- Formulaire -->
    </immopro-auth-card>
    ```

### 5. Carte de Statistiques (`immopro-stat-card`)
Composant d'affichage de données clés pour le tableau de bord.

*   **Sélecteur** : `immopro-stat-card`
*   **Props (`input`)** :
    *   `title`: `string` (label supérieur)
    *   `value`: `string | number` (valeur dorée d'envergure)
*   **Exemple d'utilisation** :
    ```html
    <immopro-stat-card title="Actifs sous gestion" value="2.4M €"></immopro-stat-card>
    ```

---

## 🛠️ Intégration dans un projet Angular

Pour utiliser ces composants réutilisables dans une nouvelle page ou un nouveau module Angular :

1.  **Importation directe** :
    Dans votre fichier de composant TS, importez les composants requis depuis `ui-lib` et ajoutez-les au tableau `imports` :
    ```typescript
    import { Component } from '@angular/core';
    import { ImmoproButtonComponent, ImmoproInputComponent } from 'ui-lib';

    @Component({
      selector: 'app-my-feature',
      standalone: true,
      imports: [ImmoproButtonComponent, ImmoproInputComponent],
      templateUrl: './my-feature.component.html'
    })
    export class MyFeatureComponent {}
    ```
2.  **Styles requis** :
    Assurez-vous que le fichier `styles.scss` global du projet est importé pour disposer des variables CSS de la charte graphique.
