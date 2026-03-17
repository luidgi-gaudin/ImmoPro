import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';

import { AuthService } from './auth.service';
import { User } from '../models/user.model';

const mockUser: User = {
  id: 1,
  name: 'Alice Dupont',
  email: 'alice@test.com',
  created_at: '2024-01-01T00:00:00Z',
};

describe('AuthService', () => {
  let service: AuthService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [AuthService, provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(AuthService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  // ── currentUser initial state ───────────────────────────────────────────────

  it('should have null currentUser initially', () => {
    expect(service.currentUser()).toBeNull();
  });

  // ── isAuthenticated ─────────────────────────────────────────────────────────

  it('isAuthenticated is false initially', () => {
    expect(service.isAuthenticated()).toBe(false);
  });

  it('isAuthenticated is true after a successful login', async () => {
    const loginPromise = firstValueFrom(
      service.login({ email: 'alice@test.com', password: 'password1' }),
    );
    const req = httpMock.expectOne('/api/auth/login');
    req.flush(mockUser);
    await loginPromise;
    expect(service.isAuthenticated()).toBe(true);
  });

  // ── initialize() ───────────────────────────────────────────────────────────

  it('initialize() fetches CSRF then current user and sets currentUser', async () => {
    const initPromise = service.initialize();

    const csrfReq = httpMock.expectOne('/sanctum/csrf-cookie');
    csrfReq.flush(null);

    const userReq = httpMock.expectOne('/api/auth/user');
    userReq.flush(mockUser);

    await initPromise;

    expect(service.currentUser()).toEqual(mockUser);
    expect(service.isAuthenticated()).toBe(true);
  });

  it('initialize() handles 401 on user fetch gracefully', async () => {
    const initPromise = service.initialize();

    const csrfReq = httpMock.expectOne('/sanctum/csrf-cookie');
    csrfReq.flush(null);

    const userReq = httpMock.expectOne('/api/auth/user');
    userReq.flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    await initPromise;

    expect(service.currentUser()).toBeNull();
    expect(service.isAuthenticated()).toBe(false);
  });

  it('initialize() handles CSRF error gracefully and still completes', async () => {
    const initPromise = service.initialize();

    const csrfReq = httpMock.expectOne('/sanctum/csrf-cookie');
    // initializeCsrf uses catchError(() => of(null)) so it succeeds even if CSRF fails
    csrfReq.flush('error', { status: 500, statusText: 'Server Error' });

    // After CSRF catchError, fetchCurrentUser is called
    const userReq = httpMock.expectOne('/api/auth/user');
    userReq.flush(null, { status: 401, statusText: 'Unauthorized' });

    await initPromise;

    expect(service.currentUser()).toBeNull();
  });

  // ── login() ────────────────────────────────────────────────────────────────

  it('login() POSTs to /api/auth/login and sets currentUser', async () => {
    const loginPromise = firstValueFrom(
      service.login({ email: 'alice@test.com', password: 'password1' }),
    );

    const req = httpMock.expectOne('/api/auth/login');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ email: 'alice@test.com', password: 'password1' });
    req.flush(mockUser);

    const result = await loginPromise;
    expect(result).toEqual(mockUser);
    expect(service.currentUser()).toEqual(mockUser);
    expect(service.isAuthenticated()).toBe(true);
  });

  // ── logout() ───────────────────────────────────────────────────────────────

  it('logout() POSTs to /api/auth/logout and clears currentUser', async () => {
    // Set up authenticated state
    const loginPromise = firstValueFrom(
      service.login({ email: 'alice@test.com', password: 'password1' }),
    );
    httpMock.expectOne('/api/auth/login').flush(mockUser);
    await loginPromise;
    expect(service.isAuthenticated()).toBe(true);

    // Logout
    const logoutPromise = firstValueFrom(service.logout());
    const req = httpMock.expectOne('/api/auth/logout');
    expect(req.request.method).toBe('POST');
    req.flush(null);
    await logoutPromise;

    expect(service.currentUser()).toBeNull();
    expect(service.isAuthenticated()).toBe(false);
  });

  // ── register() ─────────────────────────────────────────────────────────────

  it('register() POSTs to /api/auth/register and sets currentUser', async () => {
    const registerPromise = firstValueFrom(
      service.register({
        name: 'Alice Dupont',
        email: 'alice@test.com',
        password: 'password1',
        password_confirmation: 'password1',
      }),
    );

    const req = httpMock.expectOne('/api/auth/register');
    expect(req.request.method).toBe('POST');
    req.flush(mockUser);

    const result = await registerPromise;
    expect(result).toEqual(mockUser);
    expect(service.currentUser()).toEqual(mockUser);
    expect(service.isAuthenticated()).toBe(true);
  });

  // ── currentUser after login ────────────────────────────────────────────────

  it('currentUser returns user data after login', async () => {
    const loginPromise = firstValueFrom(
      service.login({ email: 'alice@test.com', password: 'pass12345' }),
    );
    httpMock.expectOne('/api/auth/login').flush(mockUser);
    await loginPromise;
    expect(service.currentUser()).toEqual(mockUser);
    expect(service.currentUser()?.name).toBe('Alice Dupont');
  });
});
