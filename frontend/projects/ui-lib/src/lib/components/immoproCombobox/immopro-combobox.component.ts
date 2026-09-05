import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  computed,
  forwardRef,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

export interface ComboboxOption {
  value: string | number;
  label: string;
  /** Seconde ligne : ville, portefeuille, courriel — ce qui départage deux homonymes. */
  hint?: string;
}

/** Valeur vide commune : le formulaire de bail initialise ses champs à `''`. */
const EMPTY = '';

/**
 * Liste déroulante avec recherche, pour les choix trop nombreux pour un
 * `<select>`.
 *
 * Un menu natif convient jusqu'à une dizaine d'entrées. Au-delà, choisir un bien
 * parmi deux cents revient à faire défiler une liste non filtrable, où deux
 * appartements du même immeuble ne se distinguent que par un mot au milieu du
 * libellé. C'est le point où la saisie d'un bail devient pénible sur un vrai
 * portefeuille.
 *
 * Ce composant filtre à la frappe, se pilote entièrement au clavier (flèches,
 * Entrée, Échap) et annonce son état aux lecteurs d'écran par le motif ARIA
 * `combobox` / `listbox`.
 *
 * Il s'utilise comme n'importe quel champ de formulaire réactif :
 *
 * ```html
 * <immopro-combobox
 *   inputId="property_id"
 *   label="Bien immobilier"
 *   [options]="propertyOptions()"
 *   formControlName="property_id"
 * />
 * ```
 */
@Component({
  selector: 'immopro-combobox',
  standalone: true,
  imports: [],
  templateUrl: './immopro-combobox.component.html',
  styleUrls: ['./immopro-combobox.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => ImmoproComboboxComponent),
      multi: true,
    },
  ],
})
export class ImmoproComboboxComponent implements ControlValueAccessor {
  options = input<ComboboxOption[]>([]);
  label = input<string>('');
  inputId = input<string>('');
  placeholder = input<string>('Rechercher…');
  error = input<string | null>(null);

  /** Message affiché quand aucune option ne correspond à la recherche. */
  emptyMessage = input<string>('Aucun résultat');

  /** Message affiché quand la liste elle-même est vide (rien à choisir). */
  noOptionsMessage = input<string>('Aucune entrée disponible');

  private readonly field = viewChild.required<ElementRef<HTMLInputElement>>('field');

  /** Valeur retenue, telle que le formulaire la connaît. */
  private readonly value = signal<string | number>(EMPTY);

  /** Ce que l'utilisateur a tapé. Vide tant qu'il n'a pas commencé à filtrer. */
  protected readonly query = signal<string>(EMPTY);
  protected readonly open = signal(false);
  protected readonly disabled = signal(false);

  /** Index de l'option surlignée, pilotée par les flèches. */
  protected readonly activeIndex = signal(0);

  protected readonly selected = computed(() => {
    const current = this.value();

    if (current === EMPTY || current === null || current === undefined) {
      return null;
    }

    // Comparaison souple : le formulaire manipule des chaînes (`'12'`), les
    // options des identifiants numériques venus de l'API.
    return this.options().find((option) => String(option.value) === String(current)) ?? null;
  });

  /**
   * Options retenues par la recherche.
   *
   * Le filtre est insensible à la casse et aux accents : « Chateau » doit
   * trouver « Château », faute de quoi la recherche punit qui tape vite.
   */
  protected readonly filtered = computed(() => {
    const needle = normalize(this.query());

    if (needle === '') {
      return this.options();
    }

    return this.options().filter(
      (option) =>
        normalize(option.label).includes(needle) ||
        (option.hint !== undefined && normalize(option.hint).includes(needle)),
    );
  });

  /** Texte affiché dans le champ : la recherche en cours, sinon le choix retenu. */
  protected readonly displayValue = computed(() =>
    this.open() ? this.query() : (this.selected()?.label ?? EMPTY),
  );

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  private onChange: (value: string | number) => void = () => {};
  private onTouched: () => void = () => {};

  // ── ControlValueAccessor ──────────────────────────────────────────────────

  writeValue(value: string | number | null): void {
    this.value.set(value ?? EMPTY);
  }

  registerOnChange(fn: (value: string | number) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled.set(isDisabled);
  }

  // ── Interactions ──────────────────────────────────────────────────────────

  protected openList(): void {
    if (this.disabled() || this.open()) {
      return;
    }

    // La recherche repart à vide : à l'ouverture, on veut voir toute la liste,
    // pas seulement ce qui ressemble au choix précédent.
    this.query.set(EMPTY);
    this.open.set(true);

    // La liste s'ouvre sur le choix courant plutôt qu'en tête : sur deux cents
    // biens, retrouver celui déjà retenu ne doit pas demander de faire défiler.
    const current = this.selected();
    const index = current === null ? -1 : this.filtered().indexOf(current);

    this.activeIndex.set(index < 0 ? 0 : index);
  }

  protected onInput(event: Event): void {
    this.query.set((event.target as HTMLInputElement).value);
    this.open.set(true);
    this.activeIndex.set(0);
  }

  protected select(option: ComboboxOption): void {
    this.value.set(option.value);
    this.onChange(option.value);
    this.close();
  }

  protected clear(event: Event): void {
    event.stopPropagation();

    this.value.set(EMPTY);
    this.query.set(EMPTY);
    this.onChange(EMPTY);
    this.field().nativeElement.focus();
  }

  protected onKeydown(event: KeyboardEvent): void {
    const options = this.filtered();

    switch (event.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        event.preventDefault();

        if (!this.open()) {
          this.openList();

          return;
        }

        const step = event.key === 'ArrowDown' ? 1 : -1;
        const count = options.length;

        if (count > 0) {
          this.activeIndex.update((index) => (index + step + count) % count);
        }

        return;
      }

      case 'Enter': {
        if (!this.open()) {
          return;
        }

        // Le formulaire ne doit pas se soumettre parce qu'on valide une option.
        event.preventDefault();

        const option = options[this.activeIndex()];

        if (option !== undefined) {
          this.select(option);
        }

        return;
      }

      case 'Escape': {
        if (this.open()) {
          event.stopPropagation();
          this.close();
        }

        return;
      }

      case 'Tab':
        this.close();
    }
  }

  /**
   * Ferme sans rien choisir : le champ retrouve le libellé retenu.
   *
   * Sans ce retour, une recherche abandonnée laissait dans le champ un texte qui
   * ne correspondait à aucun choix — l'utilisateur croyait avoir sélectionné.
   */
  protected close(): void {
    if (!this.open()) {
      return;
    }

    this.open.set(false);
    this.query.set(EMPTY);
    this.onTouched();
  }

  /** Un clic ailleurs dans la page vaut abandon. */
  @HostListener('document:pointerdown', ['$event'])
  protected onDocumentPointerDown(event: PointerEvent): void {
    if (this.open() && !this.host.nativeElement.contains(event.target as Node)) {
      this.close();
    }
  }

  protected optionId(index: number): string {
    return `${this.inputId() || 'combobox'}-option-${index}`;
  }
}

/**
 * Minuscules sans accents : `NFD` sépare la lettre de son signe diacritique,
 * que la plage Unicode retire ensuite.
 */
function normalize(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}
