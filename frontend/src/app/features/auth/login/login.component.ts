import { Component, inject, signal, ChangeDetectionStrategy } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import {
  SocialCredential,
  SocialSignInComponent,
} from '../../../shared/components/social-sign-in/social-sign-in.component';
import { ImmoproAuthCardComponent, ImmoproInputComponent, ImmoproButtonComponent } from 'ui-lib';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    SocialSignInComponent,
    ImmoproAuthCardComponent,
    ImmoproInputComponent,
    ImmoproButtonComponent,
  ],
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginComponent {
  private fb = inject(FormBuilder);
  private authService = inject(AuthService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);

  /**
   * Page quittée au moment de la déconnexion.
   *
   * Renvoyer systématiquement au tableau de bord après une reconnexion fait
   * perdre le fil : celui dont la session a expiré sur la fiche d'un bail veut
   * y revenir, pas repartir de l'accueil.
   */
  private readonly returnUrl = signal<string | null>(null);

  /** Vrai quand on arrive ici parce que la session a expiré, pas par choix. */
  readonly sessionExpired = signal(false);

  form: FormGroup;
  twoFactorForm: FormGroup;

  loading = signal(false);
  error = signal<string | null>(null);
  submitted = signal(false);

  twoFactorRequired = signal(false);
  challengeToken = signal('');
  useRecoveryCode = signal(false);

  /* --- Vérification de l'adresse ------------------------------------- */

  /**
   * Le compte existe et le mot de passe est bon, mais l'adresse n'a jamais été
   * vérifiée. L'écran bascule alors sur la saisie du code plutôt que d'afficher
   * « accès refusé » : le compte n'est pas en faute, il est inachevé.
   */
  readonly verificationRequired = signal(false);
  readonly pendingEmail = signal('');
  readonly debugCode = signal<string | null>(null);
  readonly resendIn = signal(0);

  readonly codeForm: FormGroup;

  private resendTimer: ReturnType<typeof setInterval> | null = null;

  constructor() {
    this.form = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required]],
    });

    this.twoFactorForm = this.fb.group({
      code: [''],
      recovery_code: [''],
    });

    this.codeForm = this.fb.group({
      code: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
    });

    const params = this.route.snapshot.queryParamMap;

    this.sessionExpired.set(params.get('expired') === '1');

    // On n'accepte qu'un chemin interne : une URL absolue placée là par un tiers
    // transformerait l'écran de connexion en tremplin vers un site externe.
    const requested = params.get('returnUrl');
    this.returnUrl.set(
      requested && requested.startsWith('/') && !requested.startsWith('//') ? requested : null,
    );
  }

  /**
   * Destination après connexion : la page quittée, sinon l'écran d'arrivée du
   * profil — le tableau de bord pour un bailleur, son espace pour un locataire.
   */
  private afterLogin(): void {
    this.router.navigateByUrl(this.returnUrl() ?? this.authService.homePath());
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
          this.afterLogin();
        }
      },
      error: (error: unknown) => {
        this.loading.set(false);

        if (error instanceof HttpErrorResponse && error.error?.email_verification_required) {
          this.startVerification(error.error.email ?? this.form.get('email')?.value);

          return;
        }

        this.error.set(
          (error as HttpErrorResponse)?.error?.message ?? 'Identifiants ou connexion invalides',
        );
      },
    });
  }

  /* ----------------------------------------------------------------------
   | Adresse non vérifiée
   |----------------------------------------------------------------------*/

  /**
   * Bascule sur l'écran du code et en demande un.
   *
   * Le code est redemandé automatiquement : celui reçu à l'inscription a pu
   * expirer depuis longtemps, et obliger à cliquer « renvoyer » avant de
   * pouvoir faire quoi que ce soit n'apporte rien.
   */
  private startVerification(email: string): void {
    this.verificationRequired.set(true);
    this.pendingEmail.set(email);
    this.submitted.set(false);
    this.error.set(null);

    this.requestCode();
  }

  requestCode(): void {
    if (this.resendIn() > 0 || this.loading()) {
      return;
    }

    this.loading.set(true);

    this.authService.sendOtp(this.pendingEmail()).subscribe({
      next: (response) => {
        this.loading.set(false);
        this.debugCode.set(response.otp?.debug_code ?? null);
        this.startResendCountdown(response.otp?.resend_after_seconds ?? 60);
      },
      error: (error: unknown) => {
        this.loading.set(false);

        // Un renvoi trop rapproché n'est pas un échec : le code précédent est
        // toujours valable, et le dire évite de le faire chercher ailleurs.
        if (error instanceof HttpErrorResponse && error.status === 429) {
          this.startResendCountdown(60);

          return;
        }

        this.error.set("Impossible d'envoyer un nouveau code pour le moment.");
      },
    });
  }

  onVerifyEmail(): void {
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
        this.afterLogin();
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.error.set(
          (error as HttpErrorResponse)?.error?.errors?.code?.[0] ?? 'Code incorrect ou expiré',
        );
      },
    });
  }

  cancelVerification(): void {
    this.stopResendCountdown();
    this.verificationRequired.set(false);
    this.codeForm.reset();
    this.error.set(null);
    this.submitted.set(false);
  }

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

  /* ----------------------------------------------------------------------
   | Google et Apple
   |----------------------------------------------------------------------*/

  onSocialCredential(credential: SocialCredential): void {
    this.error.set(null);
    this.loading.set(true);

    this.authService
      .signInWithProvider(credential.provider, {
        id_token: credential.id_token,
        nonce: credential.nonce,
      })
      .subscribe({
        next: () => {
          this.loading.set(false);
          this.afterLogin();
        },
        error: (error: unknown) => {
          this.loading.set(false);

          /*
           * Le serveur refuse de créer un compte sans profil. Ce n'est pas une
           * erreur à afficher telle quelle : c'est une première connexion, et
           * l'inscription est l'endroit où l'on choisit son profil.
           */
          if (error instanceof HttpErrorResponse && error.error?.errors?.role) {
            this.router.navigate(['/register']);

            return;
          }

          this.error.set(
            (error as HttpErrorResponse)?.error?.message ?? 'La connexion externe a échoué.',
          );
        },
      });
  }

  onSocialFailure(message: string): void {
    this.error.set(message);
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
      challenge_token: this.challengeToken(),
    };

    if (this.useRecoveryCode()) {
      payload.recovery_code = recVal;
    } else {
      payload.code = codeVal;
    }

    this.authService.verify2FAChallenge(payload).subscribe({
      next: () => {
        this.loading.set(false);
        this.afterLogin();
      },
      error: (error) => {
        this.loading.set(false);
        this.error.set(
          error.error?.errors?.code?.[0] ||
            error.error?.message ||
            'Code double authentification incorrect',
        );
      },
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
      this.twoFactorForm
        .get('code')
        ?.setValidators([Validators.required, Validators.minLength(6), Validators.maxLength(6)]);
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

  get verificationCode() {
    return this.codeForm.get('code');
  }
}
