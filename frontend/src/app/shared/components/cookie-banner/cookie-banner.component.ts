import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ImmoproButtonComponent } from 'ui-lib';
import { CookieConsentService } from '../../../core/services/cookie-consent.service';

/**
 * Bandeau de consentement.
 *
 * Deux principes tenus par la CNIL guident sa construction :
 * refuser doit être aussi simple qu'accepter (deux boutons de même niveau,
 * jamais un refus caché derrière un sous-menu), et rien n'est déposé au-delà du
 * strictement nécessaire tant qu'aucun choix n'a été fait.
 */
@Component({
  selector: 'app-cookie-banner',
  standalone: true,
  imports: [RouterLink, ImmoproButtonComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './cookie-banner.component.html',
  styleUrl: './cookie-banner.component.scss',
})
export class CookieBannerComponent {
  protected readonly consent = inject(CookieConsentService);

  protected readonly detailsOpen = signal(false);
  /** Case « Préférences » du panneau détaillé, avant validation. */
  protected readonly preferences = signal(false);

  toggleDetails(): void {
    this.detailsOpen.update((open) => !open);
  }

  togglePreferences(event: Event): void {
    this.preferences.set((event.target as HTMLInputElement).checked);
  }

  acceptAll(): void {
    this.consent.acceptAll();
  }

  rejectAll(): void {
    this.consent.rejectAll();
  }

  saveChoices(): void {
    this.consent.save({ necessaires: true, preferences: this.preferences() });
  }
}
