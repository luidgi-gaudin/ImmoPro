import { Component, inject, OnInit, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { ImmoproAuthCardComponent, ImmoproInputComponent, ImmoproButtonComponent } from 'ui-lib';

@Component({
  selector: 'app-reset-password',
  standalone: true,
  imports: [
    ReactiveFormsModule, 
    RouterLink, 
    ImmoproAuthCardComponent, 
    ImmoproInputComponent, 
    ImmoproButtonComponent
  ],
  templateUrl: './reset-password.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResetPasswordComponent implements OnInit {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);

  form: FormGroup;
  loading = signal(false);
  successMessage = signal<string | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);
  token = signal('');

  constructor() {
    this.form = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', [Validators.required]],
    }, {
      validators: this.passwordMatchValidator
    });
  }

  ngOnInit() {
    const tokenVal = this.route.snapshot.queryParamMap.get('token') || '';
    this.token.set(tokenVal);
    
    const emailParam = this.route.snapshot.queryParamMap.get('email') || '';
    if (emailParam) {
      this.form.patchValue({ email: emailParam });
    }

    if (!tokenVal) {
      this.error.set('Jeton de réinitialisation manquant. Veuillez utiliser le lien reçu par e-mail.');
    }
  }

  passwordMatchValidator(g: FormGroup) {
    return g.get('password')?.value === g.get('password_confirmation')?.value
      ? null : { mismatch: true };
  }

  onSubmit() {
    this.submitted.set(true);
    this.error.set(null);
    this.successMessage.set(null);

    if (this.form.invalid || !this.token()) {
      return;
    }

    this.loading.set(true);

    const payload = {
      token: this.token(),
      email: this.form.get('email')?.value,
      password: this.form.get('password')?.value,
      password_confirmation: this.form.get('password_confirmation')?.value,
    };

    this.authService.resetPassword(payload).subscribe({
      next: (res) => {
        this.loading.set(false);
        this.successMessage.set(res.message || 'Votre mot de passe a été réinitialisé avec succès.');
        this.submitted.set(false);
        setTimeout(() => {
          this.router.navigate(['/login']);
        }, 3000);
      },
      error: (error) => {
        this.loading.set(false);
        if (error.error?.errors) {
          this.error.set(Object.values(error.error.errors).flat().join(', '));
        } else {
          this.error.set(error.error?.message || 'Erreur lors de la réinitialisation de votre mot de passe');
        }
      },
    });
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
