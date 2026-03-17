import { Injectable, Optional } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

@Injectable({
  providedIn: 'root',
})
export class Api {
  private readonly baseUrl = '/api';
  private http: HttpClient | null;

  constructor(@Optional() http: HttpClient | null) {
    this.http = http;
  }

  private buildUrl(path: string): string {
    const normalizedPath = path.replace(/^\/+/, '');
    return `${this.baseUrl}/${normalizedPath}`;
  }

  private getHttpClient(): HttpClient {
    if (!this.http) {
      throw new Error('HttpClient has not been provided to Api service.');
    }
    return this.http;
  }

  get<T>(path: string): Observable<T> {
    return this.getHttpClient().get<T>(this.buildUrl(path));
  }

  post<T>(path: string, body: unknown): Observable<T> {
    return this.getHttpClient().post<T>(this.buildUrl(path), body);
  }

  put<T>(path: string, body: unknown): Observable<T> {
    return this.getHttpClient().put<T>(this.buildUrl(path), body);
  }

  delete<T>(path: string): Observable<T> {
    return this.getHttpClient().delete<T>(this.buildUrl(path));
  }
}
