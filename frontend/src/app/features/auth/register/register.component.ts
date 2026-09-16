import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AuthService, UserRole } from '../../../core/services/auth.service';
import {
  SocialCredential,
  SocialSignInComponent,
} from '../../../shared/components/social-sign-in/social-sign-in.component';
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

/** Étape courante du parcours d'inscription. */
type Step = 'form' | 'code';

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    SocialSignInComponent,
    ImmoproAuthCardComponent,
    ImmoproInputComponent,
    ImmoproButtonComponent,
  ],
  templateUrl: './register.component.html',
  styleUrls: ['./register.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RegisterComponent {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);

  readonly form: FormGroup;
  readonly codeForm: FormGroup;

  readonly step = signal<Step>('form');
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly submitted = signal(false);

  /** Adresse à laquelle le code a été envoyé, affichée sur l'écran du code. */
  readonly pendingEmail = signal('');

  /** Secondes restant avant qu'un nouveau code puisse être demandé. */
  readonly resendIn = signal(0);

  readonly canResend = computed(() => this.resendIn() === 0 && !this.loading());

  private resendTimer: ReturnType<typeof setInterval> | null = null;

  /**
   * Profils proposés.
   *
   * Écrits ici plutôt que chargés depuis l'API : cet écran est le tout premier,
   * et attendre un appel réseau pour afficher deux libellés retarderait le
   * rendu sans rien apporter. Le serveur revalide de toute façon la valeur.
   */
  readonly roles: { value: UserRole; label: string; hint: string }[] = [
    {
      value: 'proprietaire',
      label: 'Propriétaire bailleur',
      hint: 'Je gère un ou plusieurs logements en location.',
    },
    {
      value: 'locataire',
      label: 'Locataire',
      hint: 'Je loue un logement et je consulte mon dossier.',
    },
  ];

  constructor() {
    const params = this.route.snapshot.queryParamMap;

    /*
     * L'invitation envoyée par le bailleur porte le profil et l'adresse.
     * Les préremplir évite au locataire de se tromper de case, et surtout de
     * saisir une autre adresse que celle qui rattachera son dossier.
     */
    const invitedRole = params.get('role');
    const invitedEmail = params.get('email');

    this.form = this.fb.group(
      {
        role: [
          invitedRole === 'locataire' || invitedRole === 'proprietaire'
            ? invitedRole
            : 'proprietaire',
          [Validators.required],
        ],
        name: ['', [Validators.required, Validators.minLength(3)]],
        email: [invitedEmail ?? '', [Validators.required, Validators.email]],
        password: ['', [Validators.required, Validators.minLength(8)]],
        password_confirmation: ['', [Validators.required]],
      },
      { validators: passwordMatchValidator },
    );

    this.codeForm = this.fb.group({
      code: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
    });
  }

  chooseRole(role: UserRole): void {
    this.form.get('role')?.setValue(role);
  }

  get selectedRole(): UserRole {
    return this.form.get('role')?.value as UserRole;
  }

  /* ----------------------------------------------------------------------
   | Inscription par mot de passe
   |----------------------------------------------------------------------*/

  onSubmit(): void {
    this.submitted.set(true);
    this.error.set(null);

    if (this.form.invalid) {
      return;
    }

    this.loading.set(true);

    this.authService.register(this.form.value).subscribe({
      next: (response) => {
        this.loading.set(false);
        this.pendingEmail.set(this.form.get('email')?.value);
        this.startResendCountdown(response.otp?.resend_after_seconds ?? 60);
        this.submitted.set(false);
        this.step.set('code');
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.error.set(this.readError(error, "Erreur lors de l'inscription"));
      },
    });
  }

  /* ----------------------------------------------------------------------
   | Vérification du code
   |----------------------------------------------------------------------*/

  onVerify(): void {
    this.submitted.set(true);
    this.error.set(null);

    if (this.codeForm.invalid) {
      return;
    }

    this.loading.set(true);

    this.authService.verifyOtp(this.pendingEmail(), this.codeForm.get('code')?.value).subscribe({
      next: () => {
        this.loading.set(false);
        this.stopResendCountdown();
        // Le serveur dit où atterrir : le bailleur sur son tableau de bord,
        // le locataire sur son espace.
        this.router.navigateByUrl(this.authService.homePath());
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.error.set(this.readError(error, 'Code incorrect ou expiré'));
      },
    });
  }

  resend(): void {
    if (!this.canResend()) {
      return;
    }

    this.error.set(null);
    this.notice.set(null);
    this.loading.set(true);

    this.authService.sendOtp(this.pendingEmail()).subscribe({
      next: (response) => {
        this.loading.set(false);
        this.notice.set('Un nouveau code vient de vous être envoyé.');
        this.startResendCountdown(response.otp?.resend_after_seconds ?? 60);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.error.set(this.readError(error, "Impossible d'envoyer un nouveau code"));
      },
    });
  }

  /** Retour au formulaire, pour corriger une adresse mal saisie. */
  backToForm(): void {
    this.stopResendCountdown();
    this.step.set('form');
    this.error.set(null);
    this.notice.set(null);
    this.submitted.set(false);
    this.codeForm.reset();
  }

  /* ----------------------------------------------------------------------
   | Inscription par Google ou Apple
   |----------------------------------------------------------------------*/

  onSocialCredential(credential: SocialCredential): void {
    this.error.set(null);
    this.loading.set(true);

    this.authService
      .signInWithProvider(credential.provider, {
        id_token: credential.id_token,
        nonce: credential.nonce,
        // Lu par le serveur à la création seulement : une connexion ne change
        // pas le profil déjà choisi.
        role: this.selectedRole,
      })
      .subscribe({
        next: () => {
          this.loading.set(false);
          this.router.navigateByUrl(this.authService.homePath());
        },
        error: (error: unknown) => {
          this.loading.set(false);
          this.error.set(this.readError(error, 'La connexion externe a échoué'));
        },
      });
  }

  onSocialFailure(message: string): void {
    this.error.set(message);
  }

  /* ----------------------------------------------------------------------
   | Assises
   |----------------------------------------------------------------------*/

  private startResendCountdown(seconds: number): void {
    this.stopResendCountdown();
    this.resendIn.set(seconds);

    this.resendTimer = setInterval(() => {
      const remaining = this.resendIn() - 1;

      this.resendIn.set(Math.max(0, remaining));

      if (remaining <= 0) {
        this.stopResendCountdown();
      }
    }, 1000);
  }

  private stopResendCountdown(): void {
    if (this.resendTimer !== null) {
      clearInterval(this.resendTimer);
      this.resendTimer = null;
    }
  }

  /**
   * Message le plus précis dont on dispose.
   *
   * Les erreurs de validation portent le détail utile ; le message global se
   * contente souvent d'annoncer qu'il y a une erreur quelque part.
   */
  private readError(error: unknown, fallback: string): string {
    if (error instanceof HttpErrorResponse) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      if (errors) {
        return Object.values(errors).flat().join(' ');
      }

      return error.error?.message ?? fallback;
    }

    return fallback;
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

  get code() {
    return this.codeForm.get('code');
  }
}
