import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { NotificationService } from '../../../core/services/notification.service';

/**
 * Zone d'affichage des messages transitoires, montée une fois pour toute
 * l'application.
 *
 * Placée en bas à droite plutôt qu'en haut de page : un bandeau qui s'insère
 * au-dessus du contenu décale ce que l'utilisateur est en train de lire, et
 * fait parfois manquer le clic suivant.
 *
 * `aria-live="polite"` fait annoncer les messages par un lecteur d'écran sans
 * interrompre la lecture en cours — un enregistrement réussi n'a pas à couper
 * la parole.
 */
@Component({
  selector: 'app-toast-host',
  standalone: true,
  imports: [],
  templateUrl: './toast-host.component.html',
  styleUrl: './toast-host.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ToastHostComponent {
  protected readonly notifications = inject(NotificationService);
}
