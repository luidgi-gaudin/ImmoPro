import { Injectable, inject, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';

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
  two_factor_enabled?: boolean;
}

export interface AuthResponse {
  data?: User;
  token?: string;
  token_type?: string;
  two_factor_required?: boolean;
  challenge_token?: string;
  message?: string;
}

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private http = inject(HttpClient);
  private apiUrl = 'http://127.0.0.1:8000/api/auth';

  // Global authentication state signals
  currentUser = signal<User | null>(null);
  isAuthenticated = computed(() => !!this.currentUser());

  constructor() {
    this.loadStoredUser();
  }

  getUserProfile(): Observable<AuthResponse> {
    return this.http.get<AuthResponse>(`${this.apiUrl}/user`).pipe(
      tap((response) => {
        if (response.data) {
          this.currentUser.set(response.data);
        }
      }),
    );
  }

  login(credentials: LoginRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/login`, credentials).pipe(
      tap((response) => {
        if (!response.two_factor_required && response.token && response.data) {
          this.setToken(response.token);
          this.currentUser.set(response.data);
        }
      }),
    );
  }

  verify2FAChallenge(payload: { challenge_token: string; code?: string; recovery_code?: string }): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/2fa/challenge`, payload).pipe(
      tap((response) => {
        if (response.token && response.data) {
          this.setToken(response.token);
          this.currentUser.set(response.data);
        }
      })
    );
  }

  register(data: RegisterRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/register`, data).pipe(
      tap((response) => {
        if (response.token && response.data) {
          this.setToken(response.token);
          this.currentUser.set(response.data);
        }
      }),
    );
  }

  logout(): Observable<any> {
    return this.http.post(`${this.apiUrl}/logout`, {}).pipe(
      tap(() => {
        this.clearSession();
      }),
    );
  }

  clearSession(): void {
    this.clearToken();
    this.currentUser.set(null);
  }

  getCurrentUser(): User | null {
    return this.currentUser();
  }

  // 2FA Management Endpoints
  enable2FA(): Observable<{ secret: string; otpauth_url: string; message: string }> {
    return this.http.post<{ secret: string; otpauth_url: string; message: string }>(`${this.apiUrl}/2fa/enable`, {});
  }

  confirm2FA(code: string): Observable<{ message: string; recovery_codes: string[] }> {
    return this.http.post<{ message: string; recovery_codes: string[] }>(`${this.apiUrl}/2fa/confirm`, { code }).pipe(
      tap(() => {
        // Refresh profile to update 2FA status
        this.getUserProfile().subscribe();
      })
    );
  }

  disable2FA(password: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.apiUrl}/2fa/disable`, { password }).pipe(
      tap(() => {
        // Refresh profile to update 2FA status
        this.getUserProfile().subscribe();
      })
    );
  }

  regenerateRecoveryCodes(password: string): Observable<{ recovery_codes: string[] }> {
    return this.http.post<{ recovery_codes: string[] }>(`${this.apiUrl}/2fa/recovery-codes`, { password });
  }

  // Password Recovery Endpoints
  forgotPassword(email: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.apiUrl}/forgot-password`, { email });
  }

  resetPassword(payload: any): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.apiUrl}/reset-password`, payload);
  }

  updatePassword(payload: any): Observable<{ message: string }> {
    return this.http.put<{ message: string }>(`${this.apiUrl}/password`, payload);
  }

  private setToken(token: string): void {
    localStorage.setItem('auth_token', token);
  }

  public getToken(): string | null {
    return localStorage.getItem('auth_token');
  }

  private clearToken(): void {
    localStorage.removeItem('auth_token');
  }

  private loadStoredUser(): void {
    const token = this.getToken();
    if (token) {
      this.getUserProfile().subscribe({
        error: () => this.clearSession(),
      });
    }
  }

  getAuthHeader(): string | null {
    const token = this.getToken();
    return token ? `Bearer ${token}` : null;
  }
}
