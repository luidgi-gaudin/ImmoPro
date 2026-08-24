import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BreadcrumbService } from '../../../core/seo/breadcrumb.service';

/**
 * Fil d'Ariane. Masqué en dessous de deux maillons, et le dernier n'est pas
 * cliquable : un lien vers la page courante est une fausse piste.
 */
@Component({
  selector: 'app-breadcrumb',
  standalone: true,
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (crumbs().length > 1) {
      <nav class="ip-breadcrumb" aria-label="Fil d'Ariane">
        <ol>
          @for (crumb of crumbs(); track crumb.url; let last = $last) {
            <li>
              @if (last) {
                <span aria-current="page">{{ crumb.label }}</span>
              } @else {
                <a [routerLink]="crumb.url">{{ crumb.label }}</a>
                <svg
                  class="sep"
                  aria-hidden="true"
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <polyline points="9 18 15 12 9 6" />
                </svg>
              }
            </li>
          }
        </ol>
      </nav>
    }
  `,
  styles: `
    .ip-breadcrumb {
      padding: 1.5rem;
      font-size: 0.82rem;
      line-height: 1.4;
    }

    ol {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 4px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    li {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      min-width: 0;
    }

    a {
      color: var(--text-secondary);
      text-decoration: none;
      border-bottom: 1px solid transparent;
      padding: 2px 0;
      transition:
        color var(--transition-fast),
        border-color var(--transition-fast);

      &:hover {
        color: var(--primary);
        border-bottom-color: var(--primary);
      }

      &:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
        border-radius: var(--radius-sm);
      }
    }

    /* Le maillon courant porte le poids visuel. */
    span[aria-current] {
      color: var(--text-primary);
      font-weight: 600;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 46ch;
    }

    .sep {
      color: var(--text-muted);
      flex-shrink: 0;
    }
  `,
})
export class BreadcrumbComponent {
  private readonly breadcrumbs = inject(BreadcrumbService);
  protected readonly crumbs = computed(() => this.breadcrumbs.trail());
}
