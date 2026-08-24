import { Injectable, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { apiUrl } from '../config/api.config';
import { Observable, of } from 'rxjs';
import { catchError, map } from 'rxjs/operators';

export interface SearchItem {
  id: number;
  type: 'portfolio' | 'property' | 'tenant' | 'lease' | 'alert';
  title: string;
  subtitle: string;
  badge?: string;
  badge_tone?: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
  url: string;
}

export interface GlobalSearchResponse {
  query: string;
  total: number;
  results: {
    portfolios: SearchItem[];
    properties: SearchItem[];
    tenants: SearchItem[];
    leases: SearchItem[];
    alerts: SearchItem[];
  };
}

@Injectable({
  providedIn: 'root',
})
export class GlobalSearchService {
  private http = inject(HttpClient);
  private apiUrl = apiUrl('search');

  readonly isOpen = signal(false);

  open(): void {
    this.isOpen.set(true);
  }

  close(): void {
    this.isOpen.set(false);
  }

  toggle(): void {
    this.isOpen.update((v) => !v);
  }

  search(query: string): Observable<GlobalSearchResponse> {
    const trimmed = query.trim();
    if (trimmed.length < 2) {
      return of({
        query: trimmed,
        total: 0,
        results: {
          portfolios: [],
          properties: [],
          tenants: [],
          leases: [],
          alerts: [],
        },
      });
    }

    const params = new HttpParams().set('q', trimmed);
    return this.http.get<GlobalSearchResponse>(this.apiUrl, { params }).pipe(
      catchError(() =>
        of({
          query: trimmed,
          total: 0,
          results: {
            portfolios: [],
            properties: [],
            tenants: [],
            leases: [],
            alerts: [],
          },
        }),
      ),
    );
  }
}
