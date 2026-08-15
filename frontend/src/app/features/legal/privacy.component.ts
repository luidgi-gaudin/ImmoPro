import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-privacy',
  standalone: true,
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './privacy.component.html',
  styleUrl: './legal-page.scss',
})
export class PrivacyComponent {
  /** Affichée en tête de page ; à mettre à jour quand le texte change. */
  protected readonly lastUpdated = '15 août 2026';
}
