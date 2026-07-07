import { Component, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { ImmoproAuthCardComponent, ImmoproInputComponent, ImmoproButtonComponent } from 'ui-lib';

@Component({
  selector: 'app-forgot-password',
  standalone: true,
  imports: [
    ReactiveFormsModule, 
    RouterLink, 
    ImmoproAuthCardComponent, 
    ImmoproInputComponent, 
    ImmoproButtonComponent
  ],
  templateUrl: './forgot-password.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForgotPasswordComponent {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);

  form: FormGroup;
  loading = signal(false);
  successMessage = signal<string | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);

  constructor() {
    this.form = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
    });
  }

  onSubmit() {
    this.submitted.set(true);
    this.error.set(null);
    this.successMessage.set(null);

    if (this.form.invalid) {
      return;
    }

    this.loading.set(true);

    this.authService.forgotPassword(this.form.get('email')?.value).subscribe({
      next: (res) => {
        this.loading.set(false);
        this.successMessage.set(res.message || 'Un email de réinitialisation a été envoyé.');
        this.form.reset();
        this.submitted.set(false);
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(error.error?.message || 'Une erreur est survenue lors de l\'envoi de l\'email');
      },
    });
  }

  get email() {
    return this.form.get('email');
  }
}
