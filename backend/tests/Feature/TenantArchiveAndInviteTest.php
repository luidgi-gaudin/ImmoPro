<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TenantArchiveAndInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
    }

    /* ----------------------------------------------------------------------
     | Archivage
     |----------------------------------------------------------------------*/

    public function test_an_archived_file_leaves_the_working_list(): void
    {
        $landlord = User::factory()->create();
        $kept = Tenant::factory()->create(['user_id' => $landlord->id]);
        $closed = Tenant::factory()->create(['user_id' => $landlord->id]);

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$closed->id}/archive")
            ->assertStatus(200)
            ->assertJsonPath('data.is_archived', true);

        $ids = array_column(
            $this->actingAs($landlord)->getJson('/api/tenants')->assertStatus(200)->json('data'),
            'id'
        );

        $this->assertSame([$kept->id], $ids);
    }

    /** L'archivage n'est pas une suppression : la ligne reste en base. */
    public function test_an_archived_file_is_still_there(): void
    {
        $landlord = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $landlord->id]);

        $this->actingAs($landlord)->postJson("/api/tenants/{$tenant->id}/archive")->assertStatus(200);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'deleted_at' => null]);
        $this->assertNotNull($tenant->refresh()->archived_at);
    }

    public function test_archived_files_can_be_asked_for(): void
    {
        $landlord = User::factory()->create();
        Tenant::factory()->create(['user_id' => $landlord->id]);
        $closed = Tenant::factory()->create(['user_id' => $landlord->id]);

        $this->actingAs($landlord)->postJson("/api/tenants/{$closed->id}/archive")->assertStatus(200);

        $this->actingAs($landlord)
            ->getJson('/api/tenants?archives=seuls')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $closed->id);

        $this->actingAs($landlord)
            ->getJson('/api/tenants?archives=inclus')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_a_file_can_come_back(): void
    {
        $landlord = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $landlord->id]);

        $this->actingAs($landlord)->postJson("/api/tenants/{$tenant->id}/archive")->assertStatus(200);

        $this->actingAs($landlord)
            ->deleteJson("/api/tenants/{$tenant->id}/archive")
            ->assertStatus(200)
            ->assertJsonPath('data.is_archived', false);

        $this->actingAs($landlord)->getJson('/api/tenants')->assertJsonCount(1, 'data');
    }

    public function test_another_landlord_cannot_archive(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/tenants/{$tenant->id}/archive")
            ->assertStatus(403);
    }

    /* ----------------------------------------------------------------------
     | Invitation
     |----------------------------------------------------------------------*/

    public function test_the_tenant_is_invited_to_open_their_space(): void
    {
        $landlord = User::factory()->create();
        $tenant = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ]);

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/invite")
            ->assertStatus(200);

        Notification::assertSentOnDemand(TenantInvitationNotification::class);
    }

    public function test_a_file_without_an_address_cannot_be_invited(): void
    {
        $landlord = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $landlord->id, 'email' => null]);

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/invite")
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    /** Un second message n'entretiendrait que la confusion. */
    public function test_a_file_already_linked_is_not_invited_again(): void
    {
        $landlord = User::factory()->create();
        $tenant = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'account_user_id' => User::factory()->tenant()->create()->id,
        ]);

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/invite")
            ->assertStatus(409);

        Notification::assertNothingSent();
    }

    public function test_another_landlord_cannot_invite(): void
    {
        $tenant = Tenant::factory()->create(['email' => 'lea@example.com']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/tenants/{$tenant->id}/invite")
            ->assertStatus(403);
    }

    /* ----------------------------------------------------------------------
     | Identité
     |----------------------------------------------------------------------*/

    public function test_the_identity_details_are_recorded(): void
    {
        $landlord = User::factory()->create();

        $response = $this->actingAs($landlord)->postJson('/api/tenants', [
            'first_name' => 'Lea',
            'last_name' => 'Martin',
            'birth_date' => '1994-06-12',
            'birth_place' => 'Lyon',
            'identity_document_type' => 'carte_identite',
            'identity_document_number' => '940612345678',
            'email' => 'lea@example.com',
        ])->assertStatus(201);

        $tenant = Tenant::find($response->json('id'));

        $this->assertSame('1994-06-12', $tenant->birth_date->toDateString());
        $this->assertSame('940612345678', $tenant->identity_document_number);
    }

    /**
     * Une donnée d'identification directe : elle ouvre l'usurpation d'identité,
     * pas seulement le démarchage.
     */
    public function test_the_identity_number_is_encrypted_at_rest(): void
    {
        $landlord = User::factory()->create();

        $tenant = Tenant::create([
            'user_id' => $landlord->id,
            'first_name' => 'Lea',
            'last_name' => 'Martin',
            'identity_document_number' => '940612345678',
        ]);

        $raw = DB::table('tenants')
            ->where('id', $tenant->id)
            ->value('identity_document_number');

        $this->assertNotSame('940612345678', $raw);
        $this->assertSame('940612345678', $tenant->refresh()->identity_document_number);
    }

    /** Même règle que pour les biens : une date se sérialise sans heure. */
    public function test_the_birth_date_is_serialised_without_a_time(): void
    {
        $landlord = User::factory()->create();

        $tenant = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'birth_date' => '1996-03-08',
        ]);

        $this->actingAs($landlord)
            ->getJson("/api/tenants/{$tenant->id}")
            ->assertStatus(200)
            ->assertJsonPath('birth_date', '1996-03-08');
    }

    public function test_a_birth_date_in_the_future_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/tenants', [
                'first_name' => 'Lea',
                'last_name' => 'Martin',
                'birth_date' => now()->addYear()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date']);
    }
}
