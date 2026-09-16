import {
  Component,
  OnInit,
  computed,
  inject,
  signal,
  ChangeDetectionStrategy,
} from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import {
  AuthService,
  NotificationPreferences,
  SocialProvider,
  User,
} from '../../core/services/auth.service';
import { SocialSignInService } from '../../core/services/social-sign-in.service';
import {
  ImmoproButtonComponent,
  ImmoproInputComponent,
  ImmoproCardComponent,
  ImmoproPageHeaderComponent,
  ImmoproAvatarComponent,
  ImmoproBadgeComponent,
} from 'ui-lib';

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
    DatePipe,
  ],
  templateUrl: './profile.component.html',
  styleUrl: './profile.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileComponent implements OnInit {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);

  private social = inject(SocialSignInService);

  user = signal<User | null>(null);

  // Forms
  passwordForm: FormGroup;
  confirm2FAForm: FormGroup;
  passwordConfirmForm: FormGroup;

  /** Coordonnées modifiables. */
  identityForm: FormGroup;

  /** Changement d'adresse, en deux temps : demande puis confirmation. */
  emailForm: FormGroup;
  emailCodeForm: FormGroup;

  readonly identitySaving = signal(false);
  readonly emailSaving = signal(false);

  /**
   * Code rendu par le serveur hors production, pour dérouler le parcours en
   * local sans ouvrir de boîte de réception.
   */
  readonly emailDebugCode = signal<string | null>(null);

  /* --- Photo de profil ------------------------------------------------ */

  readonly avatarSaving = signal(false);

  /**
   * Change à chaque remplacement pour casser le cache du navigateur : l'URL de
   * la photo est stable, et sans cela l'ancienne image resterait affichée.
   */
  readonly avatarVersion = signal(Date.now());

  readonly avatarUrl = computed(() =>
    this.user()?.has_avatar ? this.authService.avatarUrl(this.avatarVersion()) : null,
  );

  /* --- Comptes externes ------------------------------------------------ */

  readonly availableProviders = signal<SocialProvider[]>([]);
  readonly providerBusy = signal<string | null>(null);

  readonly linkedProviders = computed(() => this.user()?.social_accounts ?? []);

  readonly unlinkedProviders = computed(() => {
    const linked = new Set(this.linkedProviders().map((account) => account.provider));

    return this.availableProviders().filter((provider) => !linked.has(provider.value));
  });

  /* --- Préférences de notification ------------------------------------ */

  readonly preferences = signal<NotificationPreferences | null>(null);
  readonly preferencesSaving = signal(false);

  // States as Signals
  loading = signal(false);
  successMessage = signal<string | null>(null);
  error = signal<string | null>(null);
  submitted = signal(false);

  // 2FA activation details as Signals
  twoFactorSetup = signal<{ secret: string; otpauth_url: string; qr_code_url: string } | null>(
    null,
  );
  recoveryCodes = signal<string[]>([]);

  // Modal controllers as Signals
  confirmPasswordAction = signal<'disable' | 'regenerate' | null>(null);
  isPasswordModalOpen = signal(false);
  modalError = signal<string | null>(null);
  modalSubmitted = signal(false);

  constructor() {
    this.passwordForm = this.fb.group(
      {
        current_password: ['', [Validators.required]],
        password: ['', [Validators.required, Validators.minLength(8)]],
        password_confirmation: ['', [Validators.required]],
      },
      {
        validators: this.passwordMatchValidator,
      },
    );

    this.confirm2FAForm = this.fb.group({
      code: ['', [Validators.required, Validators.minLength(6), Validators.maxLength(6)]],
    });

    this.passwordConfirmForm = this.fb.group({
      password: ['', [Validators.required]],
    });

    this.identityForm = this.fb.group({
      name: ['', [Validators.required, Validators.minLength(3)]],
      phone: [''],
      iban: [''],
      bic: [''],
      siret: [''],
      siren: [''],
    });

    this.emailForm = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: [''],
    });

    this.emailCodeForm = this.fb.group({
      code: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
    });
  }

  ngOnInit() {
    this.loadProfile();
    this.loadPreferences();

    void this.social.available().then((providers) => this.availableProviders.set(providers));
  }

  loadProfile() {
    // `getProfile` charge les comptes externes rattachés, ce que la réponse
    // d'authentification ne fait pas : elle est jouée à chaque démarrage, et
    // une requête de plus s'y paierait à chaque visite.
    this.authService.getProfile().subscribe({
      next: (res) => {
        this.user.set(res.data);
        this.identityForm.patchValue({
          name: res.data.name ?? '',
          phone: res.data.phone ?? '',
        });
      },
    });
  }

  loadPreferences() {
    this.authService.notificationPreferences().subscribe({
      next: (res) => this.preferences.set(res.data),
    });
  }

  /* ----------------------------------------------------------------------
   | Informations personnelles
   |----------------------------------------------------------------------*/

  onIdentitySubmit() {
    this.error.set(null);
    this.successMessage.set(null);

    if (this.identityForm.invalid) {
      return;
    }

    this.identitySaving.set(true);

    this.authService.updateProfile(this.identityForm.value).subscribe({
      next: (res) => {
        this.identitySaving.set(false);
        this.user.set(res.data);
        this.successMessage.set(res.message);
      },
      error: (error: unknown) => {
        this.identitySaving.set(false);
        this.error.set(this.readError(error, "Vos informations n'ont pas pu être enregistrées."));
      },
    });
  }

  /* ----------------------------------------------------------------------
   | Changement d'adresse
   |----------------------------------------------------------------------*/

  requestEmailChange() {
    this.error.set(null);
    this.successMessage.set(null);

    if (this.emailForm.invalid) {
      return;
    }

    this.emailSaving.set(true);

    this.authService.requestEmailChange(this.emailForm.value).subscribe({
      next: (res) => {
        this.emailSaving.set(false);
        this.emailDebugCode.set(res.otp?.debug_code ?? null);
        this.successMessage.set(res.message);
        this.loadProfile();
      },
      error: (error: unknown) => {
        this.emailSaving.set(false);
        this.error.set(this.readError(error, "Le changement d'adresse a échoué."));
      },
    });
  }

  confirmEmailChange() {
    this.error.set(null);
    this.successMessage.set(null);

    if (this.emailCodeForm.invalid) {
      return;
    }

    this.emailSaving.set(true);

    this.authService.confirmEmailChange(this.emailCodeForm.get('code')?.value).subscribe({
      next: (res) => {
        this.emailSaving.set(false);
        this.user.set(res.data);
        this.successMessage.set(res.message);
        this.emailForm.reset();
        this.emailCodeForm.reset();
        this.emailDebugCode.set(null);
        // Une adresse vérifiée débloque les courriels : l'écran doit le dire.
        this.loadPreferences();
      },
      error: (error: unknown) => {
        this.emailSaving.set(false);
        this.error.set(this.readError(error, 'Code incorrect ou expiré.'));
      },
    });
  }

  cancelEmailChange() {
    this.authService.cancelEmailChange().subscribe({
      next: (res) => {
        this.user.set(res.data);
        this.emailDebugCode.set(null);
        this.emailForm.reset();
        this.emailCodeForm.reset();
        this.successMessage.set(res.message);
      },
    });
  }

  /* ----------------------------------------------------------------------
   | Photo de profil
   |----------------------------------------------------------------------*/

  onAvatarSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
      return;
    }

    this.error.set(null);
    this.avatarSaving.set(true);

    this.authService.uploadAvatar(file).subscribe({
      next: (res) => {
        this.avatarSaving.set(false);
        this.user.set(res.data);
        this.avatarVersion.set(Date.now());
        this.successMessage.set(res.message);
        // Le champ est vidé : sans cela, redéposer le même fichier
        // n'émettrait aucun évènement et paraîtrait sans effet.
        input.value = '';
      },
      error: (error: unknown) => {
        this.avatarSaving.set(false);
        this.error.set(this.readError(error, "La photo n'a pas pu être enregistrée."));
        input.value = '';
      },
    });
  }

  removeAvatar() {
    this.avatarSaving.set(true);

    this.authService.deleteAvatar().subscribe({
      next: (res) => {
        this.avatarSaving.set(false);
        this.user.set(res.data);
        this.successMessage.set(res.message);
      },
      error: () => this.avatarSaving.set(false),
    });
  }

  /* ----------------------------------------------------------------------
   | Comptes Google et Apple
   |----------------------------------------------------------------------*/

  async linkProvider(provider: 'google' | 'apple') {
    this.error.set(null);
    this.successMessage.set(null);
    this.providerBusy.set(provider);

    try {
      const { id_token, nonce } = await this.social.requestIdToken(provider);

      this.authService.linkProvider(provider, { id_token, nonce }).subscribe({
        next: (res) => {
          this.providerBusy.set(null);
          this.user.set(res.data);
          this.successMessage.set(res.message);
        },
        error: (error: unknown) => {
          this.providerBusy.set(null);
          this.error.set(this.readError(error, 'Le rattachement a échoué.'));
        },
      });
    } catch (error) {
      this.providerBusy.set(null);

      const message = error instanceof Error ? error.message : 'La connexion a été interrompue.';

      if (!/popup_closed|user_cancel|AbortError/i.test(message)) {
        this.error.set(message);
      }
    }
  }

  unlinkProvider(provider: 'google' | 'apple') {
    this.error.set(null);
    this.successMessage.set(null);
    this.providerBusy.set(provider);

    this.authService.unlinkProvider(provider).subscribe({
      next: (res) => {
        this.providerBusy.set(null);
        this.user.set(res.data);
        this.successMessage.set(res.message);
      },
      error: (error: unknown) => {
        this.providerBusy.set(null);
        this.error.set(this.readError(error, 'Le détachement a échoué.'));
      },
    });
  }

  /* ----------------------------------------------------------------------
   | Préférences de notification
   |----------------------------------------------------------------------*/

  isChannelOn(topic: string, channel: string): boolean {
    return (this.preferences()?.preferences[topic] ?? []).includes(channel);
  }

  toggleChannel(topic: string, channel: string) {
    const current = this.preferences();

    if (!current) {
      return;
    }

    const active = new Set(current.preferences[topic] ?? []);

    active.has(channel) ? active.delete(channel) : active.add(channel);

    this.preferences.set({
      ...current,
      preferences: { ...current.preferences, [topic]: [...active] },
    });
  }

  savePreferences() {
    const current = this.preferences();

    if (!current) {
      return;
    }

    this.error.set(null);
    this.preferencesSaving.set(true);

    this.authService.saveNotificationPreferences(current.preferences).subscribe({
      next: (res) => {
        this.preferencesSaving.set(false);
        this.preferences.set({ ...current, preferences: res.data.preferences });
        this.successMessage.set(res.message);
      },
      error: (error: unknown) => {
        this.preferencesSaving.set(false);
        this.error.set(this.readError(error, "Vos préférences n'ont pas pu être enregistrées."));
      },
    });
  }

  /**
   * Message le plus précis dont on dispose : les erreurs de validation portent
   * le détail utile, le message global se contente d'annoncer qu'il y en a une.
   */
  private readError(error: unknown, fallback: string): string {
    if (error instanceof HttpErrorResponse) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return errors ? Object.values(errors).flat().join(' ') : (error.error?.message ?? fallback);
    }

    return fallback;
  }

  passwordMatchValidator(g: FormGroup) {
    return g.get('password')?.value === g.get('password_confirmation')?.value
      ? null
      : { mismatch: true };
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
      },
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
          qr_code_url: qrUrl,
        });
        this.confirm2FAForm.reset();
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(error.error?.message || "Impossible d'activer la double authentification");
      },
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
        this.successMessage.set(
          'Double authentification activée avec succès ! Notez précieusement vos codes de récupération.',
        );
        this.loadProfile();
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(
          error.error?.errors?.code?.[0] || error.error?.message || 'Code de confirmation invalide',
        );
      },
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
          this.modalError.set(
            error.error?.errors?.password?.[0] || error.error?.message || 'Mot de passe incorrect',
          );
        },
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
          this.modalError.set(
            error.error?.errors?.password?.[0] || error.error?.message || 'Mot de passe incorrect',
          );
        },
      });
    }
  }

  get currentPassword() {
    return this.passwordForm.get('current_password');
  }
  get newPassword() {
    return this.passwordForm.get('password');
  }
  get newPasswordConfirmation() {
    return this.passwordForm.get('password_confirmation');
  }
  get totpCode() {
    return this.confirm2FAForm.get('code');
  }
  get confirmPassword() {
    return this.passwordConfirmForm.get('password');
  }

  get identityName() {
    return this.identityForm.get('name');
  }
  get newEmail() {
    return this.emailForm.get('email');
  }
  get emailCode() {
    return this.emailCodeForm.get('code');
  }
}
