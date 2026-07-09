import { Component, ChangeDetectionStrategy, input } from '@angular/core';

/**
 * ImmoPro brand mark.
 * Renders a refined real-estate monogram (rooflines forming an abstract skyline)
 * inside a rounded badge, with an optional wordmark + tagline.
 */
@Component({
  selector: 'app-logo',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="logo-mark" [style.width.px]="size()" [style.height.px]="size()">
      <svg [attr.width]="size() * 0.62" [attr.height]="size() * 0.62" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <!-- Left tower -->
        <path d="M3 21V10l5-3.5" />
        <!-- Main roofline / gable -->
        <path d="M8 21V7.5L15 3l6 4.2V21" />
        <!-- Door -->
        <path d="M13 21v-4.5h3V21" />
        <!-- Window accents -->
        <path d="M13 9.5h3" />
      </svg>
    </div>

    @if (showText()) {
      <div class="logo-text">
        <span class="logo-word">ImmoPro</span>
        <span class="logo-tag">Gestion immobilière</span>
      </div>
    }
  `,
  styles: [`
    :host {
      display: inline-flex;
      align-items: center;
      gap: 12px;
    }

    .logo-mark {
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(150deg, var(--primary) 0%, var(--primary-hover) 100%);
      color: var(--on-primary);
      box-shadow: 0 6px 18px -6px var(--primary-glow), inset 0 1px 0 rgba(255, 255, 255, 0.18);
      flex-shrink: 0;
      transition: transform var(--transition-fast), box-shadow var(--transition-smooth);
    }

    .logo-mark svg { display: block; }

    .logo-text {
      display: flex;
      flex-direction: column;
      line-height: 1;
    }

    .logo-word {
      font-family: 'Space Grotesk', system-ui, sans-serif;
      font-weight: 600;
      font-size: 1.25rem;
      letter-spacing: -0.02em;
      color: var(--text-primary);
    }

    .logo-tag {
      font-family: 'Inter', system-ui, sans-serif;
      font-weight: 500;
      font-size: 0.6rem;
      letter-spacing: 0.22em;
      text-transform: uppercase;
      color: var(--text-muted);
      margin-top: 3px;
    }
  `],
})
export class LogoComponent {
  readonly showText = input<boolean>(true);
  readonly size = input<number>(40);
}
