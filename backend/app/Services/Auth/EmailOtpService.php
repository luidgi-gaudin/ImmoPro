<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Codes à usage unique envoyés par courriel.
 *
 * Trois protections, et aucune n'est superflue :
 *
 *   - le code est stocké **haché**. La table `users` n'est pas couverte par la
 *     Row Level Security — elle est lue avant qu'une identité existe — et un
 *     code en clair y serait un mot de passe temporaire lisible par quiconque
 *     obtient une lecture sur la table.
 *   - le nombre d'essais est plafonné. Six chiffres, c'est un million de
 *     combinaisons : quelques milliers de tentatives suffisent à tomber juste
 *     assez souvent pour que cela vaille la peine d'essayer.
 *   - un délai sépare deux envois. Sans lui, le formulaire de renvoi devient un
 *     robinet à courriels pointé sur l'adresse de son choix.
 *
 * La comparaison passe par `Hash::check`, dont le temps d'exécution ne dépend
 * pas du nombre de caractères justes.
 */
class EmailOtpService
{
    public function __construct() {}

    /**
     * Émet un code et l'envoie.
     *
     * `$sendTo` détourne l'envoi vers une autre adresse que celle du compte.
     * Indispensable au changement d'adresse : le code doit partir vers la
     * **nouvelle** boîte, puisque c'est son accès qu'il s'agit de prouver.
     * L'envoyer à l'ancienne ne prouverait rien et livrerait le code à une
     * boîte que l'utilisateur veut justement quitter.
     *
     * Renvoie le code en clair uniquement hors production, pour que les tests
     * et le développement local n'aient pas à ouvrir une boîte de réception.
     */
    public function send(User $user, OtpPurpose $purpose, ?string $sendTo = null): ?string
    {
        $this->guardResendInterval($user);

        $code = $this->generateCode();

        $user->forceFill([
            'otp_code_hash' => Hash::make($code),
            'otp_purpose' => $purpose->value,
            'otp_sent_at' => now(),
            'otp_expires_at' => now()->addMinutes($this->validityMinutes()),
            'otp_attempts' => 0,
        ])->save();

        $notification = new EmailOtpNotification($code, $purpose, $this->validityMinutes());

        if ($sendTo === null) {
            $user->notify($notification);
        } else {
            Notification::route('mail', [$sendTo => $user->name])->notify($notification);
        }

        return app()->environment('production') ? null : $code;
    }

    /**
     * Vérifie un code et le consomme.
     *
     * Le code est effacé quel que soit le résultat dès que le plafond d'essais
     * est atteint : laisser un code vivant après dix échecs revient à offrir
     * dix essais de plus à chaque renvoi.
     *
     * @throws ValidationException
     */
    public function verify(User $user, string $code, OtpPurpose $purpose): void
    {
        if ($user->otp_code_hash === null || $user->otp_expires_at === null) {
            $this->fail('Aucun code n\'est en attente. Demandez-en un nouveau.');
        }

        if ($user->otp_purpose !== $purpose->value) {
            $this->fail('Ce code ne correspond pas à l\'opération en cours.');
        }

        if ($user->otp_expires_at->isPast()) {
            $this->clear($user);
            $this->fail('Ce code a expiré. Demandez-en un nouveau.');
        }

        if ($user->otp_attempts >= $this->maxAttempts()) {
            $this->clear($user);
            $this->fail('Trop de tentatives. Demandez un nouveau code.');
        }

        if (! Hash::check($code, $user->otp_code_hash)) {
            $user->forceFill(['otp_attempts' => $user->otp_attempts + 1])->save();

            $remaining = max(0, $this->maxAttempts() - $user->otp_attempts);

            $this->fail($remaining > 0
                ? "Code incorrect. Il vous reste {$remaining} tentative(s)."
                : 'Code incorrect. Demandez un nouveau code.');
        }

        $this->clear($user);
    }

    /**
     * Marque l'adresse comme vérifiée.
     *
     * Séparé de `verify()` : le changement d'adresse valide un code sans que
     * l'adresse courante devienne vérifiée pour autant — c'est la nouvelle qui
     * l'est, et elle n'est écrite qu'ensuite.
     */
    public function markEmailVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'otp_code_hash' => null,
            'otp_purpose' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ])->save();
    }

    public function validityMinutes(): int
    {
        return (int) config('immopro.otp.validity_minutes', 10);
    }

    public function maxAttempts(): int
    {
        return (int) config('immopro.otp.max_attempts', 5);
    }

    public function resendIntervalSeconds(): int
    {
        return (int) config('immopro.otp.resend_interval_seconds', 60);
    }

    /**
     * Code à six chiffres, tiré d'une source cryptographique.
     *
     * `random_int` et non `rand` : la seconde est prévisible à partir de
     * quelques tirages, ce qui suffirait à deviner le code du compte suivant.
     * Le zéro de tête est conservé — « 004218 » est un code valide, et le
     * tronquer réduirait l'espace de recherche.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** @throws ValidationException */
    private function guardResendInterval(User $user): void
    {
        $interval = $this->resendIntervalSeconds();

        if ($user->otp_sent_at === null || $interval <= 0) {
            return;
        }

        $elapsed = $user->otp_sent_at->diffInSeconds(now());

        if ($elapsed < $interval) {
            $wait = (int) ceil($interval - $elapsed);

            throw ValidationException::withMessages([
                'code' => "Veuillez patienter {$wait} seconde(s) avant de demander un nouveau code.",
            ])->status(429);
        }
    }

    /** @throws ValidationException */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
