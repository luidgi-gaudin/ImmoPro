import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface Property {
  id: number;
  name: string;
  address: string;
  city: string;
  type: string;
  price: number;
}

export interface Portfolio {
  id: number;
  name: string;
  description: string;
  properties_count: number;
  properties: Property[];
}

@Injectable({
  providedIn: 'root',
})
export class PortfolioService {
  private http = inject(HttpClient);
  private apiUrl = 'http://127.0.0.1:8000/api/portfolios';

  getPortfolios(): Observable<Portfolio[]> {
    return this.http.get<Portfolio[]>(this.apiUrl);
  }

  createPortfolio(portfolio: Partial<Portfolio>): Observable<Portfolio> {
    return this.http.post<Portfolio>(this.apiUrl, portfolio);
  }
}
