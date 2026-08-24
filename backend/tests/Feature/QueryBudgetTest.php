<?php

namespace Tests\Feature;

use App\Enums\DocumentCategory;
use App\Enums\LeaseStatus;
use App\Models\Alert;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Garde-fou sur le seul chiffre qui détermine le temps de réponse d'une page.
 *
 * Un aller-retour SQL vers Supabase coûte ~135 ms, mesuré, quelle que soit la
 * requête ; le calcul PHP est négligeable devant cela. Le temps d'une page,
 * c'est donc son nombre de requêtes, et rien d'autre. Pour tenir la promesse de
 * 300 ms, le budget est de **deux** allers-retours : un pour authentifier, un
 * pour les données.
 *
 * Ces tests comptent les requêtes de *données*. L'authentification n'y figure
 * pas : `actingAs` court-circuite le garde, alors qu'en production la
 * résolution du jeton et le chargement du bailleur tiennent en une requête (voir
 * App\Models\PersonalAccessToken). Le budget vérifié ici est donc de 1, ce qui
 * correspond bien à 2 en conditions réelles.
 *
 * Une requête ajoutée quelque part fait tomber le test qui la concerne, avec le
 * SQL fautif à l'appui. C'est voulu : c'est le seul moment où l'on peut encore
 * choisir de la fusionner plutôt que de la subir.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function patrimoine(): User
    {
        $user = User::factory()->create();

        // Plusieurs exemplaires partout : une requête N+1 ne se voit pas sur une
        // seule ligne, c'est précisément ce qui la rend facile à introduire.
        $portfolios = Portfolio::factory()->count(3)->create(['user_id' => $user->id]);
        $tenants = Tenant::factory()->count(3)->create(['user_id' => $user->id]);

        foreach ($portfolios as $portfolio) {
            $properties = Property::factory()->count(2)->create(['portfolio_id' => $portfolio->id]);

            foreach ($properties as $property) {
                $lease = Lease::factory()->create([
                    'property_id' => $property->id,
                    'tenant_id' => $tenants->random()->id,
                    'statut' => LeaseStatus::Actif->value,
                ]);

                $lease->coTenants()->attach($tenants->random()->id, ['rent_share' => 300]);

                RentPayment::factory()->count(2)->create(['lease_id' => $lease->id]);

                Document::factory()->create([
                    'user_id' => $user->id,
                    'documentable_type' => Lease::class,
                    'documentable_id' => $lease->id,
                    'category' => DocumentCategory::BailSigne->value,
                ]);
            }
        }

        Alert::factory()->count(4)->create(['user_id' => $user->id]);

        return $user;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function countQueries(User $user, string $url): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->getJson($url)->assertOk();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [count($log), array_column($log, 'query')];
    }

    private function assertWithinBudget(User $user, string $url, int $budget = 1): void
    {
        [$count, $queries] = $this->countQueries($user, $url);

        $this->assertLessThanOrEqual(
            $budget,
            $count,
            sprintf(
                "%s exécute %d requêtes pour un budget de %d.\nChacune coûte ~135 ms sur Supabase.\n\n%s",
                $url,
                $count,
                $budget,
                implode("\n\n", $queries)
            )
        );
    }

    public function test_dashboard_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/dashboard');
    }

    public function test_report_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/reports/overview');
    }

    public function test_global_search_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/search?q=ap');
    }

    public function test_portfolio_list_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/portfolios');
    }

    public function test_tenant_list_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/tenants');
    }

    /** Le bien, le locataire et les colocataires voyagent avec les baux. */
    public function test_lease_list_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/leases');
    }

    public function test_alert_list_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/alerts');
    }

    public function test_document_list_holds_in_one_query(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/documents');
    }

    /**
     * Liste imbriquée : une requête résout le portefeuille depuis l'URL — c'est
     * elle qui permet de répondre 403 plutôt qu'une liste vide — et une seconde
     * ramène la page de biens. La réponse porte aussi le portefeuille lui-même,
     * ce qui évite à l'écran parent de le redemander dans un second appel HTTP.
     */
    public function test_property_list_holds_in_two_queries(): void
    {
        $user = $this->patrimoine();
        $portfolio = $user->portfolios()->firstOrFail();

        $this->assertWithinBudget($user, "/api/portfolios/{$portfolio->id}/properties", 2);
    }

    /**
     * Le catalogue des catégories ne touche pas la base : il vient de
     * l'énumération.
     */
    public function test_document_categories_touch_no_table(): void
    {
        $this->assertWithinBudget($this->patrimoine(), '/api/documents/categories', 0);
    }

    /**
     * Liste imbriquée : une requête pour résoudre le bail depuis l'URL, une
     * pour ses échéances. La policy, elle, ne coûte plus rien — le
     * propriétaire est ramené par la requête de résolution.
     */
    public function test_payment_list_holds_in_two_queries(): void
    {
        $user = $this->patrimoine();
        $lease = Lease::whereHas('property.portfolio', fn ($q) => $q->where('user_id', $user->id))->firstOrFail();

        $this->assertWithinBudget($user, "/api/leases/{$lease->id}/payments", 2);
    }
}
