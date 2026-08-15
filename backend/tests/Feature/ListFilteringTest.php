<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\Dpe;
use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Enums\PropertyType;
use App\Models\Alert;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le socle commun de recherche, filtrage, tri et pagination porté par
 * le trait App\Models\Concerns\Filterable.
 */
class ListFilteringTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    // ── Recherche ─────────────────────────────────────────────────────────────

    public function test_search_matches_across_declared_columns(): void
    {
        $user = $this->owner();
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Dupont', 'email' => 'a@example.com']);
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Martin', 'email' => 'dupont.pro@example.com']);
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Bernard', 'email' => 'c@example.com']);

        // Le terme touche un nom sur une fiche et un e-mail sur l'autre.
        $response = $this->actingAs($user)->getJson('/api/tenants?search=dupont');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_search_is_case_insensitive(): void
    {
        $user = $this->owner();
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Dupont']);

        $this->actingAs($user)->getJson('/api/tenants?search=DUPONT')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_treats_sql_wildcards_literally(): void
    {
        $user = $this->owner();
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Durand']);
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Dupont']);

        // Sans échappement, « % » se comporterait comme un joker et ramènerait
        // toute la table. Ici il doit être cherché littéralement : aucun nom
        // ne le contient.
        $this->actingAs($user)->getJson('/api/tenants?search=%')
            ->assertOk()->assertJsonCount(0, 'data');

        // Idem pour « _ », joker « un caractère quelconque » en SQL.
        $this->actingAs($user)->getJson('/api/tenants?search=Du_and')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_traverses_relations(): void
    {
        $user = $this->owner();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id, 'title' => 'Villa Bleue']);
        $other = Property::factory()->create(['portfolio_id' => $portfolio->id, 'title' => 'Studio Gris']);

        $tenant = Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Lefevre']);

        Lease::factory()->create(['property_id' => $property->id, 'tenant_id' => $tenant->id]);
        Lease::factory()->create(['property_id' => $other->id, 'tenant_id' => $tenant->id]);

        // Un bail n'a pas de titre : la recherche doit passer par le bien.
        $this->actingAs($user)->getJson('/api/leases?search=villa')
            ->assertOk()->assertJsonCount(1, 'data');

        // Et par le locataire.
        $this->actingAs($user)->getJson('/api/leases?search=lefevre')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    // ── Filtres ───────────────────────────────────────────────────────────────

    public function test_property_filters_combine(): void
    {
        $user = $this->owner();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);

        Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'property_type' => PropertyType::Maison->value,
            'dpe' => Dpe::A->value,
        ]);
        Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'property_type' => PropertyType::Maison->value,
            'dpe' => Dpe::G->value,
        ]);
        Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'property_type' => PropertyType::Appartement->value,
            'dpe' => Dpe::A->value,
        ]);

        $url = "/api/portfolios/{$portfolio->id}/properties";

        $this->actingAs($user)->getJson("{$url}?property_type=maison")
            ->assertOk()->assertJsonCount(2, 'data');

        // Deux filtres se cumulent en ET, pas en OU.
        $this->actingAs($user)->getJson("{$url}?property_type=maison&dpe=A")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_property_rented_filter_relies_on_active_lease(): void
    {
        $user = $this->owner();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $rented = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $vacant = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $endedLease = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        Lease::factory()->create([
            'property_id' => $rented->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Actif->value,
        ]);
        // Un bail terminé ne rend pas le bien occupé.
        Lease::factory()->create([
            'property_id' => $endedLease->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Termine->value,
        ]);

        $url = "/api/portfolios/{$portfolio->id}/properties";

        $this->actingAs($user)->getJson("{$url}?loue=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rented->id);

        $this->actingAs($user)->getJson("{$url}?loue=0")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_lease_status_filter(): void
    {
        $user = $this->owner();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        Lease::factory()->count(2)->create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Actif->value,
            'type' => LeaseType::Nu->value,
        ]);
        Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'statut' => LeaseStatus::Termine->value,
            'type' => LeaseType::Meuble->value,
        ]);

        $this->actingAs($user)->getJson('/api/leases?statut=actif')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($user)->getJson('/api/leases?type=meuble')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_empty_filter_value_does_not_restrict(): void
    {
        $user = $this->owner();
        Tenant::factory()->count(3)->create(['user_id' => $user->id]);

        // « Tous » dans l'interface envoie une valeur vide : la liste reste entière.
        $this->actingAs($user)->getJson('/api/tenants?search=&sort=')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    // ── Tri ───────────────────────────────────────────────────────────────────

    public function test_sort_and_direction_are_applied(): void
    {
        $user = $this->owner();
        foreach (['Zola', 'Auclair', 'Marchand'] as $name) {
            Tenant::factory()->create(['user_id' => $user->id, 'last_name' => $name]);
        }

        $this->actingAs($user)->getJson('/api/tenants?sort=last_name&direction=asc')
            ->assertOk()->assertJsonPath('data.0.last_name', 'Auclair');

        $this->actingAs($user)->getJson('/api/tenants?sort=last_name&direction=desc')
            ->assertOk()->assertJsonPath('data.0.last_name', 'Zola');
    }

    public function test_unknown_sort_column_falls_back_to_default(): void
    {
        $user = $this->owner();
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Auclair']);
        Tenant::factory()->create(['user_id' => $user->id, 'last_name' => 'Zola']);

        // Une colonne hors liste blanche est ignorée, sans erreur : une URL
        // bricolée ne doit pas casser l'écran, ni atteindre le SQL.
        $this->actingAs($user)->getJson('/api/tenants?sort=password')
            ->assertOk()
            ->assertJsonPath('data.0.last_name', 'Auclair');
    }

    public function test_sort_injection_attempt_is_ignored(): void
    {
        $user = $this->owner();
        Tenant::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/tenants?sort='.urlencode('last_name; drop table tenants--'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // La table est toujours là.
        $this->assertDatabaseCount('tenants', 2);
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    public function test_per_page_is_honoured_and_capped(): void
    {
        $user = $this->owner();
        Tenant::factory()->count(12)->create(['user_id' => $user->id]);

        $this->actingAs($user)->getJson('/api/tenants?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 3);

        // Au-delà du plafond, la valeur est ramenée à 100 : sans cela, un
        // per_page démesuré ferait charger toute la table en mémoire.
        $this->actingAs($user)->getJson('/api/tenants?per_page=100000')
            ->assertOk()
            ->assertJsonPath('per_page', 100);

        // Une valeur absurde retombe sur le défaut plutôt que d'échouer.
        $this->actingAs($user)->getJson('/api/tenants?per_page=-3')
            ->assertOk()
            ->assertJsonPath('per_page', 15);
    }

    public function test_pagination_keeps_filters_in_links(): void
    {
        $user = $this->owner();
        Tenant::factory()->count(8)->create(['user_id' => $user->id, 'last_name' => 'Dupont']);
        Tenant::factory()->count(8)->create(['user_id' => $user->id, 'last_name' => 'Martin']);

        $response = $this->actingAs($user)->getJson('/api/tenants?search=dupont&per_page=5');

        $response->assertOk()->assertJsonPath('total', 8);

        // Sans withQueryString(), la page 2 perdrait la recherche et
        // réafficherait les 16 fiches.
        $this->assertStringContainsString('search=dupont', $response->json('next_page_url'));
    }

    public function test_second_page_returns_remaining_rows(): void
    {
        $user = $this->owner();
        Tenant::factory()->count(7)->create(['user_id' => $user->id]);

        $this->actingAs($user)->getJson('/api/tenants?per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('current_page', 2);
    }

    // ── Statut des loyers, calculé et non stocké ──────────────────────────────

    public function test_rent_payment_status_filter(): void
    {
        $user = $this->owner();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'payment_day' => 1,
        ]);

        // Payée : peu importe la période.
        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->subMonths(2)->startOfMonth(),
            'paid_at' => now()->subMonths(2),
        ]);
        // Impayée sur un mois révolu : en retard.
        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->subMonth()->startOfMonth(),
            'paid_at' => null,
        ]);
        // Impayée sur un mois à venir : en attente.
        RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'period' => now()->addMonth()->startOfMonth(),
            'paid_at' => null,
        ]);

        $url = "/api/leases/{$lease->id}/payments";

        $paid = $this->actingAs($user)->getJson("{$url}?statut=paye")->assertOk();
        $paid->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'paye');

        $late = $this->actingAs($user)->getJson("{$url}?statut=en_retard")->assertOk();
        $late->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'en_retard');

        $pending = $this->actingAs($user)->getJson("{$url}?statut=en_attente")->assertOk();
        $pending->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'en_attente');
    }

    public function test_rent_payment_year_filter(): void
    {
        $user = $this->owner();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $lease = Lease::factory()->create(['property_id' => $property->id, 'tenant_id' => $tenant->id]);

        RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2025-03-01']);
        RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2025-12-01']);
        RentPayment::factory()->create(['lease_id' => $lease->id, 'period' => '2026-01-01']);

        $this->actingAs($user)->getJson("/api/leases/{$lease->id}/payments?annee=2025")
            ->assertOk()->assertJsonCount(2, 'data');

        // Décembre est inclus : la borne haute couvre bien la fin d'année.
        $this->actingAs($user)->getJson("/api/leases/{$lease->id}/payments?annee=2026")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // ── Alertes ───────────────────────────────────────────────────────────────

    public function test_alerts_are_ordered_by_business_severity(): void
    {
        $user = $this->owner();

        foreach ([AlertSeverity::Info, AlertSeverity::Critical, AlertSeverity::Warning] as $severity) {
            Alert::factory()->create([
                'user_id' => $user->id,
                'severity' => $severity->value,
                'resolved_at' => null,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/alerts')->assertOk();

        // L'ordre alphabétique donnerait critical, info, warning : « info »
        // remonterait avant « warning », ce qui n'a aucun sens pour un bailleur.
        $this->assertSame(
            ['critical', 'warning', 'info'],
            array_column($response->json('data'), 'severity')
        );
    }

    public function test_alerts_unread_filter_yields_exact_count(): void
    {
        $user = $this->owner();
        Alert::factory()->count(2)->create([
            'user_id' => $user->id,
            'resolved_at' => null,
            'read_at' => null,
        ]);
        Alert::factory()->count(3)->create([
            'user_id' => $user->id,
            'resolved_at' => null,
            'read_at' => now(),
        ]);

        // La pastille de notification lit « total » sans rapatrier les alertes.
        $this->actingAs($user)->getJson('/api/alerts?unread=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_alerts_keep_flat_pagination_envelope(): void
    {
        $user = $this->owner();
        Alert::factory()->count(3)->create(['user_id' => $user->id, 'resolved_at' => null]);

        $response = $this->actingAs($user)->getJson('/api/alerts?per_page=2');

        // Même enveloppe que les autres listes : data + champs à plat, et non
        // l'enveloppe meta/links d'une ResourceCollection.
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'per_page', 'total']);
    }
}
