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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bailleurAvecPatrimoine(): User
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $loue = Property::factory()->create(['portfolio_id' => $portfolio->id, 'is_rented' => true]);
        Property::factory()->create(['portfolio_id' => $portfolio->id, 'is_rented' => false]);

        Lease::factory()->create([
            'property_id' => $loue->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Actif->value,
            'monthly_rent' => 900,
        ]);
        Lease::factory()->create([
            'property_id' => $loue->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Termine->value,
            'monthly_rent' => 700,
        ]);

        return $user;
    }

    public function test_returns_counts_over_the_whole_estate(): void
    {
        $user = $this->bailleurAvecPatrimoine();

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.portfolios', 1)
            ->assertJsonPath('counts.properties', 2)
            ->assertJsonPath('counts.occupied_properties', 1)
            ->assertJsonPath('counts.tenants', 1)
            ->assertJsonPath('counts.leases', 2)
            ->assertJsonPath('counts.active_leases', 1)
            // Seuls les baux actifs alimentent le loyer attendu : le bail
            // terminé à 700 € ne doit pas être compté.
            ->assertJsonPath('counts.monthly_rent_expected', 900);
    }

    public function test_alerts_are_sorted_by_severity_and_counted(): void
    {
        $user = User::factory()->create();

        Alert::factory()->create(['user_id' => $user->id, 'severity' => AlertSeverity::Info->value, 'resolved_at' => null, 'read_at' => null]);
        Alert::factory()->create(['user_id' => $user->id, 'severity' => AlertSeverity::Critical->value, 'resolved_at' => null, 'read_at' => null]);
        Alert::factory()->create(['user_id' => $user->id, 'severity' => AlertSeverity::Warning->value, 'resolved_at' => null, 'read_at' => now()]);
        // Résolue : ni affichée, ni comptée.
        Alert::factory()->create(['user_id' => $user->id, 'severity' => AlertSeverity::Critical->value, 'resolved_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(
            ['critical', 'warning', 'info'],
            array_column($response->json('alerts.items'), 'severity')
        );
        $response->assertJsonPath('alerts.unread_count', 2);
    }

    public function test_never_leaks_another_landlords_data(): void
    {
        $user = User::factory()->create();
        $this->bailleurAvecPatrimoine(); // patrimoine d'un autre bailleur

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.portfolios', 0)
            ->assertJsonPath('counts.properties', 0)
            ->assertJsonPath('counts.leases', 0)
            ->assertJsonPath('counts.tenants', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    /**
     * Le point d'entrée n'existe que pour épargner des allers-retours vers une
     * base distante : si le nombre de requêtes repart à la hausse, il perd sa
     * raison d'être. Ce test sert de garde-fou.
     */
    public function test_stays_within_its_query_budget(): void
    {
        $user = $this->bailleurAvecPatrimoine();

        DB::enableQueryLog();
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            8,
            $count,
            "Le tableau de bord exécute {$count} requêtes. Chacune coûte environ 110 ms sur Supabase : regrouper avant d'en ajouter."
        );
    }
}
