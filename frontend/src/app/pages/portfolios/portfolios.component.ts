import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { PortfolioService, Portfolio } from '../../services/portfolio.service';
import { ImmoproCardComponent, ImmoproButtonComponent } from 'ui-lib';

@Component({
  selector: 'app-portfolios',
  standalone: true,
  imports: [CommonModule, ImmoproCardComponent, ImmoproButtonComponent],
  templateUrl: './portfolios.component.html',
  styleUrl: './portfolios.component.scss'
})
export class PortfoliosComponent implements OnInit {
  private portfolioService = inject(PortfolioService);
  portfolios = signal<Portfolio[]>([]);

  ngOnInit() {
    this.loadPortfolios();
  }

  loadPortfolios() {
    this.portfolioService.getPortfolios().subscribe(data => {
      this.portfolios.set(data);
    });
  }

  addPortfolio() {
    console.log('Add portfolio');
  }

  viewPortfolio(portfolio: Portfolio) {
    console.log('View portfolio', portfolio.id);
  }
}
