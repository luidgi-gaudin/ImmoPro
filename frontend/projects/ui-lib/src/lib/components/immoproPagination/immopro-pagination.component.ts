import { Component, computed, input, output } from '@angular/core';

/** Séparateur affiché à la place des pages omises. */
export const PAGE_GAP = '…' as const;

export type PageSlot = number | typeof PAGE_GAP;

@Component({
  selector: 'immopro-pagination',
  standalone: true,
  imports: [],
  templateUrl: './immopro-pagination.component.html',
  styleUrls: ['./immopro-pagination.component.scss'],
})
export class ImmoproPaginationComponent {
  currentPage = input.required<number>();
  lastPage = input.required<number>();

  /**
   * Bornes affichées (« 21 à 40 sur 137 »). Renvoyées telles quelles par l'API.
   * Laissées à null, le résumé chiffré n'apparaît pas.
   */
  from = input<number | null>(null);
  to = input<number | null>(null);
  total = input<number | null>(null);

  /** Taille de page courante. À 0, le sélecteur n'est pas affiché. */
  perPage = input<number>(0);
  perPageChoices = input<readonly number[]>([10, 15, 25, 50, 100]);

  pageChange = output<number>();
  perPageChange = output<number>();

  /**
   * Pages à afficher : les premières et dernières, plus une fenêtre autour de
   * la page courante. Au-delà d'une poignée de pages, tout énumérer donnerait
   * une ligne de boutons illisible.
   */
  readonly slots = computed<PageSlot[]>(() => {
    const last = this.lastPage();
    const current = this.currentPage();

    if (last <= 7) {
      return Array.from({ length: last }, (_, index) => index + 1);
    }

    const pages = new Set<number>([1, last, current]);

    // Une page de part et d'autre de la page courante.
    for (const offset of [-1, 1]) {
      const page = current + offset;
      if (page > 1 && page < last) {
        pages.add(page);
      }
    }

    // Toujours montrer le début ou la fin quand on s'en approche, pour que la
    // barre ne change pas de largeur à chaque clic.
    if (current <= 3) {
      pages.add(2).add(3).add(4);
    }
    if (current >= last - 2) {
      pages
        .add(last - 1)
        .add(last - 2)
        .add(last - 3);
    }

    const sorted = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);

    const slots: PageSlot[] = [];
    let previous = 0;

    for (const page of sorted) {
      if (previous && page - previous > 1) {
        slots.push(PAGE_GAP);
      }
      slots.push(page);
      previous = page;
    }

    return slots;
  });

  readonly summary = computed(() => {
    const from = this.from();
    const to = this.to();
    const total = this.total();

    if (from === null || to === null || total === null || total === 0) {
      return '';
    }

    return `${from} à ${to} sur ${total}`;
  });

  /** Le bloc n'a d'intérêt que s'il y a plusieurs pages ou un choix de taille. */
  readonly isVisible = computed(() => this.lastPage() > 1 || this.perPage() > 0);

  isGap(slot: PageSlot): slot is typeof PAGE_GAP {
    return slot === PAGE_GAP;
  }

  goTo(page: number): void {
    if (page >= 1 && page <= this.lastPage() && page !== this.currentPage()) {
      this.pageChange.emit(page);
    }
  }

  onPerPageChange(event: Event): void {
    this.perPageChange.emit(Number((event.target as HTMLSelectElement).value));
  }
}
