import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ImmoproButtonComponent, ImmoproModalComponent } from 'ui-lib';
import { ConfirmService } from '../../../core/services/confirm.service';

/**
 * Boîte de confirmation de l'application, montée une fois à la racine.
 *
 * Remplace `window.confirm`, qui ne se met pas au thème, ne se teste pas, et ne
 * permet pas de rappeler ce qui sera emporté avec l'élément supprimé.
 *
 * Sur les suppressions en cascade, un mot à recopier est demandé. Ce n'est pas
 * une formalité : un portefeuille entraîne ses biens, leurs baux et leurs
 * quittances, et rien de tout cela ne se rattrape d'un Ctrl+Z.
 */
@Component({
  selector: 'app-confirm-dialog',
  standalone: true,
  imports: [FormsModule, ImmoproModalComponent, ImmoproButtonComponent],
  templateUrl: './confirm-dialog.component.html',
  styleUrl: './confirm-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConfirmDialogComponent {
  private readonly confirm = inject(ConfirmService);

  protected readonly request = this.confirm.pending;
  protected readonly typed = signal('');

  protected readonly canConfirm = computed(() => {
    const expected = this.request()?.typeToConfirm;

    return !expected || this.typed().trim() === expected;
  });

  protected answer(confirmed: boolean): void {
    this.typed.set('');
    this.confirm.answer(confirmed);
  }
}
