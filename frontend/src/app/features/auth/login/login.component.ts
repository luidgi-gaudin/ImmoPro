import { Component, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { ImmoproAuthCardComponent, ImmoproInputComponent, ImmoproButtonComponent } from 'ui-lib';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    ReactiveFormsModule, 
    RouterLink, 
    ImmoproAuthCardComponent, 
    ImmoproInputComponent, 
    ImmoproButtonComponent
  ],
  templateUrl: './login.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginComponent {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);
  private router = inject(Router);

  form: FormGroup;
  twoFactorForm: FormGroup;
  
  loading = signal(false);
  error = signal<string | null>(null);
  submitted = signal(false);

  twoFactorRequired = signal(false);
  challengeToken = signal('');
  useRecoveryCode = signal(false);

  constructor() {
    this.form = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required]],
    });

    this.twoFactorForm = this.fb.group({
      code: [''],
      recovery_code: ['']
    });
  }

  onSubmit() {
    this.submitted.set(true);
    this.error.set(null);

    if (this.form.invalid) {
      return;
    }

    this.loading.set(true);

    this.authService.login(this.form.value).subscribe({
      next: (res) => {
        this.loading.set(false);
        if (res.two_factor_required) {
          this.twoFactorRequired.set(true);
          this.challengeToken.set(res.challenge_token || '');
          this.submitted.set(false);
          this.toggle2FAFields();
        } else {
          this.router.navigate(['/dashboard']);
        }
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(error.error?.message || 'Identifiants ou connexion invalides');
      },
    });
  }

  onTwoFactorSubmit() {
    this.submitted.set(true);
    this.error.set(null);

    const codeVal = this.twoFactorForm.get('code')?.value;
    const recVal = this.twoFactorForm.get('recovery_code')?.value;

    if (this.useRecoveryCode() && !recVal) {
      this.error.set('Veuillez saisir votre code de récupération');
      return;
    }
    if (!this.useRecoveryCode() && !codeVal) {
      this.error.set('Veuillez saisir votre code à 6 chiffres');
      return;
    }

    this.loading.set(true);

    const payload: any = {
      challenge_token: this.challengeToken()
    };

    if (this.useRecoveryCode()) {
      payload.recovery_code = recVal;
    } else {
      payload.code = codeVal;
    }

    this.authService.verify2FAChallenge(payload).subscribe({
      next: () => {
        this.loading.set(false);
        this.router.navigate(['/dashboard']);
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(error.error?.errors?.code?.[0] || error.error?.message || 'Code double authentification incorrect');
      }
    });
  }

  toggleRecoveryMode() {
    this.useRecoveryCode.set(!this.useRecoveryCode());
    this.error.set(null);
    this.submitted.set(false);
    this.twoFactorForm.reset();
    this.toggle2FAFields();
  }

  toggle2FAFields() {
    if (this.useRecoveryCode()) {
      this.twoFactorForm.get('recovery_code')?.setValidators([Validators.required]);
      this.twoFactorForm.get('code')?.clearValidators();
    } else {
      this.twoFactorForm.get('code')?.setValidators([Validators.required, Validators.minLength(6), Validators.maxLength(6)]);
      this.twoFactorForm.get('recovery_code')?.clearValidators();
    }
    this.twoFactorForm.get('code')?.updateValueAndValidity();
    this.twoFactorForm.get('recovery_code')?.updateValueAndValidity();
  }

  resetLoginFlow() {
    this.twoFactorRequired.set(false);
    this.challengeToken.set('');
    this.useRecoveryCode.set(false);
    this.error.set(null);
    this.submitted.set(false);
    this.twoFactorForm.reset();
  }

  get email() {
    return this.form.get('email');
  }

  get password() {
    return this.form.get('password');
  }

  get code() {
    return this.twoFactorForm.get('code');
  }

  get recoveryCode() {
    return this.twoFactorForm.get('recovery_code');
  }
}
