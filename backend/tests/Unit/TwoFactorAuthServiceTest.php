<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TwoFactorAuthService::class);
    }

    public function test_generated_secret_is_valid_base32(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16,}$/', $secret);
    }

    public function test_verify_code_accepts_the_current_otp(): void
    {
        $secret = $this->service->generateSecret();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->assertTrue($this->service->verifyCode($secret, $code));
    }

    public function test_verify_code_rejects_a_wrong_code(): void
    {
        $secret = $this->service->generateSecret();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        // Code différent du code courant, même longueur
        $wrong = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->assertFalse($this->service->verifyCode($secret, $wrong));
    }

    public function test_recovery_codes_are_unique_and_well_formed(): void
    {
        $codes = $this->service->generateRecoveryCodes();

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
        }
    }

    public function test_challenge_can_be_resolved_then_forgotten(): void
    {
        $user = User::factory()->create();

        $token = $this->service->createChallenge($user);

        $this->assertTrue($user->is($this->service->resolveChallenge($token)));

        $this->service->forgetChallenge($token);

        $this->assertNull($this->service->resolveChallenge($token));
    }

    public function test_unknown_challenge_token_resolves_to_null(): void
    {
        $this->assertNull($this->service->resolveChallenge('unknown-token'));
    }

    public function test_recovery_code_is_consumed_after_use(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_recovery_codes' => ['AAAAA-AAAAA', 'BBBBB-BBBBB']])->save();

        $this->assertTrue($this->service->verifyRecoveryCode($user, 'AAAAA-AAAAA'));
        $this->assertFalse($this->service->verifyRecoveryCode($user->fresh(), 'AAAAA-AAAAA'));
        $this->assertSame(['BBBBB-BBBBB'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_wrong_recovery_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_recovery_codes' => ['AAAAA-AAAAA']])->save();

        $this->assertFalse($this->service->verifyRecoveryCode($user, 'CCCCC-CCCCC'));
        $this->assertSame(['AAAAA-AAAAA'], $user->fresh()->two_factor_recovery_codes);
    }
}
