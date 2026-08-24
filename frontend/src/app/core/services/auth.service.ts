import { Injectable, inject, signal, computed } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { apiUrl } from '../config/api.config';
import { Observable, tap } from 'rxjs';
import { SessionService, SessionState } from './session.service';
import { AlertService } from './alert.service';

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

  /**
   * Échéances de la session, annoncées dès la connexion pour que le préavis
   * d'expiration puisse être programmé sans interroger l'API en boucle.
   */
  session?: SessionState;

  /**
   * Alertes actives non lues, pour la pastille de la barre latérale.
   *
   * Elle est visible sur tous les écrans, mais le compteur n'était demandé que
   * par le tableau de bord et la page des alertes : partout ailleurs, la
   * pastille affichait « 0 ». Le faire voyager avec l'authentification évite
   * d'ajouter un appel HTTP au démarrage.
   */
  alerts_unread?: number;
}

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private http = inject(HttpClient);
  private session = inject(SessionService);
  private alerts = inject(AlertService);
  private apiUrl = apiUrl('auth');

  // Global authentication state signals
  readonly currentUser = signal<User | null>(this.getStoredUser());
  readonly isAuthenticated = computed(() => !!this.getToken() || !!this.currentUser());

  constructor() {
    this.refreshUserInBackground();
  }

  getUserProfile(): Observable<AuthResponse> {
    return this.http.get<AuthResponse>(`${this.apiUrl}/user`).pipe(
      tap((response) => {
        if (response.data) {
          this.setStoredUser(response.data);
          this.currentUser.set(response.data);
        }
        this.session.adopt(response.session);
        this.alerts.adoptUnreadCount(response.alerts_unread);
      }),
    );
  }

  login(credentials: LoginRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/login`, credentials).pipe(
      tap((response) => {
        if (!response.two_factor_required && response.token && response.data) {
          this.setToken(response.token);
          this.setStoredUser(response.data);
          this.currentUser.set(response.data);
        }
        this.session.adopt(response.session);
        this.alerts.adoptUnreadCount(response.alerts_unread);
      }),
    );
  }

  verify2FAChallenge(payload: {
    challenge_token: string;
    code?: string;
    recovery_code?: string;
  }): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/2fa/challenge`, payload).pipe(
      tap((response) => {
        if (response.token && response.data) {
          this.setToken(response.token);
          this.setStoredUser(response.data);
          this.currentUser.set(response.data);
        }
        this.session.adopt(response.session);
        this.alerts.adoptUnreadCount(response.alerts_unread);
      }),
    );
  }

  register(data: RegisterRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/register`, data).pipe(
      tap((response) => {
        if (response.token && response.data) {
          this.setToken(response.token);
          this.setStoredUser(response.data);
          this.currentUser.set(response.data);
        }
        this.session.adopt(response.session);
        this.alerts.adoptUnreadCount(response.alerts_unread);
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
    this.clearStoredUser();
    this.currentUser.set(null);
    this.session.clear();
  }

  getCurrentUser(): User | null {
    return this.currentUser();
  }

  // 2FA Management Endpoints
  enable2FA(): Observable<{ secret: string; otpauth_url: string; message: string }> {
    return this.http.post<{ secret: string; otpauth_url: string; message: string }>(
      `${this.apiUrl}/2fa/enable`,
      {},
    );
  }

  confirm2FA(code: string): Observable<{ message: string; recovery_codes: string[] }> {
    return this.http
      .post<{ message: string; recovery_codes: string[] }>(`${this.apiUrl}/2fa/confirm`, { code })
      .pipe(
        tap(() => {
          this.getUserProfile().subscribe();
        }),
      );
  }

  disable2FA(password: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.apiUrl}/2fa/disable`, { password }).pipe(
      tap(() => {
        this.getUserProfile().subscribe();
      }),
    );
  }

  regenerateRecoveryCodes(password: string): Observable<{ recovery_codes: string[] }> {
    return this.http.post<{ recovery_codes: string[] }>(`${this.apiUrl}/2fa/recovery-codes`, {
      password,
    });
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
    try {
      localStorage.setItem('auth_token', token);
    } catch {
      // Ignored in SSR or private mode
    }
  }

  public getToken(): string | null {
    try {
      return localStorage.getItem('auth_token');
    } catch {
      return null;
    }
  }

  private clearToken(): void {
    try {
      localStorage.removeItem('auth_token');
    } catch {
      // Ignored
    }
  }

  private setStoredUser(user: User): void {
    try {
      localStorage.setItem('auth_user', JSON.stringify(user));
    } catch {
      // Ignored
    }
  }

  private getStoredUser(): User | null {
    try {
      const stored = localStorage.getItem('auth_user');
      return stored ? JSON.parse(stored) : null;
    } catch {
      return null;
    }
  }

  private clearStoredUser(): void {
    try {
      localStorage.removeItem('auth_user');
    } catch {
      // Ignored
    }
  }

  private refreshUserInBackground(): void {
    const token = this.getToken();
    if (token) {
      this.getUserProfile().subscribe({
        error: (error: unknown) => {
          if (error instanceof HttpErrorResponse && error.status === 401) {
            this.clearSession();
          }
        },
      });
    }
  }

  getAuthHeader(): string | null {
    const token = this.getToken();
    return token ? `Bearer ${token}` : null;
  }
}
