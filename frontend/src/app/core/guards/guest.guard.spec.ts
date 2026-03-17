import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, RouterStateSnapshot, UrlTree } from '@angular/router';
import { provideRouter } from '@angular/router';
import { signal } from '@angular/core';

import { guestGuard } from './guest.guard';
import { AuthService } from '../services/auth.service';

describe('guestGuard', () => {
  const mockRoute = {} as ActivatedRouteSnapshot;
  const mockState = {} as RouterStateSnapshot;

  function createAuthServiceMock(isAuthenticated: boolean) {
    const isAuthSignal = signal(isAuthenticated);
    return {
      isAuthenticated: isAuthSignal.asReadonly(),
    };
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([])],
    });
  });

  it('returns true when the user is NOT authenticated (guest)', () => {
    TestBed.overrideProvider(AuthService, {
      useValue: createAuthServiceMock(false),
    });

    const result = TestBed.runInInjectionContext(() => guestGuard(mockRoute, mockState));
    expect(result).toBe(true);
  });

  it('returns a UrlTree redirecting to /dashboard when authenticated', () => {
    TestBed.overrideProvider(AuthService, {
      useValue: createAuthServiceMock(true),
    });

    const result = TestBed.runInInjectionContext(() => guestGuard(mockRoute, mockState));
    expect(result).toBeInstanceOf(UrlTree);
    expect((result as UrlTree).toString()).toBe('/dashboard');
  });
});
