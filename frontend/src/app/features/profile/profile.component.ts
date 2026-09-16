import { Component, OnInit, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { DatePipe } from '@angular/common';
import { AuthService, User } from '../../core/services/auth.service';
import { ImmoproButtonComponent, ImmoproInputComponent, ImmoproCardComponent, ImmoproPageHeaderComponent, ImmoproAvatarComponent, ImmoproBadgeComponent } from 'ui-lib';

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    ImmoproButtonComponent,
    ImmoproInputComponent,
    ImmoproCardComponent,
    ImmoproPageHeaderComponent,
    ImmoproAvatarComponent,
    ImmoproBadgeComponent,
    DatePipe
  ],
  templateUrl: './profile.component.html',
  styleUrl: './profile.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileComponent implements OnInit {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);

  user = signal<User | null>(null);
  
  // Forms
  passwordForm: FormGroup;
  confirm2FAForm: FormGroup;
  passwordConfirmForm: FormGroup;

  // States as Signals
  loading = signal(false);
  successMessage = signal<string | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);

  // 2FA activation details as Signals
  twoFactorSetup = signal<{ secret: string; otpauth_url: string; qr_code_url: string } | null>(null);
  recoveryCodes = signal<string[]>([]);
  
  // Modal controllers as Signals
  confirmPasswordAction = signal<'disable' | 'regenerate' | null>(null);
  isPasswordModalOpen = signal(false);
  modalError = signal<string | null>(null);
  modalSubmitted = signal(false);

  constructor() {
    this.passwordForm = this.fb.group({
      current_password: ['', [Validators.required]],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', [Validators.required]]
    }, {
      validators: this.passwordMatchValidator
    });

    this.confirm2FAForm = this.fb.group({
      code: ['', [Validators.required, Validators.minLength(6), Validators.maxLength(6)]]
    });

    this.passwordConfirmForm = this.fb.group({
      password: ['', [Validators.required]]
    });
  }

  ngOnInit() {
    this.loadProfile();
  }

  loadProfile() {
    this.authService.getUserProfile().subscribe({
      next: (res) => {
        if (res.data) {
          this.user.set(res.data);
        }
      }
    });
  }

  passwordMatchValidator(g: FormGroup) {
    return g.get('password')?.value === g.get('password_confirmation')?.value
      ? null : { mismatch: true };
  }

  onPasswordSubmit() {
    this.submitted.set(true);
    this.error.set(null);
    this.successMessage.set(null);

    if (this.passwordForm.invalid) {
      return;
    }

    this.loading.set(true);

    this.authService.updatePassword(this.passwordForm.value).subscribe({
      next: (res) => {
        this.loading.set(false);
        this.successMessage.set(res.message || 'Votre mot de passe a été mis à jour.');
        this.passwordForm.reset();
        this.submitted.set(false);
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(error.error?.message || 'Erreur lors de la mise à jour du mot de passe');
      }
    });
  }

  // 2FA Setup
  start2FASetup() {
    this.loading.set(true);
    this.error.set(null);
    this.successMessage.set(null);

    this.authService.enable2FA().subscribe({
      next: (res) => {
        this.loading.set(false);
        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(res.otpauth_url)}`;
        this.twoFactorSetup.set({
          secret: res.secret,
          otpauth_url: res.otpauth_url,
          qr_code_url: qrUrl
        });
        this.confirm2FAForm.reset();
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(error.error?.message || 'Impossible d\'activer la double authentification');
      }
    });
  }

  cancel2FASetup() {
    this.twoFactorSetup.set(null);
    this.error.set(null);
  }

  confirm2FASetup() {
    if (this.confirm2FAForm.invalid) {
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    const code = this.confirm2FAForm.get('code')?.value;

    this.authService.confirm2FA(code).subscribe({
      next: (res) => {
        this.loading.set(false);
        this.twoFactorSetup.set(null);
        this.recoveryCodes.set(res.recovery_codes || []);
        this.successMessage.set('Double authentification activée avec succès ! Notez précieusement vos codes de récupération.');
        this.loadProfile();
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(error.error?.errors?.code?.[0] || error.error?.message || 'Code de confirmation invalide');
      }
    });
  }

  // Disable / Regenerate Recovery Codes Actions
  openPasswordModal(action: 'disable' | 'regenerate') {
    this.confirmPasswordAction.set(action);
    this.isPasswordModalOpen.set(true);
    this.modalError.set(null);
    this.modalSubmitted.set(false);
    this.passwordConfirmForm.reset();
  }

  closePasswordModal() {
    this.isPasswordModalOpen.set(false);
    this.confirmPasswordAction.set(null);
    this.passwordConfirmForm.reset();
  }

  submitPasswordConfirmation() {
    this.modalSubmitted.set(true);
    this.modalError.set(null);

    if (this.passwordConfirmForm.invalid) {
      return;
    }

    const password = this.passwordConfirmForm.get('password')?.value;
    this.loading.set(true);

    const action = this.confirmPasswordAction();

    if (action === 'disable') {
      this.authService.disable2FA(password).subscribe({
        next: (res) => {
          this.loading.set(false);
          this.closePasswordModal();
          this.successMessage.set(res.message || 'Double authentification désactivée.');
          this.recoveryCodes.set([]);
          this.loadProfile();
        },
        error: (error) => {
          this.loading.set(false);
          this.modalError.set(error.error?.errors?.password?.[0] || error.error?.message || 'Mot de passe incorrect');
        }
      });
    } else if (action === 'regenerate') {
      this.authService.regenerateRecoveryCodes(password).subscribe({
        next: (res) => {
          this.loading.set(false);
          this.closePasswordModal();
          this.recoveryCodes.set(res.recovery_codes || []);
          this.successMessage.set('Nouveaux codes de récupération générés.');
        },
        error: (error) => {
          this.loading.set(false);
          this.modalError.set(error.error?.errors?.password?.[0] || error.error?.message || 'Mot de passe incorrect');
        }
      });
    }
  }

  get currentPassword() { return this.passwordForm.get('current_password'); }
  get newPassword() { return this.passwordForm.get('password'); }
  get newPasswordConfirmation() { return this.passwordForm.get('password_confirmation'); }
  get totpCode() { return this.confirm2FAForm.get('code'); }
  get confirmPassword() { return this.passwordConfirmForm.get('password'); }
}
