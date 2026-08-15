import {
  Component,
  DestroyRef,
  effect,
  inject,
  input,
  output,
  signal,
  ChangeDetectionStrategy,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';

export interface FilterChip {
  key: string;
  label: string;
  value: string;
}

/**
 * Barre de recherche et de filtres avancée commune à toutes les listes.
 *
 * Supporte la recherche debouncée, la projection de slots de filtres personnalisés,
 * l'affichage dynamique de chips de filtres actifs avec suppression unitaire,
 * et le bouton de réinitialisation globale.
 */
@Component({
  selector: 'immopro-filter-bar',
  standalone: true,
  imports: [],
  templateUrl: './immopro-filter-bar.component.html',
  styleUrls: ['./immopro-filter-bar.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImmoproFilterBarComponent {
  /** Valeur pilotée par le parent, pour rester d'accord avec l'URL. */
  searchValue = input<string>('');
  searchPlaceholder = input<string>('Rechercher…');

  /** Affiche le bouton Réinitialiser : à passer quand un critère est actif. */
  canReset = input<boolean>(false);

  /** Nombre total de résultats, affiché à droite. null masque le compteur. */
  total = input<number | null>(null);
  /** Nom de l'objet compté, au singulier (« bien », « bail », « locataire »). */
  itemLabel = input<string>('résultat');
  /** Pluriel, quand un « s » ne suffit pas (« bail » → « baux »). */
  itemLabelPlural = input<string>('');

  /** Liste des filtres actifs à afficher sous forme de badges amovibles. */
  activeChips = input<FilterChip[]>([]);

  loading = input<boolean>(false);

  searchChange = output<string>();
  removeChip = output<string>();
  reset = output<void>();

  /** Ce que l'utilisateur voit dans le champ, avant l'attente de frappe. */
  readonly draft = signal('');

  private readonly typed = new Subject<string>();

  constructor() {
    effect(() => this.draft.set(this.searchValue()));

    this.typed
      .pipe(debounceTime(250), distinctUntilChanged(), takeUntilDestroyed(inject(DestroyRef)))
      .subscribe((value) => this.searchChange.emit(value));
  }

  onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.draft.set(value);
    this.typed.next(value);
  }

  /** Vide la recherche sans attendre le délai de frappe. */
  clearSearch(): void {
    this.draft.set('');
    this.searchChange.emit('');
  }

  onRemoveChip(key: string): void {
    this.removeChip.emit(key);
  }

  onReset(): void {
    this.draft.set('');
    this.reset.emit();
  }

  get countLabel(): string {
    const total = this.total();

    if (total === null) {
      return '';
    }

    const plural = this.itemLabelPlural() || `${this.itemLabel()}s`;

    return `${total} ${total !== 1 ? plural : this.itemLabel()}`;
  }
}
