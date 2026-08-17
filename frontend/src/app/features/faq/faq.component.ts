import { ChangeDetectionStrategy, Component, DestroyRef, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ImmoproButtonComponent } from 'ui-lib';
import { BreadcrumbComponent } from '../../shared/components/breadcrumb/breadcrumb.component';
import { SeoService } from '../../core/seo/seo.service';
import { absoluteUrl } from '../../core/config/site.config';
import { FAQ_ENTRIES, buildFaqJsonLd } from './faq.content';

/**
 * Questions fréquentes. Le balisage `FAQPage` est retiré à la destruction :
 * laissé en place, il décrirait une FAQ sur des écrans qui n'en ont pas.
 */
@Component({
  selector: 'app-faq',
  standalone: true,
  imports: [RouterLink, BreadcrumbComponent, ImmoproButtonComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './faq.component.html',
  styleUrl: './faq.component.scss',
})
export class FaqComponent {
  private readonly seo = inject(SeoService);

  protected readonly entries = FAQ_ENTRIES;

  constructor() {
    this.seo.setJsonLd('faq', buildFaqJsonLd(absoluteUrl('/faq')));
    inject(DestroyRef).onDestroy(() => this.seo.setJsonLd('faq', null));
  }
}
