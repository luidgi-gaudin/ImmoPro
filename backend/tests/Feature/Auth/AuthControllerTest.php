<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Register ──────────────────────────────────────────────────────────────

    private function asSpa(): static
    {
        return $this->withHeader('Referer', 'http://localhost:4200');
    }

    public function test_register_creates_user_and_returns_201(): void
    {
        $response = $this->asSpa()->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'created_at']]);

        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_register_returns_422_when_email_is_already_taken(): void
    {
        User::factory()->create(['email' => 'john@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_returns_422_when_password_confirmation_does_not_match(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password1',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_returns_422_when_required_fields_are_missing(): void
    {
        $response = $this->postJson('/api/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function test_login_returns_200_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password1')]);

        $response = $this->asSpa()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_login_returns_401_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->asSpa()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment(['message' => 'Identifiants invalides.']);
    }

    public function test_login_returns_422_when_required_fields_are_missing(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function test_logout_invalidates_session_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->asSpa()->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Déconnexion réussie.']);
    }

    public function test_logout_returns_401_for_unauthenticated_request(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    // ── User ──────────────────────────────────────────────────────────────────

    public function test_user_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_user_returns_401_for_unauthenticated_request(): void
    {
        $response = $this->getJson('/api/auth/user');

        $response->assertStatus(401);
    }
}
