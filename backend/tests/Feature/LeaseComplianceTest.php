<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Règles issues de la loi n° 89-462 du 6 juillet 1989 :
 * plafonds de dépôt de garantie et durées des baux.
 */
class LeaseComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerWithProperty(): array
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id, 'is_rented' => false]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        return [$user, $property, $tenant];
    }

    private function payload(Property $property, Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'type' => 'nu',
            'start_date' => '2026-01-01',
            'end_date' => '2029-01-01',
            'monthly_rent' => 800.00,
            'charges' => 50.00,
            'deposit' => 800.00,
            'payment_day' => 5,
            'statut' => LeaseStatus::Actif->value,
        ], $overrides);
    }

    // ── Dépôt de garantie (art. 22, 25-6, 25-13) ──────────────────────────────

    public function test_bail_nu_rejects_deposit_over_one_month_of_rent(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'deposit' => 900.00, // > 1 mois (800)
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['deposit']);
    }

    public function test_bail_nu_accepts_deposit_of_one_month(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant))
            ->assertStatus(201);
    }

    public function test_bail_meuble_rejects_deposit_over_two_months(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'type' => 'meuble',
            'end_date' => '2027-01-01',
            'deposit' => 1700.00, // > 2 mois (1600)
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['deposit']);
    }

    public function test_bail_mobilite_rejects_any_deposit(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'type' => 'mobilite',
            'end_date' => '2026-06-01',
            'deposit' => 100.00,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['deposit']);
    }

    // ── Durées des baux (art. 10, 25-7, 25-12) ────────────────────────────────

    public function test_bail_nu_rejects_duration_under_three_years(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'end_date' => '2027-01-01', // 1 an < 3 ans
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    public function test_bail_nu_accepts_open_ended_lease(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'end_date' => null, // tacite reconduction
        ]))->assertStatus(201);
    }

    public function test_bail_mobilite_requires_an_end_date(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'type' => 'mobilite',
            'end_date' => null,
            'deposit' => null,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    public function test_bail_mobilite_rejects_duration_over_ten_months(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'type' => 'mobilite',
            'end_date' => '2026-12-15', // > 10 mois
            'deposit' => null,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    public function test_bail_mobilite_accepts_six_months(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'type' => 'mobilite',
            'end_date' => '2026-07-01',
            'deposit' => null,
        ]))->assertStatus(201);
    }

    public function test_bail_etudiant_accepts_nine_months(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'type' => 'etudiant',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'deposit' => 1600.00, // 2 mois autorisés en meublé étudiant
        ]))->assertStatus(201);
    }

    public function test_bail_etudiant_rejects_twelve_months(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $response = $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant, [
            'type' => 'etudiant',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
            'deposit' => null,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    // ── Synchronisation du statut locatif du bien ─────────────────────────────

    public function test_creating_an_active_lease_marks_the_property_as_rented(): void
    {
        [$user, $property, $tenant] = $this->createOwnerWithProperty();

        $this->assertFalse($property->is_rented);

        $this->actingAs($user)->postJson('/api/leases', $this->payload($property, $tenant))
            ->assertStatus(201);

        $this->assertTrue($property->fresh()->is_rented);
    }
}
