<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthService
{
    private const CHALLENGE_TTL_MINUTES = 5;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * URL otpauth:// à encoder en QR code côté client (Google Authenticator, Aegis, etc.).
     */
    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Vérifie un code de récupération et le consomme (usage unique).
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        if (! in_array($code, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$code])),
        ])->save();

        return true;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }

    /**
     * Crée un défi 2FA après vérification du mot de passe au login.
     * Le token est à renvoyer sur POST /auth/2fa/challenge avec le code TOTP.
     */
    public function createChallenge(User $user): string
    {
        $token = Str::random(60);

        Cache::put(
            $this->challengeKey($token),
            $user->id,
            now()->addMinutes(self::CHALLENGE_TTL_MINUTES)
        );

        return $token;
    }

    public function resolveChallenge(string $token): ?User
    {
        $userId = Cache::get($this->challengeKey($token));

        return $userId !== null ? User::find($userId) : null;
    }

    public function forgetChallenge(string $token): void
    {
        Cache::forget($this->challengeKey($token));
    }

    private function challengeKey(string $token): string
    {
        return "two_factor_challenge:{$token}";
    }
}
