<?php

namespace Tests\Feature;

use App\Enums\DocumentCategory;
use App\Enums\LeaseStatus;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Espace locataire.
 *
 * Les tests tournent sur SQLite, qui n'a pas de Row Level Security : ils
 * vérifient donc l'isolation telle que le contrôleur l'applique. C'est
 * exactement l'intérêt — un contrôleur qui ne se bornerait que par la policy
 * serait vert ici et ouvert en local.
 */
class TenantSpaceControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{landlord: User, account: User, profile: Tenant, lease: Lease, property: Property} */
    private function scene(): array
    {
        $landlord = User::factory()->create();
        $account = User::factory()->tenant()->create();

        $portfolio = Portfolio::factory()->create(['user_id' => $landlord->id]);
        $property = Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'title' => 'Studio Bastille',
            'address' => '12 rue de la Roquette',
            'address_complement' => 'Bâtiment B',
            'floor' => '3',
            'apartment_number' => '31',
            'city' => 'Paris',
            'postal_code' => '75011',
        ]);

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'account_user_id' => $account->id,
        ]);

        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => $profile->id,
            'statut' => LeaseStatus::Actif->value,
        ]);

        return compact('landlord', 'account', 'profile', 'lease', 'property');
    }

    /* ----------------------------------------------------------------------
     | Accès
     |----------------------------------------------------------------------*/

    public function test_the_space_requires_authentication(): void
    {
        $this->getJson('/api/tenant-space')->assertStatus(401);
    }

    public function test_a_landlord_has_no_business_here(): void
    {
        $scene = $this->scene();

        $this->actingAs($scene['landlord'])->getJson('/api/tenant-space')->assertStatus(403);
    }

    /**
     * Cas fréquent et déroutant : le compte existe, mais aucun bailleur n'a
     * encore inscrit cette adresse. Un écran vide sans un mot ressemble à une
     * panne.
     */
    public function test_an_unlinked_account_is_told_why_it_sees_nothing(): void
    {
        $response = $this->actingAs(User::factory()->tenant()->create())
            ->getJson('/api/tenant-space')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.leases');

        $this->assertNotNull($response->json('message'));
    }

    /* ----------------------------------------------------------------------
     | Vue d'ensemble
     |----------------------------------------------------------------------*/

    public function test_the_overview_shows_the_lease_and_the_lodging(): void
    {
        $scene = $this->scene();

        $this->actingAs($scene['account'])
            ->getJson('/api/tenant-space')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.leases')
            ->assertJsonPath('data.leases.0.id', $scene['lease']->id)
            ->assertJsonPath('data.leases.0.property.title', 'Studio Bastille')
            ->assertJsonPath(
                'data.leases.0.property.full_address',
                '12 rue de la Roquette, Bâtiment B, Appt 31 étage 3, 75011 Paris'
            );
    }

    /** Le parc du bailleur ne regarde pas le locataire. */
    public function test_only_the_leases_of_the_account_are_returned(): void
    {
        $scene = $this->scene();

        $otherProperty = Property::factory()->create([
            'portfolio_id' => $scene['property']->portfolio_id,
        ]);
        $otherTenant = Tenant::factory()->create(['user_id' => $scene['landlord']->id]);

        Lease::factory()->create([
            'property_id' => $otherProperty->id,
            'tenant_id' => $otherTenant->id,
        ]);

        $this->actingAs($scene['account'])
            ->getJson('/api/tenant-space')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.leases');
    }

    /** Un colocataire qui ne verrait pas son bail n'aurait pas d'espace du tout. */
    public function test_a_co_tenant_sees_the_lease_too(): void
    {
        $scene = $this->scene();

        $mate = User::factory()->tenant()->create();
        $mateProfile = Tenant::factory()->create([
            'user_id' => $scene['landlord']->id,
            'account_user_id' => $mate->id,
        ]);

        $scene['lease']->coTenants()->attach($mateProfile->id);

        $this->actingAs($mate)
            ->getJson('/api/tenant-space')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.leases')
            ->assertJsonPath('data.leases.0.id', $scene['lease']->id);
    }

    public function test_the_summary_counts_what_is_overdue(): void
    {
        $scene = $this->scene();

        RentPayment::factory()->create([
            'lease_id' => $scene['lease']->id,
            'period' => now()->startOfMonth()->subMonth()->toDateString(),
            'amount_rent' => 800,
            'amount_charges' => 50,
            'paid_at' => null,
        ]);

        RentPayment::factory()->create([
            'lease_id' => $scene['lease']->id,
            'period' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'amount_rent' => 800,
            'amount_charges' => 50,
            'paid_at' => now()->subMonths(2)->toDateString(),
        ]);

        $this->actingAs($scene['account'])
            ->getJson('/api/tenant-space')
            ->assertStatus(200)
            ->assertJsonPath('data.summary.overdue_count', 1)
            // json_encode ramène 850.0 à 850 : la comparaison stricte
            // d'assertJsonPath distinguerait les deux.
            ->assertJsonPath('data.summary.overdue_amount', 850)
            ->assertJsonPath('data.summary.active_leases', 1);
    }

    /**
     * Le point qui rendait l'écran alarmant à tort.
     *
     * L'échéancier est engendré des mois à l'avance. Additionner toutes les
     * lignes non réglées annonçait une dette d'un an de loyers à quelqu'un qui
     * ne devait rien.
     */
    public function test_future_instalments_are_not_counted_as_a_debt(): void
    {
        $scene = $this->scene();

        foreach ([1, 2, 3] as $ahead) {
            RentPayment::factory()->create([
                'lease_id' => $scene['lease']->id,
                'period' => now()->startOfMonth()->addMonths($ahead)->toDateString(),
                'amount_rent' => 800,
                'amount_charges' => 50,
                'paid_at' => null,
            ]);
        }

        $this->actingAs($scene['account'])
            ->getJson('/api/tenant-space')
            ->assertStatus(200)
            ->assertJsonPath('data.summary.overdue_count', 0)
            ->assertJsonPath('data.summary.overdue_amount', 0)
            ->assertJsonPath('data.summary.upcoming_count', 3);
    }

    /** La prochaine échéance est la plus proche de celles qui restent à venir. */
    public function test_the_next_instalment_is_the_nearest_upcoming_one(): void
    {
        $scene = $this->scene();

        RentPayment::factory()->create([
            'lease_id' => $scene['lease']->id,
            'period' => now()->startOfMonth()->addMonths(3)->toDateString(),
            'amount_rent' => 800,
            'amount_charges' => 50,
            'paid_at' => null,
        ]);

        RentPayment::factory()->create([
            'lease_id' => $scene['lease']->id,
            'period' => now()->startOfMonth()->addMonth()->toDateString(),
            'amount_rent' => 800,
            'amount_charges' => 50,
            'paid_at' => null,
        ]);

        $this->actingAs($scene['account'])
            ->getJson('/api/tenant-space')
            ->assertStatus(200)
            ->assertJsonPath(
                'data.summary.next_due.period',
                now()->startOfMonth()->addMonth()->toDateString()
            );
    }

    /**
     * Le résumé ne doit pas coûter une requête par échéance.
     *
     * L'accesseur qui décide du statut lit le jour de paiement du bail : sans
     * la relation inverse, chaque ligne le recharge.
     */
    public function test_the_summary_does_not_query_once_per_instalment(): void
    {
        $scene = $this->scene();

        RentPayment::factory()->count(12)->create(['lease_id' => $scene['lease']->id]);

        DB::enableQueryLog();

        $this->actingAs($scene['account'])->getJson('/api/tenant-space')->assertStatus(200);

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        // Authentification, dossiers, baux, biens, échéances : le compte reste
        // borné, et surtout indépendant du nombre d'échéances.
        $this->assertLessThan(12, $queries, "Le résumé a déclenché {$queries} requêtes.");
    }

    /* ----------------------------------------------------------------------
     | Fiche du bail
     |----------------------------------------------------------------------*/

    public function test_the_lease_sheet_lists_the_payment_history(): void
    {
        $scene = $this->scene();

        RentPayment::factory()->count(3)->create(['lease_id' => $scene['lease']->id]);

        $this->actingAs($scene['account'])
            ->getJson("/api/tenant-space/leases/{$scene['lease']->id}")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.payments');
    }

    /**
     * Un identifiant étranger donne 404, pas 403 : un 403 confirmerait que le
     * bail existe.
     */
    public function test_a_foreign_lease_is_not_found(): void
    {
        $scene = $this->scene();
        $other = $this->scene();

        $this->actingAs($scene['account'])
            ->getJson("/api/tenant-space/leases/{$other['lease']->id}")
            ->assertStatus(404);
    }

    /* ----------------------------------------------------------------------
     | Documents
     |----------------------------------------------------------------------*/

    public function test_the_documents_of_the_file_are_listed(): void
    {
        $scene = $this->scene();

        Document::factory()->create([
            'user_id' => $scene['landlord']->id,
            'documentable_type' => Lease::class,
            'documentable_id' => $scene['lease']->id,
            'category' => DocumentCategory::BailSigne->value,
        ]);

        Document::factory()->create([
            'user_id' => $scene['landlord']->id,
            'documentable_type' => Tenant::class,
            'documentable_id' => $scene['profile']->id,
            'category' => DocumentCategory::PieceIdentite->value,
        ]);

        $this->actingAs($scene['account'])
            ->getJson('/api/tenant-space/documents')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** Un mandat de gestion ou une taxe foncière regarde le bailleur seul. */
    public function test_the_management_documents_stay_out_of_reach(): void
    {
        $scene = $this->scene();

        Document::factory()->create([
            'user_id' => $scene['landlord']->id,
            'documentable_type' => Portfolio::class,
            'documentable_id' => $scene['property']->portfolio_id,
            'category' => DocumentCategory::Mandat->value,
        ]);

        $this->actingAs($scene['account'])
            ->getJson('/api/tenant-space/documents')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_a_document_from_another_file_is_not_downloadable(): void
    {
        $scene = $this->scene();
        $other = $this->scene();

        $foreign = Document::factory()->create([
            'user_id' => $other['landlord']->id,
            'documentable_type' => Lease::class,
            'documentable_id' => $other['lease']->id,
        ]);

        $this->actingAs($scene['account'])
            ->get("/api/tenant-space/documents/{$foreign->id}/download")
            ->assertStatus(404);
    }

    /* ----------------------------------------------------------------------
     | Dépôt de pièces
     |----------------------------------------------------------------------*/

    public function test_the_tenant_uploads_their_insurance_certificate(): void
    {
        Storage::fake('documents');

        $scene = $this->scene();

        $this->actingAs($scene['account'])
            ->postJson('/api/tenant-space/documents', [
                'documentable_type' => 'lease',
                'documentable_id' => $scene['lease']->id,
                'category' => DocumentCategory::AttestationAssurance->value,
                'file' => UploadedFile::fake()->create('assurance.pdf', 200, 'application/pdf'),
            ])
            ->assertStatus(201);

        $document = Document::sole();

        // Le bailleur reste propriétaire : autrement la pièce échapperait à sa
        // policy et deviendrait invisible dans le dossier où elle a été versée.
        $this->assertSame($scene['landlord']->id, $document->user_id);
        Storage::disk('documents')->assertExists($document->path);
    }

    /**
     * C'est le bailleur qui émet les quittances : en laisser déposer une
     * reviendrait à accepter au dossier un justificatif de paiement écrit par
     * le locataire lui-même.
     */
    public function test_a_receipt_cannot_be_uploaded_by_the_tenant(): void
    {
        Storage::fake('documents');

        $scene = $this->scene();

        $this->actingAs($scene['account'])
            ->postJson('/api/tenant-space/documents', [
                'documentable_type' => 'lease',
                'documentable_id' => $scene['lease']->id,
                'category' => DocumentCategory::Quittance->value,
                'file' => UploadedFile::fake()->create('quittance.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    public function test_a_document_cannot_be_grafted_onto_another_file(): void
    {
        Storage::fake('documents');

        $scene = $this->scene();
        $other = $this->scene();

        $this->actingAs($scene['account'])
            ->postJson('/api/tenant-space/documents', [
                'documentable_type' => 'lease',
                'documentable_id' => $other['lease']->id,
                'category' => DocumentCategory::AttestationAssurance->value,
                'file' => UploadedFile::fake()->create('assurance.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documentable_id']);

        $this->assertDatabaseCount('documents', 0);
    }

    /** Ni le bien ni le portefeuille : ce sont les dossiers du bailleur. */
    public function test_a_property_is_not_an_acceptable_target(): void
    {
        Storage::fake('documents');

        $scene = $this->scene();

        $this->actingAs($scene['account'])
            ->postJson('/api/tenant-space/documents', [
                'documentable_type' => 'property',
                'documentable_id' => $scene['property']->id,
                'category' => DocumentCategory::Autre->value,
                'file' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documentable_type']);
    }

    /** Le bailleur retrouve dans son propre écran la pièce que le locataire dépose. */
    public function test_the_landlord_sees_what_the_tenant_deposited(): void
    {
        Storage::fake('documents');

        $scene = $this->scene();

        $this->actingAs($scene['account'])
            ->postJson('/api/tenant-space/documents', [
                'documentable_type' => 'lease',
                'documentable_id' => $scene['lease']->id,
                'category' => DocumentCategory::AttestationAssurance->value,
                'file' => UploadedFile::fake()->create('assurance.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        $this->actingAs($scene['landlord'])
            ->getJson('/api/documents')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_the_uploadable_categories_exclude_what_the_landlord_issues(): void
    {
        $scene = $this->scene();

        $values = array_column(
            $this->actingAs($scene['account'])
                ->getJson('/api/tenant-space/documents/categories')
                ->assertStatus(200)
                ->json('categories'),
            'value'
        );

        $this->assertContains(DocumentCategory::AttestationAssurance->value, $values);
        $this->assertNotContains(DocumentCategory::Quittance->value, $values);
        $this->assertNotContains(DocumentCategory::Mandat->value, $values);
    }
}
