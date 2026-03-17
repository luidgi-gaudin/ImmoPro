import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';

@Injectable({
  providedIn: 'root',
})
export class Api {
  private readonly baseUrl = '/api';

  constructor(private http: HttpClient) {}

  private buildUrl(path: string): string {
    const normalizedPath = path.replace(/^\/+/, '');
    return `${this.baseUrl}/${normalizedPath}`;
  }

  get<T>(path: string) {
    return this.http.get<T>(this.buildUrl(path));
  }

  post<T>(path: string, body: unknown) {
    return this.http.post<T>(this.buildUrl(path), body);
  }

  put<T>(path: string, body: unknown) {
    return this.http.put<T>(this.buildUrl(path), body);
  }

  delete<T>(path: string) {
    return this.http.delete<T>(this.buildUrl(path));
  }
}
