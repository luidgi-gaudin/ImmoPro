import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { DatePipe } from '@angular/common';
import { ImmoproButtonComponent } from 'ui-lib';
import { QuittanceData } from '../../core/services/lease.service';

/**
 * Aperçu imprimable d'une quittance de loyer (art. 21, loi n° 89-462).
 *
 * Extrait de l'écran des baux pour une raison simple : c'est le seul endroit de
 * l'application qui doit être blanc sur noir, en encre, avec des couleurs
 * figées et non des variables de thème. Sa feuille de style n'a rien de commun
 * avec celle d'un écran de gestion, et la mélanger aux styles des baux rendait
 * les deux illisibles.
 *
 * Le contenu vient du serveur, qui refuse de délivrer une quittance tant que le
 * loyer et les charges ne sont pas intégralement réglés.
 */
@Component({
  selector: 'app-quittance-preview',
  standalone: true,
  imports: [DatePipe, ImmoproButtonComponent],
  templateUrl: './quittance-preview.component.html',
  styleUrl: './quittance-preview.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class QuittancePreviewComponent {
  details = input.required<QuittanceData>();

  readonly closed = output<void>();
  readonly print = output<void>();
}
