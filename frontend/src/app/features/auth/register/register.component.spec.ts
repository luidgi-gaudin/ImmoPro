import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router } from '@angular/router';
import { provideRouter } from '@angular/router';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { of, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { vi } from 'vitest';

import { RegisterComponent } from './register.component';
import { AuthService } from '../../../core/services/auth.service';
import { User } from '../../../core/models/user.model';

const mockUser: User = {
  id: 1,
  name: 'Alice Dupont',
  email: 'alice@test.com',
  created_at: '',
};

const validFormValue = {
  name: 'Alice Dupont',
  email: 'alice@test.com',
  password: 'password1',
  password_confirmation: 'password1',
};

function createAuthServiceMock() {
  return {
    register: vi.fn(),
  };
}

describe('RegisterComponent', () => {
  let fixture: ComponentFixture<RegisterComponent>;
  let component: RegisterComponent;
  let authServiceMock: ReturnType<typeof createAuthServiceMock>;
  let router: Router;

  beforeEach(async () => {
    authServiceMock = createAuthServiceMock();

    await TestBed.configureTestingModule({
      imports: [RegisterComponent],
      providers: [
        provideRouter([]),
        provideAnimationsAsync(),
        { provide: AuthService, useValue: authServiceMock },
      ],
    }).compileComponents();

    router = TestBed.inject(Router);
    vi.spyOn(router, 'navigate').mockResolvedValue(true);

    fixture = TestBed.createComponent(RegisterComponent);
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

  it('should render the registration form', () => {
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('form')).toBeTruthy();
    expect(el.querySelector('[formControlName="name"]')).toBeTruthy();
    expect(el.querySelector('[formControlName="email"]')).toBeTruthy();
    expect(el.querySelector('[formControlName="password"]')).toBeTruthy();
    expect(el.querySelector('[formControlName="password_confirmation"]')).toBeTruthy();
  });

  // ── Form validity ───────────────────────────────────────────────────────────

  it('form is invalid when empty', () => {
    expect(component.form.invalid).toBe(true);
  });

  it('form is valid with correct matching values', () => {
    component.form.setValue(validFormValue);
    expect(component.form.valid).toBe(true);
  });

  // ── passwordMatchValidator ──────────────────────────────────────────────────

  it('passwordMatchValidator returns passwordMismatch error when passwords differ', () => {
    component.form.setValue({
      ...validFormValue,
      password: 'password1',
      password_confirmation: 'different_password',
    });
    expect(component.form.hasError('passwordMismatch')).toBe(true);
  });

  it('passwordMatchValidator returns null when passwords match', () => {
    component.form.setValue(validFormValue);
    expect(component.form.hasError('passwordMismatch')).toBe(false);
    expect(component.form.errors).toBeNull();
  });

  // ── hasFormError ────────────────────────────────────────────────────────────

  it('hasFormError("passwordMismatch") returns false when passwords match', () => {
    component.form.setValue(validFormValue);
    component.form.get('password_confirmation')!.markAsTouched();
    expect(component.hasFormError('passwordMismatch')).toBe(false);
  });

  it('hasFormError("passwordMismatch") returns true when passwords mismatch and field touched', () => {
    component.form.setValue({
      ...validFormValue,
      password_confirmation: 'wrong_password',
    });
    component.form.get('password_confirmation')!.markAsTouched();
    expect(component.hasFormError('passwordMismatch')).toBe(true);
  });

  it('hasFormError("passwordMismatch") returns false when field not touched', () => {
    component.form.setValue({
      ...validFormValue,
      password_confirmation: 'wrong_password',
    });
    expect(component.hasFormError('passwordMismatch')).toBe(false);
  });

  // ── hasError ────────────────────────────────────────────────────────────────

  it('hasError("name", "required") returns false before touch', () => {
    expect(component.hasError('name', 'required')).toBe(false);
  });

  it('hasError("name", "required") returns true after touching empty name', () => {
    component.form.get('name')!.markAsTouched();
    expect(component.hasError('name', 'required')).toBe(true);
  });

  it('hasError("email", "email") returns true for invalid email after touch', () => {
    component.form.get('email')!.setValue('not-valid');
    component.form.get('email')!.markAsTouched();
    expect(component.hasError('email', 'email')).toBe(true);
  });

  it('hasError("password", "minlength") returns true for short password after touch', () => {
    component.form.get('password')!.setValue('short');
    component.form.get('password')!.markAsTouched();
    expect(component.hasError('password', 'minlength')).toBe(true);
  });

  // ── getServerError ──────────────────────────────────────────────────────────

  it('getServerError returns null when no server errors', () => {
    expect(component.getServerError('email')).toBeNull();
  });

  it('getServerError returns first server-side error for the field', () => {
    component.fieldErrors.set({
      email: ['Cet e-mail est déjà utilisé.', 'Autre erreur.'],
    });
    expect(component.getServerError('email')).toBe('Cet e-mail est déjà utilisé.');
  });

  it('getServerError returns null for a field with no server error', () => {
    component.fieldErrors.set({
      email: ['Cet e-mail est déjà utilisé.'],
    });
    expect(component.getServerError('name')).toBeNull();
  });

  // ── onSubmit with invalid form ──────────────────────────────────────────────

  it('onSubmit() with invalid form marks all as touched, does NOT call register', () => {
    component.onSubmit();
    expect(component.form.touched).toBe(true);
    expect(authServiceMock.register).not.toHaveBeenCalled();
  });

  // ── onSubmit with valid form ────────────────────────────────────────────────

  it('onSubmit() with valid form calls authService.register with correct values', async () => {
    authServiceMock.register.mockReturnValue(of(mockUser));

    component.form.setValue(validFormValue);
    component.onSubmit();

    await fixture.whenStable();

    expect(authServiceMock.register).toHaveBeenCalledWith(validFormValue);
  });

  it('navigates to /dashboard on successful register', async () => {
    authServiceMock.register.mockReturnValue(of(mockUser));

    component.form.setValue(validFormValue);
    component.onSubmit();

    await fixture.whenStable();

    expect(router.navigate).toHaveBeenCalledWith(['/dashboard']);
  });

  it('sets isLoading to true during request', () => {
    authServiceMock.register.mockReturnValue(of(mockUser));

    component.form.setValue(validFormValue);
    component.onSubmit();

    expect(component.isLoading()).toBe(true);
  });

  it('sets isLoading to false on API error', async () => {
    const error = new HttpErrorResponse({ error: { message: 'Erreur.' }, status: 422 });
    authServiceMock.register.mockReturnValue(throwError(() => error));

    component.form.setValue(validFormValue);
    component.onSubmit();

    await fixture.whenStable();

    expect(component.isLoading()).toBe(false);
  });

  it('errorMessage is set on API error', async () => {
    const error = new HttpErrorResponse({
      error: { message: 'Cet e-mail est déjà utilisé.' },
      status: 422,
    });
    authServiceMock.register.mockReturnValue(throwError(() => error));

    component.form.setValue(validFormValue);
    component.onSubmit();

    await fixture.whenStable();

    expect(component.errorMessage()).toBe('Cet e-mail est déjà utilisé.');
  });

  it('fieldErrors are set from API error.errors', async () => {
    const error = new HttpErrorResponse({
      error: {
        message: 'Les données sont invalides.',
        errors: { email: ['Cet e-mail est déjà utilisé.'] },
      },
      status: 422,
    });
    authServiceMock.register.mockReturnValue(throwError(() => error));

    component.form.setValue(validFormValue);
    component.onSubmit();

    await fixture.whenStable();

    expect(component.getServerError('email')).toBe('Cet e-mail est déjà utilisé.');
  });

  it('errorMessage uses fallback when API error has no message', async () => {
    const error = new HttpErrorResponse({ error: {}, status: 500 });
    authServiceMock.register.mockReturnValue(throwError(() => error));

    component.form.setValue(validFormValue);
    component.onSubmit();

    await fixture.whenStable();

    expect(component.errorMessage()).toBe('Une erreur est survenue. Veuillez réessayer.');
  });

  // ── Password visibility toggles ─────────────────────────────────────────────

  it('hidePassword signal is true by default', () => {
    expect(component.hidePassword()).toBe(true);
  });

  it('hideConfirmation signal is true by default', () => {
    expect(component.hideConfirmation()).toBe(true);
  });

  it('hidePassword can be toggled', () => {
    component.hidePassword.set(false);
    expect(component.hidePassword()).toBe(false);
  });

  it('hideConfirmation can be toggled', () => {
    component.hideConfirmation.set(false);
    expect(component.hideConfirmation()).toBe(false);
  });
});
