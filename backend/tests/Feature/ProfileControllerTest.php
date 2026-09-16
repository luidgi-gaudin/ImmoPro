<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Services\Auth\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
    }

    /* ----------------------------------------------------------------------
     | Informations personnelles
     |----------------------------------------------------------------------*/

    public function test_a_landlord_updates_their_details(): void
    {
        $user = User::factory()->create(['name' => 'Jean Dupont']);

        $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => 'Jean-Pierre Dupont',
            'phone' => '0601020304',
            'siret' => '12345678901234',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Jean-Pierre Dupont')
            ->assertJsonPath('data.phone', '0601020304');

        $this->assertSame('12345678901234', $user->refresh()->siret);
    }

    /**
     * Un locataire n'émet pas de quittance : lui stocker un SIRET conserverait
     * une donnée dont l'application n'a aucun usage.
     */
    public function test_billing_details_are_ignored_for_a_tenant(): void
    {
        $user = User::factory()->tenant()->create();

        $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => 'Lea Martin',
            'siret' => '12345678901234',
            'iban' => 'FR7630006000011234567890189',
        ])->assertStatus(200);

        $user->refresh();

        $this->assertNull($user->siret);
        $this->assertNull($user->iban);
    }

    /** Un champ vidé efface la valeur, il n'enregistre pas une chaîne vide. */
    public function test_an_emptied_field_is_cleared(): void
    {
        $user = User::factory()->create(['phone' => '0601020304']);

        $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => $user->name,
            'phone' => '',
        ])->assertStatus(200);

        $this->assertNull($user->refresh()->phone);
    }

    /**
     * Ni l'adresse ni le rôle ne passent par ce formulaire : la première suit
     * son propre parcours avec confirmation, le second se choisit à
     * l'inscription.
     */
    public function test_the_email_and_the_role_cannot_be_changed_here(): void
    {
        $user = User::factory()->create(['email' => 'jean@example.com']);

        $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => 'Jean Dupont',
            'email' => 'autre@example.com',
            'role' => 'locataire',
        ])->assertStatus(200);

        $user->refresh();

        $this->assertSame('jean@example.com', $user->email);
        $this->assertSame('proprietaire', $user->role->value);
    }

    public function test_the_profile_requires_authentication(): void
    {
        $this->putJson('/api/auth/profile', ['name' => 'Jean'])->assertStatus(401);
    }

    /* ----------------------------------------------------------------------
     | Changement d'adresse
     |----------------------------------------------------------------------*/

    public function test_the_code_goes_to_the_new_address_only(): void
    {
        $user = User::factory()->create([
            'email' => 'ancienne@example.com',
            'password' => bcrypt('password1'),
        ]);

        $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'nouvelle@example.com',
            'password' => 'password1',
        ])
            ->assertStatus(200)
            ->assertJsonPath('pending_email', 'nouvelle@example.com');

        // L'adresse du compte ne bouge pas tant que rien n'est confirmé.
        $this->assertSame('ancienne@example.com', $user->refresh()->email);
        $this->assertSame('nouvelle@example.com', $user->pending_email);

        Notification::assertNothingSentTo($user);
        Notification::assertSentOnDemand(EmailOtpNotification::class);
    }

    /**
     * L'adresse commande la réinitialisation du mot de passe, donc le compte
     * entier : un poste laissé déverrouillé ne doit pas suffire à le prendre.
     */
    public function test_changing_the_address_requires_the_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password1')]);

        $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'nouvelle@example.com',
            'password' => 'mauvais',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertNull($user->refresh()->pending_email);
    }

    public function test_confirming_the_code_moves_the_address(): void
    {
        $user = User::factory()->create([
            'email' => 'ancienne@example.com',
            'password' => bcrypt('password1'),
        ]);

        $code = $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'nouvelle@example.com',
            'password' => 'password1',
        ])->json('otp.debug_code');

        $this->actingAs($user)->postJson('/api/auth/profile/email/confirm', ['code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'nouvelle@example.com')
            ->assertJsonPath('data.email_verified', true);

        $user->refresh();

        $this->assertSame('nouvelle@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_a_wrong_code_leaves_the_address_alone(): void
    {
        $user = User::factory()->create([
            'email' => 'ancienne@example.com',
            'password' => bcrypt('password1'),
        ]);

        $code = $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'nouvelle@example.com',
            'password' => 'password1',
        ])->json('otp.debug_code');

        $wrong = $code === '000000' ? '999999' : '000000';

        $this->actingAs($user)->postJson('/api/auth/profile/email/confirm', ['code' => $wrong])
            ->assertStatus(422);

        $this->assertSame('ancienne@example.com', $user->refresh()->email);
    }

    public function test_an_address_taken_meanwhile_is_refused(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password1')]);

        $code = $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'convoitee@example.com',
            'password' => 'password1',
        ])->json('otp.debug_code');

        User::factory()->create(['email' => 'convoitee@example.com']);

        $this->actingAs($user)->postJson('/api/auth/profile/email/confirm', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertNull($user->refresh()->pending_email);
    }

    public function test_the_change_can_be_cancelled(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password1')]);

        $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'nouvelle@example.com',
            'password' => 'password1',
        ])->assertStatus(200);

        $this->actingAs($user)->deleteJson('/api/auth/profile/email')->assertStatus(200);

        $this->assertNull($user->refresh()->pending_email);
    }

    /** Un compte ouvert par Google n'a pas de mot de passe à confirmer. */
    public function test_an_account_without_a_password_needs_none(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'nouvelle@example.com',
        ])->assertStatus(200);
    }

    /** La nouvelle adresse peut correspondre à un dossier ouvert avec elle. */
    public function test_the_new_address_claims_matching_tenant_files(): void
    {
        $landlord = User::factory()->create();
        $user = User::factory()->tenant()->create(['password' => bcrypt('password1')]);

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ]);

        $code = $this->actingAs($user)->postJson('/api/auth/profile/email', [
            'email' => 'lea@example.com',
            'password' => 'password1',
        ])->json('otp.debug_code');

        $this->actingAs($user)->postJson('/api/auth/profile/email/confirm', ['code' => $code])
            ->assertStatus(200);

        $this->assertSame($user->id, $profile->refresh()->account_user_id);
    }

    /* ----------------------------------------------------------------------
     | Mot de passe
     |----------------------------------------------------------------------*/

    public function test_an_account_without_a_password_can_set_a_first_one(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->actingAs($user)->putJson('/api/auth/password', [
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ])->assertStatus(200);

        $this->assertTrue($user->refresh()->hasUsablePassword());
    }

    public function test_an_existing_password_is_still_required(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password1')]);

        $this->actingAs($user)->putJson('/api/auth/password', [
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /* ----------------------------------------------------------------------
     | Photo de profil
     |----------------------------------------------------------------------*/

    public function test_an_avatar_is_stored_on_the_private_disk(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/auth/profile/avatar', [
                'file' => UploadedFile::fake()->image('portrait.jpg'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.has_avatar', true);

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);
        Storage::disk('documents')->assertExists($path);
    }

    /** Le chemin de stockage ne circule pas : seul un drapeau le signale. */
    public function test_the_storage_path_never_leaves_the_server(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/profile/avatar', [
            'file' => UploadedFile::fake()->image('portrait.jpg'),
        ])->assertStatus(200);

        $this->assertArrayNotHasKey('avatar_path', $response->json('data'));
    }

    public function test_replacing_the_avatar_removes_the_previous_file(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/profile/avatar', [
            'file' => UploadedFile::fake()->image('premier.jpg'),
        ])->assertStatus(200);

        $first = $user->refresh()->avatar_path;

        $this->actingAs($user)->postJson('/api/auth/profile/avatar', [
            'file' => UploadedFile::fake()->image('second.jpg'),
        ])->assertStatus(200);

        Storage::disk('documents')->assertMissing($first);
        Storage::disk('documents')->assertExists($user->refresh()->avatar_path);
    }

    public function test_a_document_is_not_accepted_as_an_avatar(): void
    {
        Storage::fake('documents');

        $this->actingAs(User::factory()->create())
            ->postJson('/api/auth/profile/avatar', [
                'file' => UploadedFile::fake()->create('bail.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_the_avatar_is_served_to_its_owner_only(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/profile/avatar', [
            'file' => UploadedFile::fake()->image('portrait.jpg'),
        ])->assertStatus(200);

        $this->actingAs($user)->get('/api/auth/profile/avatar')->assertStatus(200);

        // Un autre compte reçoit le sien — absent — pas celui du voisin.
        $this->actingAs(User::factory()->create())
            ->get('/api/auth/profile/avatar')
            ->assertStatus(404);
    }

    public function test_the_avatar_can_be_removed(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/profile/avatar', [
            'file' => UploadedFile::fake()->image('portrait.jpg'),
        ])->assertStatus(200);

        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)->deleteJson('/api/auth/profile/avatar')
            ->assertStatus(200)
            ->assertJsonPath('data.has_avatar', false);

        Storage::disk('documents')->assertMissing($path);
    }

    /* ----------------------------------------------------------------------
     | Anonymisation
     |----------------------------------------------------------------------*/

    /**
     * Les comptes externes doivent partir avec : les laisser rendrait le compte
     * anonymisé réactivable d'un clic.
     */
    public function test_anonymising_detaches_the_external_accounts(): void
    {
        $user = User::factory()->create();
        SocialAccount::factory()->create(['user_id' => $user->id]);

        app(EmailOtpService::class)->send($user, OtpPurpose::EmailVerification);

        $user->anonymize();

        $this->assertSame(0, SocialAccount::where('user_id', $user->id)->count());
        $this->assertNull($user->refresh()->otp_code_hash);
    }
}
