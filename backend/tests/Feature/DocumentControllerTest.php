<?php

namespace Tests\Feature;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /** @return array{0: User, 1: Portfolio, 2: Property, 3: Tenant, 4: Lease} */
    private function bailleur(): array
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
        ]);

        return [$user, $portfolio, $property, $tenant, $lease];
    }

    private function pdf(string $name = 'bail-signe.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    public function test_store_attaches_a_document_to_a_lease(): void
    {
        [$user, , , , $lease] = $this->bailleur();

        $response = $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'lease',
            'documentable_id' => $lease->id,
            'category' => DocumentCategory::BailSigne->value,
            'file' => $this->pdf(),
            'issued_on' => '2026-01-15',
        ]);

        $response->assertCreated()
            ->assertJsonPath('category', 'bail_signe')
            ->assertJsonPath('category_label', 'Bail signé')
            ->assertJsonPath('attached_to.type', 'lease')
            ->assertJsonPath('attached_to.id', $lease->id);

        $document = Document::firstOrFail();

        $this->assertSame($user->id, $document->user_id);
        $this->assertSame(Lease::class, $document->documentable_type);
        Storage::disk('documents')->assertExists($document->path);
    }

    /**
     * Le nom d'origine ne doit jamais servir de nom de fichier : il peut porter
     * des séparateurs de chemin.
     */
    public function test_store_does_not_use_the_client_filename_as_path(): void
    {
        [$user, , , , $lease] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'lease',
            'documentable_id' => $lease->id,
            'category' => DocumentCategory::BailSigne->value,
            'file' => $this->pdf('../../etc/passwd.pdf'),
        ])->assertCreated();

        $document = Document::firstOrFail();

        $this->assertStringNotContainsString('..', $document->path);
        $this->assertStringStartsWith($user->id.'/lease/', $document->path);
    }

    /** La date de fin de validité se déduit de la catégorie quand elle existe. */
    public function test_expiry_is_derived_from_the_category(): void
    {
        [$user, , $property] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'property',
            'documentable_id' => $property->id,
            'category' => DocumentCategory::Dpe->value,
            'file' => $this->pdf('dpe.pdf'),
            'issued_on' => '2026-03-01',
        ])
            ->assertCreated()
            // Dix ans, depuis la réforme de 2021.
            ->assertJsonPath('expires_on', '2036-03-01');
    }

    public function test_a_category_that_makes_no_sense_for_the_entity_is_rejected(): void
    {
        [$user, $portfolio] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'portfolio',
            'documentable_id' => $portfolio->id,
            // Une pièce d'identité se range dans un dossier locataire.
            'category' => DocumentCategory::PieceIdentite->value,
            'file' => $this->pdf(),
        ])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_cannot_attach_a_document_to_another_landlords_entity(): void
    {
        [$user] = $this->bailleur();
        [, , , , $autreBail] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'lease',
            'documentable_id' => $autreBail->id,
            'category' => DocumentCategory::BailSigne->value,
            'file' => $this->pdf(),
        ])->assertStatus(422)->assertJsonValidationErrors('documentable_id');
    }

    public function test_an_executable_disguised_as_a_document_is_refused(): void
    {
        [$user, , , , $lease] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'lease',
            'documentable_id' => $lease->id,
            'category' => DocumentCategory::BailSigne->value,
            'file' => UploadedFile::fake()->create('charge.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_index_lists_only_own_documents_and_filters_by_entity(): void
    {
        [$user, , $property, , $lease] = $this->bailleur();
        [$autre, , , , $autreBail] = $this->bailleur();

        Document::factory()->create([
            'user_id' => $user->id,
            'documentable_type' => Lease::class,
            'documentable_id' => $lease->id,
        ]);
        Document::factory()->create([
            'user_id' => $user->id,
            'documentable_type' => Property::class,
            'documentable_id' => $property->id,
            'category' => DocumentCategory::Dpe->value,
        ]);
        Document::factory()->create([
            'user_id' => $autre->id,
            'documentable_type' => Lease::class,
            'documentable_id' => $autreBail->id,
        ]);

        $this->actingAs($user)->getJson('/api/documents')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->actingAs($user)->getJson('/api/documents?documentable_type=property&documentable_id='.$property->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.category', 'dpe');
    }

    public function test_index_carries_the_label_of_the_attached_entity(): void
    {
        [$user, , $property] = $this->bailleur();

        Document::factory()->create([
            'user_id' => $user->id,
            'documentable_type' => Property::class,
            'documentable_id' => $property->id,
            'category' => DocumentCategory::Dpe->value,
        ]);

        $this->actingAs($user)->getJson('/api/documents')
            ->assertOk()
            ->assertJsonPath('data.0.attached_to.label', $property->title);
    }

    public function test_expiration_filter_isolates_expired_documents(): void
    {
        [$user, , , , $lease] = $this->bailleur();

        Document::factory()->expired()->create([
            'user_id' => $user->id,
            'documentable_type' => Lease::class,
            'documentable_id' => $lease->id,
        ]);
        Document::factory()->create([
            'user_id' => $user->id,
            'documentable_type' => Lease::class,
            'documentable_id' => $lease->id,
        ]);

        $this->actingAs($user)->getJson('/api/documents?expiration=expire')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.is_expired', true);
    }

    public function test_download_is_refused_to_another_landlord(): void
    {
        [$user, , , , $lease] = $this->bailleur();
        [$intrus] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'lease',
            'documentable_id' => $lease->id,
            'category' => DocumentCategory::BailSigne->value,
            'file' => $this->pdf(),
        ])->assertCreated();

        $document = Document::firstOrFail();

        $this->actingAs($intrus)->get("/api/documents/{$document->id}/download")->assertForbidden();
        $this->actingAs($user)->get("/api/documents/{$document->id}/download")->assertOk();
    }

    public function test_preview_requires_a_valid_signature(): void
    {
        [$user, , , , $lease] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'lease',
            'documentable_id' => $lease->id,
            'category' => DocumentCategory::BailSigne->value,
            'file' => $this->pdf(),
        ])->assertCreated();

        $document = Document::firstOrFail();
        $signed = $this->actingAs($user)->getJson('/api/documents')->json('data.0.preview_url');

        $this->assertNotNull($signed);

        // Le lien signé fonctionne sans jeton : c'est tout son intérêt pour une
        // balise <img> ou <iframe>.
        $this->get($signed)->assertOk();

        // Modifier le moindre paramètre invalide la signature.
        $this->get("/api/documents/{$document->id}/preview?as=999")->assertForbidden();
    }

    public function test_destroy_keeps_the_file_so_the_deletion_stays_reversible(): void
    {
        [$user, , , , $lease] = $this->bailleur();

        $this->actingAs($user)->postJson('/api/documents', [
            'documentable_type' => 'lease',
            'documentable_id' => $lease->id,
            'category' => DocumentCategory::BailSigne->value,
            'file' => $this->pdf(),
        ])->assertCreated();

        $document = Document::firstOrFail();

        $this->actingAs($user)->deleteJson("/api/documents/{$document->id}")->assertOk();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
        Storage::disk('documents')->assertExists($document->path);
    }

    public function test_categories_are_served_without_touching_the_database(): void
    {
        [$user] = $this->bailleur();

        $response = $this->actingAs($user)->getJson('/api/documents/categories');

        $response->assertOk()
            ->assertJsonPath('categories.0.value', 'bail_signe')
            ->assertJsonPath('categories.0.attachable_to.0', 'lease');

        $this->assertNotEmpty($response->json('accepted_mimes'));
    }

    public function test_documents_require_authentication(): void
    {
        $this->getJson('/api/documents')->assertUnauthorized();
        $this->postJson('/api/documents', [])->assertUnauthorized();
    }
}
