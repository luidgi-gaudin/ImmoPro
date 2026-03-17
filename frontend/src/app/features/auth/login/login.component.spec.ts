import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router } from '@angular/router';
import { provideRouter } from '@angular/router';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { of, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { vi } from 'vitest';

import { LoginComponent } from './login.component';
import { AuthService } from '../../../core/services/auth.service';
import { User } from '../../../core/models/user.model';

const mockUser: User = {
  id: 1,
  name: 'Test User',
  email: 'test@example.com',
  created_at: '',
};

function createAuthServiceMock() {
  return {
    login: vi.fn(),
  };
}

describe('LoginComponent', () => {
  let fixture: ComponentFixture<LoginComponent>;
  let component: LoginComponent;
  let authServiceMock: ReturnType<typeof createAuthServiceMock>;
  let router: Router;

  beforeEach(async () => {
    authServiceMock = createAuthServiceMock();

    await TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [
        provideRouter([]),
        provideAnimationsAsync(),
        { provide: AuthService, useValue: authServiceMock },
      ],
    }).compileComponents();

    router = TestBed.inject(Router);
    vi.spyOn(router, 'navigate').mockResolvedValue(true);

    fixture = TestBed.createComponent(LoginComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  // ── Rendering ───────────────────────────────────────────────────────────────

  it('should create the component', () => {
    expect(component).toBeTruthy();
  });

  it('should render the login form', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('form')).toBeTruthy();
    expect(compiled.querySelector('[formControlName="email"]')).toBeTruthy();
    expect(compiled.querySelector('[formControlName="password"]')).toBeTruthy();
  });

  // ── Form validity ───────────────────────────────────────────────────────────

  it('form is invalid when empty', () => {
    expect(component.form.invalid).toBe(true);
  });

  it('form is valid with correct values', () => {
    component.form.setValue({ email: 'test@example.com', password: 'password1' });
    expect(component.form.valid).toBe(true);
  });

  // ── hasError ────────────────────────────────────────────────────────────────

  it('hasError("email", "required") returns false before field is touched', () => {
    expect(component.hasError('email', 'required')).toBe(false);
  });

  it('hasError("email", "required") returns true after touching empty email field', () => {
    component.form.get('email')!.markAsTouched();
    fixture.detectChanges();
    expect(component.hasError('email', 'required')).toBe(true);
  });

  it('hasError("email", "email") returns true for invalid email after touch', () => {
    component.form.get('email')!.setValue('not-an-email');
    component.form.get('email')!.markAsTouched();
    expect(component.hasError('email', 'email')).toBe(true);
  });

  it('hasError("password", "required") returns false before field is touched', () => {
    expect(component.hasError('password', 'required')).toBe(false);
  });

  it('hasError("password", "required") returns true after touching empty password', () => {
    component.form.get('password')!.markAsTouched();
    expect(component.hasError('password', 'required')).toBe(true);
  });

  // ── onSubmit with invalid form ──────────────────────────────────────────────

  it('onSubmit() with invalid form marks all as touched and does NOT call authService.login', () => {
    component.onSubmit();
    expect(component.form.touched).toBe(true);
    expect(authServiceMock.login).not.toHaveBeenCalled();
  });

  // ── onSubmit with valid form ────────────────────────────────────────────────

  it('onSubmit() with valid form calls authService.login with correct values', async () => {
    authServiceMock.login.mockReturnValue(of(mockUser));

    component.form.setValue({ email: 'test@example.com', password: 'password1' });
    component.onSubmit();

    await fixture.whenStable();

    expect(authServiceMock.login).toHaveBeenCalledWith({
      email: 'test@example.com',
      password: 'password1',
    });
  });

  it('navigates to /dashboard on successful login', async () => {
    authServiceMock.login.mockReturnValue(of(mockUser));

    component.form.setValue({ email: 'test@example.com', password: 'password1' });
    component.onSubmit();

    await fixture.whenStable();

    expect(router.navigate).toHaveBeenCalledWith(['/dashboard']);
  });

  it('sets isLoading to true while request is pending', () => {
    authServiceMock.login.mockReturnValue(of(mockUser));

    component.form.setValue({ email: 'test@example.com', password: 'password1' });
    component.onSubmit();

    // isLoading becomes true synchronously within onSubmit before subscribe resolves
    expect(component.isLoading()).toBe(true);
  });

  it('sets isLoading to false on API error response', async () => {
    const errorResponse = new HttpErrorResponse({
      error: { message: 'Identifiants invalides.' },
      status: 422,
    });
    authServiceMock.login.mockReturnValue(throwError(() => errorResponse));

    component.form.setValue({ email: 'test@example.com', password: 'password1' });
    component.onSubmit();

    await fixture.whenStable();

    expect(component.isLoading()).toBe(false);
  });

  it('errorMessage is set on API error', async () => {
    const errorResponse = new HttpErrorResponse({
      error: { message: 'Identifiants invalides.' },
      status: 422,
    });
    authServiceMock.login.mockReturnValue(throwError(() => errorResponse));

    component.form.setValue({ email: 'test@example.com', password: 'password1' });
    component.onSubmit();

    await fixture.whenStable();

    expect(component.errorMessage()).toBe('Identifiants invalides.');
  });

  it('errorMessage uses fallback when API error has no message', async () => {
    const errorResponse = new HttpErrorResponse({
      error: {},
      status: 500,
    });
    authServiceMock.login.mockReturnValue(throwError(() => errorResponse));

    component.form.setValue({ email: 'test@example.com', password: 'password1' });
    component.onSubmit();

    await fixture.whenStable();

    expect(component.errorMessage()).toBe('Une erreur est survenue. Veuillez réessayer.');
  });

  // ── hidePassword toggle ─────────────────────────────────────────────────────

  it('hidePassword signal is true by default', () => {
    expect(component.hidePassword()).toBe(true);
  });

  it('hidePassword can be toggled', () => {
    component.hidePassword.set(false);
    expect(component.hidePassword()).toBe(false);
  });
});
