<?php

namespace Tests\Unit;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Services\Auth\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Le plafond d'essais est éprouvé ici plutôt qu'à travers la route : le débit
 * de celle-ci coupe avant, et masquerait le comportement qu'on veut vérifier.
 */
class EmailOtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailOtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->otp = app(EmailOtpService::class);
    }

    public function test_a_correct_code_passes_once(): void
    {
        $user = User::factory()->unverified()->create();

        $code = $this->otp->send($user, OtpPurpose::EmailVerification);

        $this->otp->verify($user, $code, OtpPurpose::EmailVerification);

        // Consommé : le secret ne reste pas en base après usage.
        $this->assertNull($user->refresh()->otp_code_hash);
    }

    public function test_the_code_is_burnt_once_the_attempt_cap_is_reached(): void
    {
        $user = User::factory()->unverified()->create();

        $code = $this->otp->send($user, OtpPurpose::EmailVerification);
        $wrong = $code === '000000' ? '999999' : '000000';

        for ($attempt = 0; $attempt < $this->otp->maxAttempts(); $attempt++) {
            try {
                $this->otp->verify($user->refresh(), $wrong, OtpPurpose::EmailVerification);
                $this->fail('Un code erroné ne devrait pas passer.');
            } catch (ValidationException) {
                // Attendu.
            }
        }

        $this->assertSame($this->otp->maxAttempts(), $user->refresh()->otp_attempts);

        // Le bon code ne vaut plus rien : le plafond a effacé le secret.
        $this->expectException(ValidationException::class);
        $this->otp->verify($user->refresh(), $code, OtpPurpose::EmailVerification);
    }

    public function test_the_code_reaches_another_address_when_asked(): void
    {
        $user = User::factory()->create(['email' => 'ancienne@example.com']);

        $this->otp->send($user, OtpPurpose::EmailChange, sendTo: 'nouvelle@example.com');

        // La boîte du compte ne doit rien recevoir : le code prouve l'accès à
        // la nouvelle adresse, pas à l'ancienne.
        Notification::assertNothingSentTo($user);
        Notification::assertSentOnDemand(
            EmailOtpNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === ['nouvelle@example.com' => $user->name]
        );
    }

    public function test_two_successive_codes_differ(): void
    {
        $user = User::factory()->unverified()->create();

        $first = $this->otp->send($user, OtpPurpose::EmailVerification);

        // L'intervalle de renvoi est neutralisé : ce n'est pas ce qu'on teste.
        $user->forceFill(['otp_sent_at' => now()->subHour()])->save();

        $second = $this->otp->send($user->refresh(), OtpPurpose::EmailVerification);

        $this->assertNotSame($first, $second);
    }
}
