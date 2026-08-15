import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ImmoproButtonComponent } from 'ui-lib';
import { CookieConsentService } from '../../core/services/cookie-consent.service';

@Component({
  selector: 'app-cookies',
  standalone: true,
  imports: [RouterLink, DatePipe, ImmoproButtonComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './cookies.component.html',
  styleUrl: './legal-page.scss',
})
export class CookiesComponent {
  protected readonly consent = inject(CookieConsentService);

  protected readonly lastUpdated = '15 août 2026';

  acceptAll(): void {
    this.consent.acceptAll();
  }

  rejectAll(): void {
    this.consent.rejectAll();
  }

  /** Réaffiche le bandeau pour refaire un choix depuis zéro. */
  reopenBanner(): void {
    this.consent.reopen();
  }
}
