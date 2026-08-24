import { Component, ElementRef, afterNextRender, input, output, viewChild } from '@angular/core';

/**
 * Enveloppe de boîte de dialogue, partagée par tous les écrans.
 *
 * Chaque page réimplémentait la sienne : même voile, même carte centrée, mais
 * des comportements différents — certaines se fermaient avec Échap, d'autres
 * non, aucune ne rendait le focus, et le contenu de la page restait accessible
 * au clavier derrière le voile.
 *
 * Ce composant s'appuie sur l'élément natif `<dialog>` : le navigateur fournit
 * alors le rendu au-dessus de tout le reste, le confinement du focus, la
 * fermeture par Échap et l'inertie de l'arrière-plan — quatre comportements
 * qu'une implémentation maison rate presque toujours au moins en partie.
 */
@Component({
  selector: 'immopro-modal',
  standalone: true,
  imports: [],
  templateUrl: './immopro-modal.component.html',
  styleUrls: ['./immopro-modal.component.scss'],
})
export class ImmoproModalComponent {
  title = input<string>('');
  subtitle = input<string>('');

  /** `wide` pour les formulaires à deux colonnes, `full` pour un aperçu de document. */
  size = input<'default' | 'wide' | 'full'>('default');

  /**
   * Empêche la fermeture par Échap ou par clic sur le voile. À réserver aux
   * opérations en cours : une modale qu'on ne peut pas fermer est une impasse.
   */
  persistent = input<boolean>(false);

  readonly closed = output<void>();

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    // `showModal()` doit être appelé après l'insertion dans le document : c'est
    // lui qui déclenche le voile natif et le confinement du focus.
    afterNextRender(() => this.dialog().nativeElement.showModal());
  }

  /** Échap : le navigateur émet `cancel` avant de fermer, on peut donc s'y opposer. */
  onCancel(event: Event): void {
    event.preventDefault();

    if (!this.persistent()) {
      this.close();
    }
  }

  /**
   * Clic sur le voile.
   *
   * L'événement vise l'élément `<dialog>` lui-même uniquement quand le clic
   * tombe en dehors de la carte : c'est ainsi qu'on distingue le voile du
   * contenu sans écouter le document entier.
   */
  onBackdropClick(event: MouseEvent): void {
    if (event.target === this.dialog().nativeElement && !this.persistent()) {
      this.close();
    }
  }

  close(): void {
    this.dialog().nativeElement.close();
    this.closed.emit();
  }
}
