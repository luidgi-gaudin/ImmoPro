import { Component, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { ImmoproAuthCardComponent, ImmoproInputComponent, ImmoproButtonComponent } from 'ui-lib';

export function passwordMatchValidator(form: FormGroup) {
  const password = form.get('password');
  const passwordConfirmation = form.get('password_confirmation');

  if (password && passwordConfirmation && password.value !== passwordConfirmation.value) {
    passwordConfirmation.setErrors({ passwordMismatch: true });
    return { passwordMismatch: true };
  }

  return null;
}

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [
    ReactiveFormsModule, 
    RouterLink, 
    ImmoproAuthCardComponent, 
    ImmoproInputComponent, 
    ImmoproButtonComponent
  ],
  templateUrl: './register.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RegisterComponent {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);
  private router = inject(Router);

  form: FormGroup;
  loading = signal(false);
  error = signal<string | null>(null);
  submitted = signal(false);

  constructor() {
    this.form = this.fb.group(
      {
        name: ['', [Validators.required, Validators.minLength(3)]],
        email: ['', [Validators.required, Validators.email]],
        password: ['', [Validators.required, Validators.minLength(8)]],
        password_confirmation: ['', [Validators.required]],
      },
      { validators: passwordMatchValidator }
    );
  }

  onSubmit() {
    this.submitted.set(true);
    this.error.set(null);

    if (this.form.invalid) {
      return;
    }

    this.loading.set(true);

    this.authService.register(this.form.value).subscribe({
      next: () => {
        this.router.navigate(['/dashboard']);
      },
      error: (error) => {
        this.loading.set(false);
        if (error.error?.errors) {
          this.error.set(Object.values(error.error.errors).flat().join(', '));
        } else {
          this.error.set(error.error?.message || 'Erreur lors de l\'inscription');
        }
      },
    });
  }

  get name() {
    return this.form.get('name');
  }

  get email() {
    return this.form.get('email');
  }

  get password() {
    return this.form.get('password');
  }

  get passwordConfirmation() {
    return this.form.get('password_confirmation');
  }
}
