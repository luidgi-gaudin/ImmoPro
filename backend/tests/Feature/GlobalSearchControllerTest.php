<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\LeaseStatus;
use App\Models\Alert;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::factory()->create();
    }

    public function test_global_search_requires_authentication(): void
    {
        $this->getJson('/api/search?q=paris')->assertUnauthorized();
    }

    public function test_global_search_returns_empty_when_query_too_short(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->getJson('/api/search?q=a')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('results.properties', []);
    }

    public function test_global_search_finds_entities_across_categories(): void
    {
        $user = $this->createUser();

        $portfolio = Portfolio::factory()->create([
            'user_id' => $user->id,
            'name' => 'Patrimoine Horizon Elysée',
        ]);

        $property = Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'title' => 'Appartement Elysée 4P',
            'city' => 'Paris',
        ]);

        $tenant = Tenant::factory()->create([
            'user_id' => $user->id,
            'first_name' => 'Jean',
            'last_name' => 'Elysée-Bernard',
            'email' => 'jean.elysee@example.com',
        ]);

        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Actif->value,
            'monthly_rent' => 1800,
        ]);

        $alert = Alert::factory()->create([
            'user_id' => $user->id,
            'title' => 'Révision de loyer Elysée',
            'severity' => AlertSeverity::Warning->value,
        ]);

        $response = $this->actingAs($user)->getJson('/api/search?q=elysée');

        $response->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonCount(1, 'results.portfolios')
            ->assertJsonCount(1, 'results.properties')
            ->assertJsonCount(1, 'results.tenants')
            ->assertJsonCount(1, 'results.leases')
            ->assertJsonCount(1, 'results.alerts');

        $this->assertSame('Patrimoine Horizon Elysée', $response->json('results.portfolios.0.title'));
        $this->assertSame('Appartement Elysée 4P', $response->json('results.properties.0.title'));
        $this->assertSame('Jean Elysée-Bernard', $response->json('results.tenants.0.title'));
    }

    public function test_global_search_strictly_isolates_user_data(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        // User A's property
        $portfolioA = Portfolio::factory()->create(['user_id' => $userA->id, 'name' => 'Portfolio A']);
        Property::factory()->create(['portfolio_id' => $portfolioA->id, 'title' => 'Secret Villa Alpha']);

        // User B's property
        $portfolioB = Portfolio::factory()->create(['user_id' => $userB->id, 'name' => 'Portfolio B']);
        Property::factory()->create(['portfolio_id' => $portfolioB->id, 'title' => 'Secret Villa Beta']);

        // User A searches for "Secret Villa"
        $responseA = $this->actingAs($userA)->getJson('/api/search?q=Secret+Villa');
        $responseA->assertOk();
        $this->assertCount(1, $responseA->json('results.properties'));
        $this->assertSame('Secret Villa Alpha', $responseA->json('results.properties.0.title'));

        // User B searches for "Secret Villa"
        $responseB = $this->actingAs($userB)->getJson('/api/search?q=Secret+Villa');
        $responseB->assertOk();
        $this->assertCount(1, $responseB->json('results.properties'));
        $this->assertSame('Secret Villa Beta', $responseB->json('results.properties.0.title'));
    }

    public function test_global_search_escapes_sql_wildcards_literally(): void
    {
        $user = $this->createUser();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id, 'name' => 'Standard Portfolio']);
        Property::factory()->create(['portfolio_id' => $portfolio->id, 'title' => 'Appartement Standard']);

        // % must not match everything
        $this->actingAs($user)->getJson('/api/search?q=%25%25')
            ->assertOk()
            ->assertJsonPath('total', 0);

        // _ must not act as a single-char wildcard
        $this->actingAs($user)->getJson('/api/search?q=Appart_ment')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }
}
