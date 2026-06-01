<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerWithProperty(): array
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        return [$user, $property, $tenant];
    }

    private function validPayload(Property $property, Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'property_id'  => $property->id,
            'tenant_id'    => $tenant->id,
            'start_date'   => '2026-01-01',
            'end_date'     => '2027-01-01',
            'monthly_rent' => 850.00,
            'deposit'      => 1700.00,
            'statut'       => LeaseStatus::Actif->value,
        ], $overrides);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_only_own_leases(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();
        Lease::factory()->count(3)->create([
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);

        // Leases d'un autre proprio
        Lease::factory()->count(2)->create();

        $response = $this->actingAs($user)->getJson('/api/leases');

        $response->assertStatus(200)->assertJsonCount(3);
    }

    public function test_index_returns_401_for_unauthenticated(): void
    {
        $this->getJson('/api/leases')->assertStatus(401);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_lease_for_owner(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->validPayload($property, $tenant));

        $response->assertStatus(201)
            ->assertJsonPath('property_id', $property->id)
            ->assertJsonPath('tenant_id', $tenant->id);

        $this->assertDatabaseHas('leases', [
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);
    }

    public function test_store_returns_422_when_property_belongs_to_another_owner(): void
    {
        [$user, , $tenant] = $this->createOwnerWithProperty();

        // Property d'un autre proprio
        $otherProperty = Property::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->validPayload($otherProperty, $tenant));

        $response->assertStatus(422)->assertJsonValidationErrors(['property_id']);
    }

    public function test_store_returns_422_when_tenant_belongs_to_another_owner(): void
    {
        [$user, $property] = $this->createOwnerWithProperty();

        // Tenant d'un autre proprio
        $otherTenant = Tenant::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->validPayload($property, $otherTenant));

        $response->assertStatus(422)->assertJsonValidationErrors(['tenant_id']);
    }

    public function test_store_returns_422_when_required_fields_missing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/leases', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['property_id', 'tenant_id', 'start_date', 'monthly_rent']);
    }

    public function test_store_returns_422_when_statut_is_invalid(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->validPayload($property, $tenant, [
            'statut' => 'invalide',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['statut']);
    }

    public function test_store_returns_401_for_unauthenticated(): void
    {
        $this->postJson('/api/leases', [])->assertStatus(401);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_lease_for_owner(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();
        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);

        $response = $this->actingAs($user)->getJson("/api/leases/{$lease->id}");

        $response->assertStatus(200)->assertJsonPath('id', $lease->id);
    }

    public function test_show_returns_403_for_non_owner(): void
    {
        [, $property, $tenant] = $this->createOwnerWithProperty();
        $other = User::factory()->create();
        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);

        $response = $this->actingAs($other)->getJson("/api/leases/{$lease->id}");

        $response->assertStatus(403);
    }

    public function test_show_returns_401_for_unauthenticated(): void
    {
        $lease = Lease::factory()->create();

        $this->getJson("/api/leases/{$lease->id}")->assertStatus(401);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_lease_for_owner(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();
        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);

        $response = $this->actingAs($user)->putJson("/api/leases/{$lease->id}", $this->validPayload($property, $tenant, [
            'monthly_rent' => 950.00,
        ]));

        $response->assertStatus(200);
        $this->assertEquals(950, $response->json('monthly_rent'));

        $this->assertDatabaseHas('leases', ['id' => $lease->id, 'monthly_rent' => 950.00]);
    }

    public function test_update_returns_403_for_non_owner(): void
    {
        [$owner, $property, $tenant] = $this->createOwnerWithProperty();
        $other = User::factory()->create();

        // Give the other user their own property and tenant so validation passes
        $otherPortfolio = Portfolio::factory()->create(['user_id' => $other->id]);
        $otherProperty = Property::factory()->create(['portfolio_id' => $otherPortfolio->id]);
        $otherTenant = Tenant::factory()->create(['user_id' => $other->id]);

        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);

        $response = $this->actingAs($other)->putJson("/api/leases/{$lease->id}", $this->validPayload($otherProperty, $otherTenant));

        $response->assertStatus(403);
    }

    public function test_update_returns_401_for_unauthenticated(): void
    {
        $lease = Lease::factory()->create();

        $this->putJson("/api/leases/{$lease->id}", [])->assertStatus(401);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_lease_for_owner(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();
        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/leases/{$lease->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('leases', ['id' => $lease->id]);
    }

    public function test_destroy_returns_403_for_non_owner(): void
    {
        [, $property, $tenant] = $this->createOwnerWithProperty();
        $other = User::factory()->create();
        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id'   => $tenant->id,
        ]);

        $response = $this->actingAs($other)->deleteJson("/api/leases/{$lease->id}");

        $response->assertStatus(403);
    }

    public function test_destroy_returns_401_for_unauthenticated(): void
    {
        $lease = Lease::factory()->create();

        $this->deleteJson("/api/leases/{$lease->id}")->assertStatus(401);
    }
}
