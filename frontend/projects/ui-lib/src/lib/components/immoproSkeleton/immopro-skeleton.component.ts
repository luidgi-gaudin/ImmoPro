import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

export type SkeletonVariant = 'line' | 'title' | 'avatar' | 'badge' | 'block';

/**
 * Bloc de chargement scintillant, reprenant le motif déjà utilisé sur le
 * tableau de bord et les alertes.
 *
 * Un squelette n'est pas qu'une décoration : en occupant à l'avance la place et
 * la forme du contenu attendu, il évite que la page ne sursaute au moment où
 * les données arrivent. C'est pour cela que les variantes reprennent les
 * hauteurs réelles des éléments qu'elles remplacent.
 */
@Component({
  selector: 'immopro-skeleton',
  standalone: true,
  imports: [],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @for (item of repeats(); track $index) {
      <span
        class="ip-skeleton"
        [class]="'is-' + variant()"
        [style.width]="width()"
        [style.height]="height() || null"
        aria-hidden="true"
      ></span>
    }
  `,
  styleUrls: ['./immopro-skeleton.component.scss'],
})
export class ImmoproSkeletonComponent {
  variant = input<SkeletonVariant>('line');
  /** Largeur CSS ; « 60% » pour suggérer une ligne de texte incomplète. */
  width = input<string>('100%');
  /** Hauteur CSS explicite, sinon celle de la variante. */
  height = input<string>('');
  /** Nombre de blocs identiques à empiler. */
  count = input<number>(1);

  protected readonly repeats = computed(() => Array.from({ length: Math.max(1, this.count()) }));
}
