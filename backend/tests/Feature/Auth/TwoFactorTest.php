<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Active et confirme la 2FA pour l'utilisateur ; retourne [secret, codes de récupération].
     *
     * @return array{0: string, 1: list<string>}
     */
    private function enableTwoFactorFor(User $user): array
    {
        $secret = $this->actingAs($user)->postJson('/api/auth/2fa/enable')->json('secret');

        $response = $this->postJson('/api/auth/2fa/confirm', [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ]);

        return [$secret, $response->json('recovery_codes')];
    }

    // ── Activation ────────────────────────────────────────────────────────────

    public function test_enable_returns_secret_and_otpauth_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/2fa/enable');

        $response->assertStatus(200)->assertJsonStructure(['secret', 'otpauth_url']);

        $this->assertStringStartsWith('otpauth://totp/', $response->json('otpauth_url'));
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled(), 'La 2FA ne doit être active qu\'après confirmation.');
    }

    public function test_confirm_with_valid_code_enables_2fa_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();

        [, $recoveryCodes] = $this->enableTwoFactorFor($user);

        $this->assertCount(8, $recoveryCodes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_with_invalid_code_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/2fa/enable');

        $response = $this->postJson('/api/auth/2fa/confirm', ['code' => '000000']);

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_without_pending_activation_returns_400(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/2fa/confirm', ['code' => '123456'])
            ->assertStatus(400);
    }

    public function test_enable_when_already_enabled_returns_409(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);

        $this->postJson('/api/auth/2fa/enable')->assertStatus(409);
    }

    public function test_secret_is_never_exposed_by_the_user_endpoint(): void
    {
        $user = User::factory()->create();
        [$secret] = $this->enableTwoFactorFor($user);

        $response = $this->getJson('/api/auth/user');

        $response->assertStatus(200);
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // ── Login en deux étapes ──────────────────────────────────────────────────

    public function test_login_with_2fa_enabled_returns_a_challenge_instead_of_a_token(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['challenge_token'])
            ->assertJsonMissingPath('token');
    }

    public function test_challenge_with_valid_code_returns_a_token(): void
    {
        $user = User::factory()->create();
        [$secret] = $this->enableTwoFactorFor($user);

        $challengeToken = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('challenge_token');

        $response = $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'token'])
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_challenge_with_invalid_code_returns_422(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);

        $challengeToken = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('challenge_token');

        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => '000000',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_challenge_with_unknown_token_returns_401(): void
    {
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => 'unknown-token',
            'code' => '123456',
        ])->assertStatus(401);
    }

    public function test_challenge_with_recovery_code_works_once(): void
    {
        $user = User::factory()->create();
        [, $recoveryCodes] = $this->enableTwoFactorFor($user);

        $login = fn () => $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('challenge_token');

        // Premier usage : accepté
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $login(),
            'recovery_code' => $recoveryCodes[0],
        ])->assertStatus(200)->assertJsonStructure(['token']);

        // Second usage du même code : refusé (usage unique)
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $login(),
            'recovery_code' => $recoveryCodes[0],
        ])->assertStatus(422);
    }

    // ── Désactivation et codes de récupération ────────────────────────────────

    public function test_disable_with_correct_password_turns_2fa_off(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);

        $this->postJson('/api/auth/2fa/disable', ['password' => 'password'])
            ->assertStatus(200);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        // Le login redevient direct
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_disable_with_wrong_password_returns_422(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);

        $this->postJson('/api/auth/2fa/disable', ['password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        $user = User::factory()->create();
        [, $oldCodes] = $this->enableTwoFactorFor($user);

        $response = $this->postJson('/api/auth/2fa/recovery-codes', ['password' => 'password']);

        $response->assertStatus(200);

        $newCodes = $response->json('recovery_codes');

        $this->assertCount(8, $newCodes);
        $this->assertEmpty(array_intersect($oldCodes, $newCodes));
    }

    public function test_recovery_codes_require_2fa_to_be_enabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/2fa/recovery-codes', ['password' => 'password'])
            ->assertStatus(400);
    }

    public function test_2fa_endpoints_require_authentication(): void
    {
        $this->postJson('/api/auth/2fa/enable')->assertStatus(401);
        $this->postJson('/api/auth/2fa/confirm', ['code' => '123456'])->assertStatus(401);
        $this->postJson('/api/auth/2fa/disable', ['password' => 'x'])->assertStatus(401);
        $this->postJson('/api/auth/2fa/recovery-codes', ['password' => 'x'])->assertStatus(401);
    }
}
