import { computed, inject, Injectable, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { catchError, EMPTY, firstValueFrom, map, Observable, of, switchMap, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import { LoginCredentials, RegisterCredentials, User } from '../models/user.model';

interface UserResource {
  data: User;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);

  private readonly _currentUser = signal<User | null>(null);

  readonly currentUser = this._currentUser.asReadonly();
  readonly isAuthenticated = computed(() => this._currentUser() !== null);

  /**
   * Called once at app startup via provideAppInitializer.
   * Initializes the CSRF cookie then restores the session if one exists.
   */
  async initialize(): Promise<void> {
    await firstValueFrom(
      this.initializeCsrf().pipe(
        switchMap(() => this.fetchCurrentUser()),
        catchError(() => EMPTY),
      ),
      { defaultValue: undefined },
    );
  }

  register(credentials: RegisterCredentials): Observable<User> {
    return this.http
      .post<UserResource>(`${environment.apiUrl}/auth/register`, credentials)
      .pipe(
        map((response) => response.data),
        tap((user) => this._currentUser.set(user)),
      );
  }

  login(credentials: LoginCredentials): Observable<User> {
    return this.http
      .post<UserResource>(`${environment.apiUrl}/auth/login`, credentials)
      .pipe(
        map((response) => response.data),
        tap((user) => this._currentUser.set(user)),
      );
  }

  logout(): Observable<void> {
    return this.http
      .post<void>(`${environment.apiUrl}/auth/logout`, {})
      .pipe(tap(() => this._currentUser.set(null)));
  }

  clearSession(): void {
    this._currentUser.set(null);
  }

  fetchCurrentUser(): Observable<User> {
    return this.http
      .get<UserResource>(`${environment.apiUrl}/auth/user`)
      .pipe(
        map((response) => response.data),
        tap((user) => this._currentUser.set(user)),
      );
  }

  /**
   * Fetches the CSRF cookie from Sanctum, enabling XSRF protection
   * for all subsequent mutating requests. Failure is non-fatal
   * since GET requests do not require a CSRF token.
   */
  private initializeCsrf(): Observable<unknown> {
    return this.http.get(environment.sanctumCsrfUrl).pipe(catchError(() => of(null)));
  }
}
