import { Component, inject, ChangeDetectionStrategy } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { ImmoproButtonComponent, ImmoproThemeToggleComponent } from 'ui-lib';
import { FAQ_ENTRIES } from '../faq/faq.content';

@Component({
  selector: 'app-welcome',
  standalone: true,
  imports: [RouterLink, ImmoproButtonComponent, ImmoproThemeToggleComponent],
  templateUrl: './welcome.component.html',
  styleUrl: './welcome.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WelcomeComponent {
  protected auth = inject(AuthService);

  /** Limité à quatre : /faq porte le balisage `FAQPage` et reste la référence. */
  protected readonly faqPreview = FAQ_ENTRIES.slice(0, 4);

  logout(): void {
    this.auth.logout().subscribe({
      next: () => {
        window.location.href = '/login';
      },
    });
  }
}
