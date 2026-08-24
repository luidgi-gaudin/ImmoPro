<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Le rapport est entièrement calculé en SQL depuis qu'il tient en une requête.
 * Ces tests vérifient que le statut d'une échéance, qui n'existe pas en base et
 * se déduit du jour d'échéance du bail, donne exactement le même résultat que
 * la règle écrite en PHP dans RentPaymentStatus.
 */
class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le 20 du mois : après un jour d'échéance au 5, avant un au 28.
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 10));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bailleur(int $paymentDay = 5): array
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        $loue = Property::factory()->create(['portfolio_id' => $portfolio->id, 'is_rented' => true]);
        Property::factory()->create(['portfolio_id' => $portfolio->id, 'is_rented' => false]);

        $tenant = Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Durand']);

        $lease = Lease::factory()->create([
            'property_id' => $loue->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Actif->value,
            'monthly_rent' => 900,
            'charges' => 100,
            'payment_day' => $paymentDay,
        ]);

        return [$user, $lease, $tenant, $loue];
    }

    public function test_overview_summarises_the_estate(): void
    {
        [$user] = $this->bailleur();

        $this->actingAs($user)->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('bailleur.total_portfolios', 1)
            ->assertJsonPath('bailleur.total_properties', 2)
            ->assertJsonPath('bailleur.occupied_properties', 1)
            ->assertJsonPath('bailleur.vacant_properties', 1)
            ->assertJsonPath('bailleur.total_tenants', 1)
            ->assertJsonPath('bailleur.active_leases', 1)
            ->assertJsonPath('bailleur.monthly_rent_expected', 900);
    }

    /** Jour d'échéance dépassé, loyer non enregistré : en retard. */
    public function test_an_unpaid_rent_past_its_due_day_counts_as_late(): void
    {
        [$user, $lease] = $this->bailleur(paymentDay: 5);

        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => '2026-06-01',
            'amount_rent' => 900,
            'amount_charges' => 100,
            'paid_at' => null,
        ]);

        $this->actingAs($user)->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('bailleur.this_month.late_count', 1)
            ->assertJsonPath('bailleur.this_month.late_amount', 1000)
            ->assertJsonPath('bailleur.this_month.pending_count', 0);
    }

    /** Jour d'échéance encore à venir : en attente, pas en retard. */
    public function test_an_unpaid_rent_before_its_due_day_stays_pending(): void
    {
        [$user, $lease] = $this->bailleur(paymentDay: 28);

        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => '2026-06-01',
            'amount_rent' => 900,
            'amount_charges' => 100,
            'paid_at' => null,
        ]);

        $this->actingAs($user)->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('bailleur.this_month.pending_count', 1)
            ->assertJsonPath('bailleur.this_month.pending_amount', 1000)
            ->assertJsonPath('bailleur.this_month.late_count', 0);
    }

    /**
     * Un jour d'échéance au 31 tombe au dernier jour des mois plus courts —
     * c'est la règle appliquée en PHP, le SQL doit dire la même chose.
     */
    public function test_a_payment_day_of_31_falls_on_the_last_day_of_a_short_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 30, 10));

        [$user, $lease] = $this->bailleur(paymentDay: 31);

        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => '2026-06-01',
            'amount_rent' => 900,
            'amount_charges' => 100,
            'paid_at' => null,
        ]);

        // Juin compte 30 jours : l'échéance tombe le 30, on est le 30, elle
        // n'est donc pas encore dépassée.
        $this->actingAs($user)->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('bailleur.this_month.pending_count', 1)
            ->assertJsonPath('bailleur.this_month.late_count', 0);
    }

    public function test_a_paid_rent_is_counted_as_collected(): void
    {
        [$user, $lease] = $this->bailleur();

        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => '2026-06-01',
            'amount_rent' => 900,
            'amount_charges' => 100,
            'paid_at' => '2026-06-03',
        ]);

        $this->actingAs($user)->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('bailleur.this_month.paid_count', 1)
            ->assertJsonPath('bailleur.this_month.paid_amount', 1000);
    }

    public function test_breakdown_by_property_names_the_tenant_in_place(): void
    {
        [$user, , $tenant, $loue] = $this->bailleur();

        $response = $this->actingAs($user)->getJson('/api/reports/overview')->assertOk();

        $ligne = collect($response->json('par_bien'))->firstWhere('id', $loue->id);

        $this->assertSame($loue->title, $ligne['title']);
        $this->assertTrue($ligne['is_rented']);
        $this->assertSame($tenant->first_name.' '.$tenant->last_name, $ligne['tenant_name']);
        $this->assertEquals(900, $ligne['monthly_rent']);

        // Le bien vacant figure aussi, sans locataire.
        $vacant = collect($response->json('par_bien'))->firstWhere('is_rented', false);
        $this->assertNotNull($vacant);
        $this->assertNull($vacant['tenant_name']);
    }

    public function test_breakdown_by_tenant_totals_paid_and_outstanding(): void
    {
        [$user, $lease, $tenant] = $this->bailleur();

        RentPayment::factory()->create([
            'lease_id' => $lease->id, 'period' => '2026-05-01',
            'amount_rent' => 900, 'amount_charges' => 100, 'paid_at' => '2026-05-04',
        ]);
        RentPayment::factory()->create([
            'lease_id' => $lease->id, 'period' => '2026-06-01',
            'amount_rent' => 900, 'amount_charges' => 100, 'paid_at' => null,
        ]);

        $ligne = collect($this->actingAs($user)->getJson('/api/reports/overview')->json('par_locataire'))
            ->firstWhere('id', $tenant->id);

        $this->assertEquals(1000, $ligne['total_paid']);
        $this->assertEquals(1000, $ligne['total_due']);
        $this->assertSame(1, $ligne['late_count']);
        $this->assertSame($lease->id, $ligne['active_lease_id']);
    }

    public function test_a_report_never_leaks_another_landlords_estate(): void
    {
        [$user] = $this->bailleur();
        $this->bailleur();

        $this->actingAs($user)->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('bailleur.total_portfolios', 1)
            ->assertJsonPath('bailleur.total_properties', 2)
            ->assertJsonCount(2, 'par_bien')
            ->assertJsonCount(1, 'par_locataire');
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/reports/overview')->assertUnauthorized();
    }
}
