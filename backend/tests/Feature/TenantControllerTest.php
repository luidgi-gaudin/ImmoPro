<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jean',
            'last_name'  => 'Dupont',
            'email'      => 'jean@example.com',
            'phone'      => '0601020304',
        ], $overrides);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_only_own_tenants(): void
    {
        $user = User::factory()->create();
        Tenant::factory()->count(3)->create(['user_id' => $user->id]);
        Tenant::factory()->count(2)->create(); // autre proprio

        $response = $this->actingAs($user)->getJson('/api/tenants');

        $response->assertStatus(200)->assertJsonCount(3);
    }

    public function test_index_returns_401_for_unauthenticated(): void
    {
        $this->getJson('/api/tenants')->assertStatus(401);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_tenant_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/tenants', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('first_name', 'Jean')
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('tenants', [
            'first_name' => 'Jean',
            'user_id'    => $user->id,
        ]);
    }

    public function test_store_returns_422_when_required_fields_missing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/tenants', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_store_returns_422_when_email_invalid(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/tenants', $this->validPayload([
            'email' => 'not-an-email',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_store_returns_401_for_unauthenticated(): void
    {
        $this->postJson('/api/tenants', $this->validPayload())->assertStatus(401);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_tenant_for_owner(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/tenants/{$tenant->id}");

        $response->assertStatus(200)->assertJsonPath('id', $tenant->id);
    }

    public function test_show_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->getJson("/api/tenants/{$tenant->id}");

        $response->assertStatus(403);
    }

    public function test_show_returns_401_for_unauthenticated(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson("/api/tenants/{$tenant->id}")->assertStatus(401);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_tenant_for_owner(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/tenants/{$tenant->id}", $this->validPayload([
            'first_name' => 'Pierre',
        ]));

        $response->assertStatus(200)->assertJsonPath('first_name', 'Pierre');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'first_name' => 'Pierre']);
    }

    public function test_update_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->putJson("/api/tenants/{$tenant->id}", $this->validPayload());

        $response->assertStatus(403);
    }

    public function test_update_returns_422_on_validation_error(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/tenants/{$tenant->id}", [
            'email' => 'invalid',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_returns_401_for_unauthenticated(): void
    {
        $tenant = Tenant::factory()->create();

        $this->putJson("/api/tenants/{$tenant->id}", $this->validPayload())->assertStatus(401);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_tenant_for_owner(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->deleteJson("/api/tenants/{$tenant->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    public function test_destroy_returns_403_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->deleteJson("/api/tenants/{$tenant->id}");

        $response->assertStatus(403);
    }

    public function test_destroy_returns_401_for_unauthenticated(): void
    {
        $tenant = Tenant::factory()->create();

        $this->deleteJson("/api/tenants/{$tenant->id}")->assertStatus(401);
    }
}
