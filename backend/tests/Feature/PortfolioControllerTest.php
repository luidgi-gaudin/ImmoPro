<?php

namespace Tests\Feature;

use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_only_authenticated_user_portfolios(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Portfolio::factory()->count(2)->create(['user_id' => $user->id]);
        Portfolio::factory()->count(3)->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->getJson('/api/portfolios');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_index_returns_401_for_unauthenticated_request(): void
    {
        $response = $this->getJson('/api/portfolios');

        $response->assertStatus(401);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_portfolio_linked_to_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/portfolios', [
            'name' => 'Mon portfolio',
            'description' => 'Une description',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Mon portfolio')
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('portfolios', [
            'name' => 'Mon portfolio',
            'user_id' => $user->id,
        ]);
    }

    public function test_store_creates_portfolio_without_description(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/portfolios', [
            'name' => 'Mon portfolio',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Mon portfolio');
    }

    public function test_store_returns_422_when_name_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/portfolios', [
            'description' => 'Sans nom',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_returns_401_for_unauthenticated_request(): void
    {
        $response = $this->postJson('/api/portfolios', ['name' => 'Test']);

        $response->assertStatus(401);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_portfolio_for_owner(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/portfolios/{$portfolio->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $portfolio->id)
            ->assertJsonPath('name', $portfolio->name);
    }

    public function test_show_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->getJson("/api/portfolios/{$portfolio->id}");

        $response->assertStatus(403);
    }

    public function test_show_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->getJson("/api/portfolios/{$portfolio->id}");

        $response->assertStatus(401);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_portfolio_for_owner(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/portfolios/{$portfolio->id}", [
            'name' => 'Nouveau nom',
            'description' => 'Nouvelle description',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Nouveau nom');

        $this->assertDatabaseHas('portfolios', [
            'id' => $portfolio->id,
            'name' => 'Nouveau nom',
        ]);
    }

    public function test_update_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->putJson("/api/portfolios/{$portfolio->id}", [
            'name' => 'Tentative',
        ]);

        $response->assertStatus(403);
    }

    public function test_update_returns_422_when_name_is_missing(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/portfolios/{$portfolio->id}", [
            'description' => 'Sans nom',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->putJson("/api/portfolios/{$portfolio->id}", ['name' => 'Test']);

        $response->assertStatus(401);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_portfolio_for_owner(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->deleteJson("/api/portfolios/{$portfolio->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('portfolios', ['id' => $portfolio->id]);
    }

    public function test_destroy_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->deleteJson("/api/portfolios/{$portfolio->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('portfolios', ['id' => $portfolio->id]);
    }

    public function test_destroy_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->deleteJson("/api/portfolios/{$portfolio->id}");

        $response->assertStatus(401);
    }
}
