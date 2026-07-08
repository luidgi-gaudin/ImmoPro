<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Alerts\AlertScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlertScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // réinitialise l'horloge de test
        parent::tearDown();
    }

    private function scan(): int
    {
        return app(AlertScanner::class)->scan();
    }

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function leaseFor(User $owner, array $attributes = []): Lease
    {
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $owner->id]);

        return Lease::factory()->active()->create(array_merge([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
        ], $attributes));
    }

    // ── Loyer impayé (J+3) ──────────────────────────────────────────────────

    public function test_no_unpaid_alert_before_j_plus_3(): void
    {
        $owner = $this->owner();
        $lease = $this->leaseFor($owner, ['payment_day' => 1, 'start_date' => '2025-01-01', 'end_date' => '2028-01-01']);
        RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-03-01', 'paid_at' => null]);

        Carbon::setTestNow('2026-03-03'); // J+2 après l'échéance du 01/03
        $this->scan();

        $this->assertSame(0, Alert::where('type', AlertType::LoyerImpaye)->count());
    }

    public function test_unpaid_alert_fires_exactly_once_at_j_plus_3(): void
    {
        $owner = $this->owner();
        $lease = $this->leaseFor($owner, ['payment_day' => 1, 'start_date' => '2025-01-01', 'end_date' => '2028-01-01']);
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-03-01', 'paid_at' => null]);

        Carbon::setTestNow('2026-03-04'); // exactement J+3
        $this->scan();
        $this->scan(); // ré-exécution : ne doit pas dupliquer

        $alerts = Alert::where('type', AlertType::LoyerImpaye)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($owner->id, $alerts->first()->user_id);
        $this->assertSame(AlertSeverity::Critical, $alerts->first()->severity);
        $this->assertSame($payment->id, $alerts->first()->alertable_id);
    }

    public function test_unpaid_alert_auto_resolves_once_rent_is_paid(): void
    {
        $owner = $this->owner();
        $lease = $this->leaseFor($owner, ['payment_day' => 1, 'start_date' => '2025-01-01', 'end_date' => '2028-01-01']);
        $payment = RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-03-01', 'paid_at' => null]);

        Carbon::setTestNow('2026-03-04');
        $this->scan();
        $this->assertNull(Alert::where('type', AlertType::LoyerImpaye)->first()->resolved_at);

        $payment->update(['paid_at' => '2026-03-05']);
        $this->scan();

        $this->assertNotNull(Alert::where('type', AlertType::LoyerImpaye)->first()->resolved_at);
    }

    // ── Révision IRL (J-30) ─────────────────────────────────────────────────

    public function test_irl_revision_alert_fires_30_days_before_the_anniversary(): void
    {
        $owner = $this->owner();
        // Jamais révisé : la révision devient possible 1 an après le début du bail.
        $this->leaseFor($owner, [
            'start_date' => '2025-08-01',
            'end_date' => '2028-08-01',
            'last_rent_revision_at' => null,
        ]);

        Carbon::setTestNow('2026-07-01'); // J-31 : trop tôt
        $this->scan();
        $this->assertSame(0, Alert::where('type', AlertType::RevisionIrl)->count());

        Carbon::setTestNow('2026-07-02'); // J-30 pile
        $this->scan();
        $this->scan();
        $this->assertSame(1, Alert::where('type', AlertType::RevisionIrl)->count());
    }

    // ── Fin de bail (6 / 3 / 1 mois) ────────────────────────────────────────

    public function test_lease_end_alert_uses_the_nearest_milestone(): void
    {
        $owner = $this->owner();
        $this->leaseFor($owner, [
            'start_date' => '2023-09-01',
            'end_date' => '2026-09-01',
            'last_rent_revision_at' => '2026-06-01', // évite une alerte IRL parasite
        ]);

        // ~5 mois avant la fin → palier 6 mois (info)
        Carbon::setTestNow('2026-04-01');
        $this->scan();
        $six = Alert::where('type', AlertType::FinBail)->where('meta->months_before', 6)->first();
        $this->assertNotNull($six);
        $this->assertSame(AlertSeverity::Info, $six->severity);

        // ~2 mois avant → palier 3 mois (warning)
        Carbon::setTestNow('2026-07-01');
        $this->scan();
        $this->assertNotNull(Alert::where('type', AlertType::FinBail)->where('meta->months_before', 3)->first());

        // < 1 mois avant → palier 1 mois (critique)
        Carbon::setTestNow('2026-08-15');
        $this->scan();
        $one = Alert::where('type', AlertType::FinBail)->where('meta->months_before', 1)->first();
        $this->assertNotNull($one);
        $this->assertSame(AlertSeverity::Critical, $one->severity);

        // Trois paliers distincts franchis au fil du temps.
        $this->assertSame(3, Alert::where('type', AlertType::FinBail)->count());
    }

    // ── Expiration DPE (validité 10 ans) ────────────────────────────────────

    public function test_dpe_alert_warns_then_becomes_critical_without_duplicating(): void
    {
        $owner = $this->owner();
        $portfolio = Portfolio::factory()->create(['user_id' => $owner->id]);
        // DPE réalisé le 01/09/2016 → expiration le 01/09/2026.
        $property = Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'dpe_date' => '2016-09-01',
        ]);

        Carbon::setTestNow('2026-05-01'); // > 3 mois avant expiration : rien
        $this->scan();
        $this->assertSame(0, Alert::where('type', AlertType::DpeExpiration)->count());

        Carbon::setTestNow('2026-06-15'); // < 3 mois avant : avertissement
        $this->scan();
        $warning = Alert::where('type', AlertType::DpeExpiration)->first();
        $this->assertNotNull($warning);
        $this->assertSame(AlertSeverity::Warning, $warning->severity);
        $this->assertSame($property->id, $warning->alertable_id);

        Carbon::setTestNow('2026-10-01'); // DPE expiré : la même alerte passe en critique
        $this->scan();
        $this->assertSame(1, Alert::where('type', AlertType::DpeExpiration)->count());
        $this->assertSame(AlertSeverity::Critical, $warning->refresh()->severity);
    }
}
