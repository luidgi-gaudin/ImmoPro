<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerWithLease(): array
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $lease = Lease::factory()->active()->create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'monthly_rent' => 850.00,
            'charges' => 50.00,
            'payment_day' => 1,
        ]);

        return [$user, $lease];
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_the_lease_payments_for_owner(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();

        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $period) {
            RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => $period]);
        }

        $response = $this->actingAs($user)->getJson("/api/leases/{$lease->id}/payments");

        $response->assertStatus(200)->assertJsonCount(3);
    }

    public function test_index_returns_403_for_non_owner(): void
    {
        [, $lease] = $this->createOwnerWithLease();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson("/api/leases/{$lease->id}/payments")->assertStatus(403);
    }

    public function test_index_returns_401_for_unauthenticated(): void
    {
        [, $lease] = $this->createOwnerWithLease();

        $this->getJson("/api/leases/{$lease->id}/payments")->assertStatus(401);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_defaults_amounts_to_the_lease_rent_and_charges(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();

        $response = $this->actingAs($user)->postJson("/api/leases/{$lease->id}/payments", [
            'period' => '2026-07-15', // normalisé au 1er du mois
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('amount_rent', '850.00')
            ->assertJsonPath('amount_charges', '50.00')
            ->assertJsonPath('status', 'en_retard'); // pas payé et échéance passée (03/07 + payment_day 1)

        $payment = RentPayment::firstWhere('lease_id', $lease->id);

        $this->assertSame('2026-07-01', $payment->period->toDateString());
    }

    public function test_store_rejects_a_duplicate_period_for_the_same_lease(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();
        RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-07-01']);

        $response = $this->actingAs($user)->postJson("/api/leases/{$lease->id}/payments", [
            'period' => '2026-07-20',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['period']);
    }

    public function test_store_returns_403_for_non_owner(): void
    {
        [, $lease] = $this->createOwnerWithLease();
        $other = User::factory()->create();

        $this->actingAs($other)->postJson("/api/leases/{$lease->id}/payments", [
            'period' => '2026-07-01',
        ])->assertStatus(403);
    }

    // ── Show / scoping ────────────────────────────────────────────────────────

    public function test_show_returns_the_payment_for_owner(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-05-01']);

        $this->actingAs($user)->getJson("/api/leases/{$lease->id}/payments/{$payment->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $payment->id);
    }

    public function test_a_payment_from_another_lease_returns_404(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();

        $otherLease = Lease::factory()->active()->create([
            'property_id' => $lease->property_id,
            'tenant_id' => $lease->tenant_id,
            'start_date' => $lease->start_date,
            'end_date' => $lease->end_date,
        ]);
        $foreignPayment = RentPayment::factory()->create(['lease_id' => $otherLease->id, 'period' => '2026-05-01']);

        $this->actingAs($user)->getJson("/api/leases/{$lease->id}/payments/{$foreignPayment->id}")
            ->assertStatus(404);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_marks_a_payment_as_paid(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-06-01']);

        $response = $this->actingAs($user)->putJson("/api/leases/{$lease->id}/payments/{$payment->id}", [
            'period' => '2026-06-01',
            'paid_at' => '2026-06-05',
            'payment_method' => 'virement',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'paye');
    }

    // ── Quittance (art. 21, loi n° 89-462) ────────────────────────────────────

    public function test_quittance_is_issued_for_a_paid_payment_with_rent_and_charges_detail(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();
        $payment = RentPayment::factory()->paid()->create([
            'lease_id' => $lease->id,
            'period' => '2026-06-01',
            'amount_rent' => 850.00,
            'amount_charges' => 50.00,
        ]);

        $response = $this->actingAs($user)->getJson("/api/leases/{$lease->id}/payments/{$payment->id}/quittance");

        $response->assertStatus(200)
            ->assertJsonPath('quittance.detail.loyer', 850)
            ->assertJsonPath('quittance.detail.charges', 50)
            ->assertJsonPath('quittance.detail.total', 900)
            ->assertJsonPath('quittance.periode.debut', '2026-06-01')
            ->assertJsonPath('quittance.periode.fin', '2026-06-30')
            ->assertJsonStructure(['quittance' => ['numero', 'bailleur' => ['nom'], 'locataire' => ['nom'], 'bien', 'date_paiement', 'mention_legale']]);
    }

    public function test_quittance_is_refused_for_an_unpaid_payment(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-06-01']);

        $this->actingAs($user)->getJson("/api/leases/{$lease->id}/payments/{$payment->id}/quittance")
            ->assertStatus(422);
    }

    public function test_quittance_returns_403_for_non_owner(): void
    {
        [, $lease] = $this->createOwnerWithLease();
        $other = User::factory()->create();
        $payment = RentPayment::factory()->paid()->create(['lease_id' => $lease->id, 'period' => '2026-06-01']);

        $this->actingAs($other)->getJson("/api/leases/{$lease->id}/payments/{$payment->id}/quittance")
            ->assertStatus(403);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_the_payment(): void
    {
        [$user, $lease] = $this->createOwnerWithLease();
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-06-01']);

        $this->actingAs($user)->deleteJson("/api/leases/{$lease->id}/payments/{$payment->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('rent_payments', ['id' => $payment->id]);
    }

    public function test_destroy_returns_403_for_non_owner(): void
    {
        [, $lease] = $this->createOwnerWithLease();
        $other = User::factory()->create();
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-06-01']);

        $this->actingAs($other)->deleteJson("/api/leases/{$lease->id}/payments/{$payment->id}")
            ->assertStatus(403);
    }
}
