import { Component, OnInit, OnDestroy, inject, effect, computed, ChangeDetectionStrategy } from '@angular/core';
import { ActivatedRoute, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { ImmoproButtonComponent, ImmoproPageHeaderComponent } from 'ui-lib';
import { PortfolioContextService } from './portfolio-context.service';
import { NavContextService } from '../../core/services/nav-context.service';

@Component({
  selector: 'app-portfolio-shell',
  standalone: true,
  imports: [RouterLink, RouterLinkActive, RouterOutlet, ImmoproButtonComponent, ImmoproPageHeaderComponent],
  providers: [PortfolioContextService],
  templateUrl: './portfolio-shell.component.html',
  styleUrl: './portfolio-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PortfolioShellComponent implements OnInit, OnDestroy {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private navContext = inject(NavContextService);
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

      this.navContext.set({
        title: this.ctx.portfolio()?.name || 'Portefeuille',
        backLabel: 'Tous les portefeuilles',
        backLink: '/portfolios',
        links: this.tabLinks(),
      });
    });
  }

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const id = Number(params.get('id'));
      if (!id) {
        this.ctx.error.set('Portfolio introuvable');
        return;
      }
      this.ctx.load(id);
    });
  }

  ngOnDestroy(): void {
    this.navContext.clear();
  }

  goBack(): void {
    this.router.navigate(['/portfolios']);
  }
}
