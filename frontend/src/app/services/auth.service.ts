import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, Observable, tap } from 'rxjs';

export interface LoginRequest {
  email: string;
  password: string;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface User {
  id: number;
  name: string;
  email: string;
  created_at: string;
}

export interface AuthResponse {
  data: User;
  token: string;
  token_type: string;
}

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private http = inject(HttpClient);
  private apiUrl = '/api/auth';

  private currentUserSubject = new BehaviorSubject<User | null>(null);
  public currentUser$ = this.currentUserSubject.asObservable();

  private isAuthenticatedSubject = new BehaviorSubject<boolean>(false);
  public isAuthenticated$ = this.isAuthenticatedSubject.asObservable();

  constructor() {
    this.loadStoredUser();
  }

  login(credentials: LoginRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/login`, credentials).pipe(
      tap((response) => {
        this.setToken(response.token);
        this.currentUserSubject.next(response.data);
        this.isAuthenticatedSubject.next(true);
      })
    );
  }

  register(data: RegisterRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/register`, data).pipe(
      tap((response) => {
        this.setToken(response.token);
        this.currentUserSubject.next(response.data);
        this.isAuthenticatedSubject.next(true);
      })
    );
  }

  logout(): Observable<any> {
    return this.http.post(`${this.apiUrl}/logout`, {}).pipe(
      tap(() => {
        this.clearToken();
        this.currentUserSubject.next(null);
        this.isAuthenticatedSubject.next(false);
      })
    );
  }

  getCurrentUser(): User | null {
    return this.currentUserSubject.value;
  }

  isAuthenticated(): boolean {
    return !!this.getToken();
  }

  private setToken(token: string): void {
    localStorage.setItem('auth_token', token);
  }

  private getToken(): string | null {
    return localStorage.getItem('auth_token');
  }

  private clearToken(): void {
    localStorage.removeItem('auth_token');
  }

  private loadStoredUser(): void {
    const token = this.getToken();
    if (token) {
      this.isAuthenticatedSubject.next(true);
    }
  }

  getAuthHeader(): string | null {
    const token = this.getToken();
    return token ? `Bearer ${token}` : null;
  }
}
