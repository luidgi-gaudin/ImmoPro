import { Component, ViewEncapsulation, input } from '@angular/core';

/**
 * Enveloppe de tableau de données.
 *
 * `stacked` change ce que le tableau devient sur un écran étroit. Un tableau
 * de sept colonnes sur 375 px n'a que deux issues : le débordement horizontal,
 * où l'on fait défiler à l'aveugle pour lire une ligne, ou l'écrasement, où les
 * colonnes deviennent illisibles. La troisième voie est de cesser d'être un
 * tableau : chaque ligne devient une fiche, et chaque cellule une paire
 * intitulé / valeur.
 *
 * L'intitulé vient de l'attribut `data-label` posé sur la cellule — c'est la
 * seule chose que l'écran appelant a à fournir :
 *
 * ```html
 * <immopro-table [stacked]="true">
 *   <td data-label="Période">Mars 2026</td>
 * ```
 *
 * Une cellule sans `data-label` s'affiche pleine largeur, sans intitulé : c'est
 * ce qu'on veut pour la colonne d'actions.
 */
@Component({
  selector: 'immopro-table',
  standalone: true,
  imports: [],
  encapsulation: ViewEncapsulation.None,
  templateUrl: './immopro-table.component.html',
  styleUrls: ['./immopro-table.component.scss'],
})
export class ImmoproTableComponent {
  loading = input<boolean>(false);
  loadingText = input<string>('Chargement...');

  /** Bascule en fiches sous 760 px, au lieu de déborder horizontalement. */
  stacked = input<boolean>(false);
}
