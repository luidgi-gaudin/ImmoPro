<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ── Forgot password ───────────────────────────────────────────────────────

    public function test_forgot_password_sends_reset_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $response->assertStatus(200);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_link_points_to_the_frontend(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url').'/reset-password?token=');
        });
    }

    public function test_forgot_password_returns_generic_message_for_unknown_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com']);

        // Même réponse qu'un e-mail connu : pas d'énumération de comptes possible.
        $response->assertStatus(200);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_is_throttled_per_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertStatus(200);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/auth/forgot-password', [])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ── Reset password ────────────────────────────────────────────────────────

    public function test_reset_password_with_valid_token_updates_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('auth_token');

        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ]);

        $response->assertStatus(200);

        $this->assertTrue(Hash::check('newpassword1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_password_with_invalid_token_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_rejects_weak_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    // ── Update password (utilisateur connecté) ────────────────────────────────

    public function test_update_password_with_valid_current_password(): void
    {
        $user = User::factory()->create(); // mot de passe : « password »

        $response = $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ]);

        $response->assertStatus(200);

        $this->assertTrue(Hash::check('newpassword1', $user->fresh()->password));
    }

    public function test_update_password_keeps_current_token_and_revokes_the_others(): void
    {
        $user = User::factory()->create();

        $currentToken = $user->createToken('auth_token')->plainTextToken;
        $user->createToken('other_device');

        $response = $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->putJson('/api/auth/password', [
                'current_password' => 'password',
                'password' => 'newpassword1',
                'password_confirmation' => 'newpassword1',
            ]);

        $response->assertStatus(200);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('auth_token', $user->tokens()->first()->name);
    }

    public function test_update_password_with_wrong_current_password_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    }

    public function test_update_password_returns_401_for_unauthenticated(): void
    {
        $this->putJson('/api/auth/password', [])->assertStatus(401);
    }
}
