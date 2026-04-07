<?php

namespace Tests\Feature;

use App\Enums\Dpe;
use App\Enums\PropertyType;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title'         => 'Appartement Paris 11',
            'property_type' => PropertyType::Appartement->value,
            'address'       => '12 rue de la Paix',
            'city'          => 'Paris',
            'postal_code'   => '75011',
            'dpe'           => Dpe::B->value,
        ], $overrides);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_properties_of_portfolio_for_owner(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        Property::factory()->count(3)->create(['portfolio_id' => $portfolio->id]);

        // Propriétés d'un autre portfolio (ne doivent pas apparaître)
        Property::factory()->count(2)->create();

        $response = $this->actingAs($user)->getJson("/api/portfolios/{$portfolio->id}/properties");

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function test_index_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->getJson("/api/portfolios/{$portfolio->id}/properties");

        $response->assertStatus(403);
    }

    public function test_index_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->getJson("/api/portfolios/{$portfolio->id}/properties");

        $response->assertStatus(401);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_property_linked_to_portfolio(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/portfolios/{$portfolio->id}/properties",
            $this->validPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('title', 'Appartement Paris 11')
            ->assertJsonPath('portfolio_id', $portfolio->id);

        $this->assertDatabaseHas('properties', [
            'title'        => 'Appartement Paris 11',
            'portfolio_id' => $portfolio->id,
        ]);
    }

    public function test_store_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->postJson(
            "/api/portfolios/{$portfolio->id}/properties",
            $this->validPayload()
        );

        $response->assertStatus(403);
    }

    public function test_store_returns_422_when_required_fields_are_missing(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/portfolios/{$portfolio->id}/properties",
            []
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'property_type', 'address', 'city', 'postal_code', 'dpe']);
    }

    public function test_store_returns_422_when_property_type_is_invalid(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/portfolios/{$portfolio->id}/properties",
            $this->validPayload(['property_type' => 'invalide'])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['property_type']);
    }

    public function test_store_returns_422_when_dpe_is_invalid(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/portfolios/{$portfolio->id}/properties",
            $this->validPayload(['dpe' => 'Z'])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dpe']);
    }

    public function test_store_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->postJson(
            "/api/portfolios/{$portfolio->id}/properties",
            $this->validPayload()
        );

        $response->assertStatus(401);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_property_for_portfolio_owner(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($user)->getJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}"
        );

        $response->assertStatus(200)
            ->assertJsonPath('id', $property->id);
    }

    public function test_show_returns_404_when_property_does_not_belong_to_portfolio(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $otherProperty = Property::factory()->create(); // appartient à un autre portfolio

        $response = $this->actingAs($user)->getJson(
            "/api/portfolios/{$portfolio->id}/properties/{$otherProperty->id}"
        );

        $response->assertStatus(404);
    }

    public function test_show_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($other)->getJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}"
        );

        $response->assertStatus(403);
    }

    public function test_show_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->getJson("/api/portfolios/{$portfolio->id}/properties/{$property->id}");

        $response->assertStatus(401);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_property_for_portfolio_owner(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($user)->putJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}",
            $this->validPayload(['title' => 'Nouveau titre', 'dpe' => Dpe::A->value])
        );

        $response->assertStatus(200)
            ->assertJsonPath('title', 'Nouveau titre');

        $this->assertDatabaseHas('properties', [
            'id'    => $property->id,
            'title' => 'Nouveau titre',
            'dpe'   => Dpe::A->value,
        ]);
    }

    public function test_update_returns_422_when_property_type_is_invalid(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($user)->putJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}",
            $this->validPayload(['property_type' => 'invalide'])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['property_type']);
    }

    public function test_update_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($other)->putJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}",
            $this->validPayload()
        );

        $response->assertStatus(403);
    }

    public function test_update_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->putJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}",
            $this->validPayload()
        );

        $response->assertStatus(401);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_property_for_portfolio_owner(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($user)->deleteJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}"
        );

        $response->assertStatus(200);

        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
    }

    public function test_destroy_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($other)->deleteJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}"
        );

        $response->assertStatus(403);

        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }

    public function test_destroy_returns_401_for_unauthenticated_request(): void
    {
        $portfolio = Portfolio::factory()->create();
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->deleteJson(
            "/api/portfolios/{$portfolio->id}/properties/{$property->id}"
        );

        $response->assertStatus(401);
    }
}
