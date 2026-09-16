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

/** Profil choisi à l'inscription : point de vue, pas niveau de privilège. */
export type UserRole = 'proprietaire' | 'locataire';

/**
 * Le téléphone n'y figure pas : il se renseigne depuis le profil, une fois le
 * compte créé.
 */
export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  role: UserRole;
}

export interface SocialProvider {
  value: 'google' | 'apple';
  label: string;
  enabled: boolean;

  /**
   * Identifiant client public du fournisseur.
   *
   * Public par construction : le navigateur doit le présenter pour ouvrir la
   * fenêtre de connexion. Aucun secret ne circule ici — le serveur ne joue pas
   * le rôle de client OAuth, il vérifie le jeton rapporté.
   */
  client_id: string | null;
}

export interface LinkedSocialAccount {
  provider: 'google' | 'apple';
  label: string;
  email: string | null;
  linked_at: string | null;
}

export interface User {
  id: number;
  name: string;
  email: string;
  phone?: string | null;

  /** Adresse en attente de confirmation, s'il y a un changement en cours. */
  pending_email?: string | null;

  role: UserRole;
  role_label?: string;

  /** Écran d'arrivée du profil, décidé par le serveur. */
  home_path?: string;

  email_verified?: boolean;
  two_factor_enabled?: boolean;
  has_password?: boolean;
  has_avatar?: boolean;

  social_accounts?: LinkedSocialAccount[];
  available_providers?: SocialProvider[];

  created_at: string;
}

/**
 * Ce que le serveur répond quand un code vient d'être envoyé.
 *
 * `debug_code` n'est renseigné qu'en dehors de la production : il évite
 * d'ouvrir une boîte de réception pour dérouler le parcours en local.
 */
export interface OtpChallenge {
  expires_in_minutes: number;
  resend_after_seconds: number;
  debug_code?: string | null;
}

export interface NotificationTopic {
  value: string;
  label: string;
  description: string;
  default_channels: string[];
}

export interface NotificationChannelOption {
  value: string;
  label: string;
}

export interface NotificationPreferences {
  topics: NotificationTopic[];
  channels: NotificationChannelOption[];
  preferences: Record<string, string[]>;

  /** Une adresse non vérifiée ne reçoit aucun courriel, réglage ou non. */
  mail_available: boolean;
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

  /**
   * L'adresse n'est pas encore vérifiée : le parcours passe par l'écran du
   * code avant d'obtenir un jeton. Renvoyé par l'inscription (201) comme par
   * une tentative de connexion (403).
   */
  email_verification_required?: boolean;

  /** Adresse concernée, quand le serveur la rappelle avec le refus. */
  email?: string;

  otp?: OtpChallenge;

  /** Compte créé à l'instant, sur une connexion externe. */
  created?: boolean;

  /** Dossiers locataire rattachés au compte lors de la vérification. */
  linked_tenant_profiles?: number;
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

  /**
   * Inscription.
   *
   * Ne connecte pas : le serveur envoie un code et n'émet le jeton qu'une fois
   * l'adresse vérifiée. Rien n'est donc enregistré ici — écrire un utilisateur
   * en session sans jeton laisserait l'application se croire connectée alors
   * que le premier appel à l'API échouerait.
   */
  register(data: RegisterRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/register`, data);
  }

  /* ----------------------------------------------------------------------
   | Vérification de l'adresse par code à usage unique
   |----------------------------------------------------------------------*/

  /** (Re)demande un code. La réponse est la même que l'adresse existe ou non. */
  sendOtp(email: string): Observable<{ message: string; otp: OtpChallenge }> {
    return this.http.post<{ message: string; otp: OtpChallenge }>(`${this.apiUrl}/otp/send`, {
      email,
    });
  }

  /** Valide le code et ouvre la session : c'est ici que le jeton arrive. */
  verifyOtp(email: string, code: string): Observable<AuthResponse> {
    return this.http
      .post<AuthResponse>(`${this.apiUrl}/otp/verify`, { email, code })
      .pipe(tap((response) => this.adoptSession(response)));
  }

  /* ----------------------------------------------------------------------
   | Google et Apple
   |----------------------------------------------------------------------*/

  /** Fournisseurs paramétrés sur ce serveur : le front n'en devine aucun. */
  socialProviders(): Observable<{ data: SocialProvider[] }> {
    return this.http.get<{ data: SocialProvider[] }>(`${this.apiUrl}/providers`);
  }

  /**
   * Connexion ou inscription par un fournisseur externe.
   *
   * `role` n'est lu par le serveur qu'à la création du compte : le renvoyer sur
   * une connexion ordinaire ne change rien.
   */
  signInWithProvider(
    provider: 'google' | 'apple',
    payload: { id_token: string; nonce?: string; role?: UserRole },
  ): Observable<AuthResponse> {
    return this.http
      .post<AuthResponse>(`${this.apiUrl}/social/${provider}`, payload)
      .pipe(tap((response) => this.adoptSession(response)));
  }

  linkProvider(
    provider: 'google' | 'apple',
    payload: { id_token: string; nonce?: string },
  ): Observable<{ data: User; message: string }> {
    return this.http
      .post<{ data: User; message: string }>(`${this.apiUrl}/social/${provider}/link`, payload)
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  unlinkProvider(provider: 'google' | 'apple'): Observable<{ data: User; message: string }> {
    return this.http
      .delete<{ data: User; message: string }>(`${this.apiUrl}/social/${provider}`)
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  /* ----------------------------------------------------------------------
   | Informations personnelles
   |----------------------------------------------------------------------*/

  getProfile(): Observable<{ data: User }> {
    return this.http
      .get<{ data: User }>(`${this.apiUrl}/profile`)
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  updateProfile(payload: Partial<User>): Observable<{ data: User; message: string }> {
    return this.http
      .put<{ data: User; message: string }>(`${this.apiUrl}/profile`, payload)
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  /** Demande un changement d'adresse : le code part vers la nouvelle boîte. */
  requestEmailChange(payload: {
    email: string;
    password?: string;
  }): Observable<{ message: string; pending_email: string; otp: OtpChallenge }> {
    return this.http.post<{ message: string; pending_email: string; otp: OtpChallenge }>(
      `${this.apiUrl}/profile/email`,
      payload,
    );
  }

  confirmEmailChange(code: string): Observable<{ data: User; message: string }> {
    return this.http
      .post<{ data: User; message: string }>(`${this.apiUrl}/profile/email/confirm`, { code })
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  cancelEmailChange(): Observable<{ data: User; message: string }> {
    return this.http
      .delete<{ data: User; message: string }>(`${this.apiUrl}/profile/email`)
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  /**
   * URL de la photo de profil.
   *
   * Le paramètre d'horodatage force le navigateur à recharger après un
   * remplacement : l'URL ne change pas, et le cache servirait l'ancienne image.
   */
  avatarUrl(version?: number): string {
    return `${this.apiUrl}/profile/avatar${version ? `?v=${version}` : ''}`;
  }

  uploadAvatar(file: File): Observable<{ data: User; message: string }> {
    const body = new FormData();
    body.append('file', file);

    return this.http
      .post<{ data: User; message: string }>(`${this.apiUrl}/profile/avatar`, body)
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  deleteAvatar(): Observable<{ data: User; message: string }> {
    return this.http
      .delete<{ data: User; message: string }>(`${this.apiUrl}/profile/avatar`)
      .pipe(tap((response) => this.adoptUser(response.data)));
  }

  /* ----------------------------------------------------------------------
   | Préférences de notification
   |----------------------------------------------------------------------*/

  notificationPreferences(): Observable<{ data: NotificationPreferences }> {
    return this.http.get<{ data: NotificationPreferences }>(
      `${this.apiUrl}/notification-preferences`,
    );
  }

  saveNotificationPreferences(
    preferences: Record<string, string[]>,
  ): Observable<{ data: { preferences: Record<string, string[]> }; message: string }> {
    return this.http.put<{ data: { preferences: Record<string, string[]> }; message: string }>(
      `${this.apiUrl}/notification-preferences`,
      { preferences },
    );
  }

  logout(): Observable<any> {
    return this.http.post(`${this.apiUrl}/logout`, {}).pipe(
      tap(() => {
        this.clearSession();
      }),
    );
  }

  /** Enregistre le jeton et le compte rendus par une réponse d'authentification. */
  private adoptSession(response: AuthResponse): void {
    if (response.token && response.data) {
      this.setToken(response.token);
      this.adoptUser(response.data);
    }

    this.session.adopt(response.session);
    this.alerts.adoptUnreadCount(response.alerts_unread);
  }

  private adoptUser(user: User): void {
    this.setStoredUser(user);
    this.currentUser.set(user);
  }

  /** Écran d'arrivée du profil connecté, dicté par le serveur. */
  homePath(): string {
    return this.currentUser()?.home_path ?? '/dashboard';
  }

  isTenant(): boolean {
    return this.currentUser()?.role === 'locataire';
  }

  isLandlord(): boolean {
    return this.currentUser()?.role !== 'locataire';
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
