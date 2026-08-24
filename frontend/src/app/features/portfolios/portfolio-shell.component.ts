import {
  Component,
  OnInit,
  OnDestroy,
  inject,
  effect,
  computed,
  ChangeDetectionStrategy,
} from '@angular/core';
import {
  ActivatedRoute,
  Router,
  RouterLink,
  RouterLinkActive,
  RouterOutlet,
} from '@angular/router';
import { ImmoproButtonComponent, ImmoproPageHeaderComponent } from 'ui-lib';
import { PortfolioContextService } from './portfolio-context.service';
import { NavContextService } from '../../core/services/nav-context.service';
import { BreadcrumbService } from '../../core/seo/breadcrumb.service';

@Component({
  selector: 'app-portfolio-shell',
  standalone: true,
  imports: [
    RouterLink,
    RouterLinkActive,
    RouterOutlet,
    ImmoproButtonComponent,
    ImmoproPageHeaderComponent,
  ],
  providers: [PortfolioContextService],
  templateUrl: './portfolio-shell.component.html',
  styleUrl: './portfolio-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PortfolioShellComponent implements OnInit, OnDestroy {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private navContext = inject(NavContextService);
  private breadcrumbs = inject(BreadcrumbService);
  protected ctx = inject(PortfolioContextService);

  protected tabLinks = computed(() => {
    const id = this.ctx.portfolioId();
    if (!id) return [];
    return [
      { label: "Vue d'ensemble", path: `/portfolios/${id}/overview` },
      { label: 'Actifs', path: `/portfolios/${id}/properties` },
    ];
  });

  constructor() {
    // Garde le menu latéral synchronisé avec le portefeuille et son nom, une fois chargé.
    effect(() => {
      const id = this.ctx.portfolioId();
      if (!id) return;

      const name = this.ctx.portfolio()?.name;

      this.navContext.set({
        title: name || 'Portefeuille',
        backLabel: 'Tous les portefeuilles',
        backLink: '/portfolios',
        links: this.tabLinks(),
      });

      // « Portefeuille » pendant le chargement, puis le nom réel.
      if (name) {
        this.breadcrumbs.setLabel(`/portfolios/${id}`, name);
      }
    });
  }

  /**
   * Le shell enregistre le portefeuille courant, mais ne le charge pas.
   *
   * Il le faisait, et c'était un appel HTTP de trop : la sous-page affichée
   * ramène déjà le portefeuille et ses compteurs dans sa propre réponse — le
   * serveur a dû le charger de toute façon pour vérifier le droit d'accès. Les
   * écrans qui n'ont rien d'autre à demander (la vue d'ensemble) appellent
   * `ensureLoaded()` de leur côté.
   */
  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const id = Number(params.get('id'));

      if (!id) {
        this.ctx.error.set('Portefeuille introuvable');
        return;
      }

      this.ctx.setPortfolioId(id);
    });
  }

  ngOnDestroy(): void {
    this.navContext.clear();
  }

  goBack(): void {
    this.router.navigate(['/portfolios']);
  }
}
