<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Leases\RentScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Génération de l'échéancier de loyer et pointage des règlements.
 *
 * Ce que ces tests protègent, c'est le geste que l'écran promet : créer un bail
 * pose ses échéances, et constater un virement tient en un appel. Si l'un des
 * deux redevient une saisie manuelle, c'est ici que cela se voit.
 */
class RentScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Lease} */
    private function ownerWithLease(array $attributes = []): array
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $lease = Lease::factory()->active()->create(array_merge([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01',
            'end_date' => '2029-01-01',
            'monthly_rent' => 800.00,
            'charges' => 50.00,
            'payment_day' => 5,
        ], $attributes));

        return [$user, $lease];
    }

    // ── Le service ────────────────────────────────────────────────────────────

    public function test_it_generates_one_payment_per_month_of_the_requested_range(): void
    {
        [, $lease] = $this->ownerWithLease();

        $result = app(RentScheduleGenerator::class)->generate(
            $lease,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-06-01'),
        );

        $this->assertSame(6, $result['created']);
        $this->assertSame(6, $lease->payments()->count());

        $periods = $lease->payments()->orderBy('period')->pluck('period')
            ->map(fn ($period) => CarbonImmutable::parse($period)->format('Y-m'))->all();

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], $periods);
    }

    /**
     * Le bouton doit pouvoir être pressé deux fois sans conséquence : c'est ce
     * qui permet de l'exposer en permanence plutôt que de le réserver à un bail
     * vierge.
     */
    public function test_generating_twice_creates_nothing_new(): void
    {
        [, $lease] = $this->ownerWithLease();
        $generator = app(RentScheduleGenerator::class);

        $generator->generate($lease, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-01'));
        $second = $generator->generate($lease, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-01'));

        $this->assertSame(0, $second['created']);
        $this->assertSame(3, $second['skipped']);
        $this->assertSame(3, $lease->payments()->count());
    }

    /** Les mois manquants sont comblés sans toucher à ceux déjà pointés. */
    public function test_it_fills_only_the_gaps(): void
    {
        [, $lease] = $this->ownerWithLease();

        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => '2026-02-01',
            'amount_rent' => 123.45,
            'paid_at' => '2026-02-03',
        ]);

        $result = app(RentScheduleGenerator::class)->generate(
            $lease,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-03-01'),
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('123.45', $lease->payments()->whereDate('period', '2026-02-01')->value('amount_rent'));
    }

    /**
     * Un bail qui prend effet le 15 ne doit pas appeler un mois plein : dix-sept
     * jours sur trente et un en janvier, soit 800 × 17/31.
     */
    public function test_the_first_month_is_prorated_to_the_days_actually_occupied(): void
    {
        [, $lease] = $this->ownerWithLease(['start_date' => '2026-01-15']);

        app(RentScheduleGenerator::class)->generate(
            $lease,
            null,
            CarbonImmutable::parse('2026-02-01'),
        );

        $first = $lease->payments()->whereDate('period', '2026-01-01')->first();
        $second = $lease->payments()->whereDate('period', '2026-02-01')->first();

        $this->assertSame(round(800 * 17 / 31, 2), (float) $first->amount_rent);
        $this->assertSame(round(50 * 17 / 31, 2), (float) $first->amount_charges);
        $this->assertSame(800.0, (float) $second->amount_rent);
    }

    public function test_prorating_can_be_turned_off(): void
    {
        [, $lease] = $this->ownerWithLease(['start_date' => '2026-01-15']);

        app(RentScheduleGenerator::class)->generate(
            $lease,
            null,
            CarbonImmutable::parse('2026-01-01'),
            prorate: false,
        );

        $this->assertSame(800.0, (float) $lease->payments()->first()->amount_rent);
    }

    /** Aucune échéance avant la prise d'effet ni après le terme du bail. */
    public function test_the_range_is_clamped_to_the_lease_term(): void
    {
        [, $lease] = $this->ownerWithLease([
            'type' => 'etudiant',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
        ]);

        $result = app(RentScheduleGenerator::class)->generate(
            $lease,
            CarbonImmutable::parse('2020-01-01'),
            CarbonImmutable::parse('2030-01-01'),
        );

        $this->assertSame(9, $result['created']);
        $this->assertSame('2026-09-01', $result['from']);
        $this->assertSame('2027-05-01', $result['to']);
    }

    // ── Le point d'entrée ─────────────────────────────────────────────────────

    public function test_creating_a_lease_lays_down_its_schedule(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/leases', [
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'type' => 'nu',
            'start_date' => '2026-01-01',
            'monthly_rent' => 900,
            'charges' => 60,
            'payment_day' => 5,
            'statut' => 'actif',
        ]);

        $response->assertStatus(201)->assertJsonPath('schedule.count', 12);

        $this->assertSame(12, RentPayment::where('lease_id', $response->json('id'))->count());
    }

    public function test_schedule_generation_can_be_declined_at_creation(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/leases', [
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'type' => 'nu',
            'start_date' => '2026-01-01',
            'monthly_rent' => 900,
            'payment_day' => 5,
            'statut' => 'actif',
            'generate_schedule' => false,
        ]);

        $response->assertStatus(201)->assertJsonPath('schedule.count', 0);
    }

    public function test_generate_endpoint_completes_the_schedule(): void
    {
        [$user, $lease] = $this->ownerWithLease();

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/payments/generate", [
                'from' => '2026-01-01',
                'months' => 6,
            ])
            ->assertStatus(200)
            ->assertJsonPath('created', 6);
    }

    public function test_generate_endpoint_refuses_a_terminated_lease(): void
    {
        [$user, $lease] = $this->ownerWithLease();
        $lease->update(['statut' => LeaseStatus::Termine->value]);

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/payments/generate", ['months' => 3])
            ->assertStatus(409);
    }

    public function test_generate_endpoint_returns_403_for_non_owner(): void
    {
        [, $lease] = $this->ownerWithLease();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/leases/{$lease->id}/payments/generate", ['months' => 3])
            ->assertStatus(403);
    }

    // ── Pointage des règlements ───────────────────────────────────────────────

    public function test_pay_marks_a_payment_as_settled_today_by_default(): void
    {
        [$user, $lease] = $this->ownerWithLease();
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'paid_at' => null]);

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/payments/{$payment->id}/pay")
            ->assertStatus(200)
            ->assertJsonPath('status', 'paye');

        $this->assertSame(now()->toDateString(), $payment->refresh()->paid_at->toDateString());
    }

    public function test_unpay_puts_a_payment_back_in_the_due_column(): void
    {
        [$user, $lease] = $this->ownerWithLease();
        $payment = RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'paid_at' => now()->toDateString(),
            'period' => now()->startOfMonth()->toDateString(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/payments/{$payment->id}/unpay")
            ->assertStatus(200);

        $this->assertNull($payment->refresh()->paid_at);
    }

    public function test_bulk_pay_settles_the_selected_payments_only(): void
    {
        [$user, $lease] = $this->ownerWithLease();

        $first = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-01-01', 'paid_at' => null]);
        $second = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-02-01', 'paid_at' => null]);
        $untouched = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-03-01', 'paid_at' => null]);

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/payments/bulk-pay", [
                'ids' => [$first->id, $second->id],
                'paid_at' => '2026-02-10',
                'payment_method' => 'Virement',
            ])
            ->assertStatus(200)
            ->assertJsonPath('updated', 2);

        $this->assertNotNull($first->refresh()->paid_at);
        $this->assertNotNull($second->refresh()->paid_at);
        $this->assertNull($untouched->refresh()->paid_at);
    }

    /**
     * L'identifiant glissé dans la liste doit appartenir au bail visé : le
     * filtrage par la relation est ce qui l'empêche de pointer l'échéance d'un
     * autre bailleur.
     */
    public function test_bulk_pay_ignores_payments_from_another_lease(): void
    {
        [$user, $lease] = $this->ownerWithLease();
        [, $otherLease] = $this->ownerWithLease();

        $foreign = RentPayment::factory()->create(['lease_id' => $otherLease->id, 'paid_at' => null]);

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/payments/bulk-pay", ['ids' => [$foreign->id]])
            ->assertStatus(200)
            ->assertJsonPath('updated', 0);

        $this->assertNull($foreign->refresh()->paid_at);
    }

    // ── Résumé porté par la fiche du bail ─────────────────────────────────────

    public function test_the_lease_detail_reports_the_state_of_its_schedule(): void
    {
        [$user, $lease] = $this->ownerWithLease(['payment_day' => 5]);

        // Deux mois échus impayés, un mois réglé.
        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'amount_rent' => 800, 'amount_charges' => 50, 'paid_at' => null,
        ]);
        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'amount_rent' => 800, 'amount_charges' => 50, 'paid_at' => null,
        ]);
        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'amount_rent' => 800, 'amount_charges' => 50, 'paid_at' => now()->subMonths(3)->toDateString(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/leases/{$lease->id}");

        $response->assertStatus(200)
            ->assertJsonPath('schedule.count', 3)
            ->assertJsonPath('schedule.paid_count', 1)
            ->assertJsonPath('schedule.unpaid_count', 2)
            ->assertJsonPath('schedule.overdue_count', 2);

        $this->assertSame(1700.0, (float) $response->json('schedule.outstanding_amount'));
    }

    // ── Report des décisions du bail sur l'échéancier ─────────────────────────

    /**
     * L'échéancier étant posé d'avance, une révision qui ne le rattraperait pas
     * laisserait appeler l'ancien loyer pendant des mois.
     */
    public function test_revising_the_rent_reprices_the_unpaid_months_to_come(): void
    {
        [$user, $lease] = $this->ownerWithLease(['monthly_rent' => 800]);

        $future = RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->addMonth()->startOfMonth()->toDateString(),
            'amount_rent' => 800, 'paid_at' => null,
        ]);
        $settled = RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'amount_rent' => 800, 'paid_at' => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/revise-rent", ['irl_old' => 100, 'irl_new' => 110])
            ->assertStatus(200)
            ->assertJsonPath('new_rent', 880)
            ->assertJsonPath('repriced_payments', 1);

        $this->assertSame(880.0, (float) $future->refresh()->amount_rent);
        // Une quittance a été remise sur ce mois : son montant ne se réécrit pas.
        $this->assertSame(800.0, (float) $settled->refresh()->amount_rent);
    }

    /**
     * Un congé anticipé rend caduques les échéances postérieures : les laisser
     * ferait apparaître un impayé sur des mois que le locataire n'occupe plus.
     */
    public function test_terminating_drops_the_unpaid_months_after_the_end_date(): void
    {
        [$user, $lease] = $this->ownerWithLease(['start_date' => '2026-01-01', 'end_date' => '2029-01-01']);

        app(RentScheduleGenerator::class)->generate(
            $lease,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-01'),
        );

        $this->actingAs($user)
            ->postJson("/api/leases/{$lease->id}/terminate", ['end_date' => '2026-06-30'])
            ->assertStatus(200)
            ->assertJsonPath('dropped_payments', 6);

        $this->assertSame(6, $lease->payments()->count());
    }

    /** Les listes n'affichent pas l'échéancier : elles n'ont pas à le calculer. */
    public function test_the_lease_list_carries_no_schedule_summary(): void
    {
        [$user] = $this->ownerWithLease();

        $this->actingAs($user)
            ->getJson('/api/leases')
            ->assertStatus(200)
            ->assertJsonPath('data.0.schedule', null);
    }
}
