<?php

namespace Tests\Feature\Auth;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * Connexion Google et Apple.
 *
 * Les jetons sont réellement signés avec une paire de clés engendrée pour le
 * test, et le jeu de clés du fournisseur est simulé au niveau HTTP. Rien n'est
 * bouchonné dans le vérificateur lui-même : c'est précisément le code qui doit
 * être éprouvé, puisque c'est lui qui décide si un texte reçu du client vaut
 * preuve d'identité.
 */
class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'immopro-test.apps.googleusercontent.com';

    private const KID = 'cle-de-test';

    private OpenSSLAsymmetricKey $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('services.google.client_id', [self::CLIENT_ID]);
        config()->set('services.apple.client_id', ['fr.immopro.app']);

        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [$this->jwk()]]),
            'https://appleid.apple.com/auth/keys' => Http::response(['keys' => [$this->jwk()]]),
        ]);
    }

    /* ----------------------------------------------------------------------
     | Fabrication des jetons
     |----------------------------------------------------------------------*/

    /** @return array<string, string> */
    private function jwk(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);

        return [
            'kid' => self::KID,
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ];
    }

    /** @param array<string, mixed> $claims */
    private function token(array $claims = [], array $header = []): string
    {
        $header = array_merge(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'], $header);

        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '1029384756',
            'email' => 'jean@example.com',
            'email_verified' => true,
            'name' => 'Jean Dupont',
            'iat' => time() - 10,
            'exp' => time() + 600,
        ], $claims);

        $encoded = $this->base64Url(json_encode($header)).'.'.$this->base64Url(json_encode($claims));

        openssl_sign($encoded, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $encoded.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /* ----------------------------------------------------------------------
     | Inscription et connexion
     |----------------------------------------------------------------------*/

    public function test_a_first_sign_in_creates_the_account(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(),
            'role' => 'proprietaire',
        ])
            ->assertStatus(201)
            ->assertJsonPath('created', true)
            ->assertJsonStructure(['token', 'data' => ['id', 'email', 'role']]);

        $this->assertDatabaseHas('users', [
            'email' => 'jean@example.com',
            'role' => 'proprietaire',
        ]);

        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'google',
            'provider_user_id' => '1029384756',
        ]);
    }

    /**
     * Le fournisseur atteste l'adresse : redemander un code reviendrait à
     * prouver deux fois la même chose.
     */
    public function test_an_attested_address_is_considered_verified(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(),
            'role' => 'locataire',
        ])->assertStatus(201);

        $this->assertNotNull(User::where('email', 'jean@example.com')->sole()->email_verified_at);
    }

    /** Un compte ouvert par Google n'a pas de mot de passe utilisable. */
    public function test_the_created_account_has_no_password(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(),
            'role' => 'proprietaire',
        ])->assertStatus(201);

        $this->assertNull(User::where('email', 'jean@example.com')->sole()->password);
    }

    public function test_a_second_sign_in_reuses_the_account(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(),
            'role' => 'proprietaire',
        ])->assertStatus(201);

        $this->postJson('/api/auth/social/google', ['id_token' => $this->token()])
            ->assertStatus(200)
            ->assertJsonPath('created', false);

        $this->assertSame(1, User::where('email', 'jean@example.com')->count());
    }

    /**
     * Le rôle ne se rejoue pas : le renvoyer à chaque connexion permettrait de
     * changer de profil en rejouant la requête.
     */
    public function test_the_role_cannot_be_changed_by_signing_in_again(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(),
            'role' => 'locataire',
        ])->assertStatus(201);

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(),
            'role' => 'proprietaire',
        ])->assertStatus(200);

        $this->assertSame('locataire', User::where('email', 'jean@example.com')->sole()->role->value);
    }

    public function test_creating_an_account_without_a_role_is_refused(): void
    {
        $this->postJson('/api/auth/social/google', ['id_token' => $this->token()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseCount('users', 0);
    }

    /* ----------------------------------------------------------------------
     | Vérification du jeton
     |----------------------------------------------------------------------*/

    public function test_a_token_signed_by_someone_else_is_refused(): void
    {
        $intruder = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT']));
        $payload = $this->base64Url(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'usurpateur',
            'email' => 'victime@example.com',
            'email_verified' => true,
            'exp' => time() + 600,
        ]));

        openssl_sign("{$header}.{$payload}", $signature, $intruder, OPENSSL_ALGO_SHA256);

        $this->postJson('/api/auth/social/google', [
            'id_token' => "{$header}.{$payload}.".$this->base64Url($signature),
            'role' => 'proprietaire',
        ])->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Le contrôle le plus facile à oublier, et le plus grave : sans lui, un
     * jeton parfaitement signé par Google mais émis pour une autre application
     * ouvrirait une session ici.
     */
    public function test_a_token_issued_for_another_application_is_refused(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(['aud' => 'application-tierce.apps.googleusercontent.com']),
            'role' => 'proprietaire',
        ])->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_token_from_an_unexpected_issuer_is_refused(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(['iss' => 'https://malveillant.example.com']),
            'role' => 'proprietaire',
        ])->assertStatus(422);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(['exp' => time() - 3600, 'iat' => time() - 7200]),
            'role' => 'proprietaire',
        ])->assertStatus(422);
    }

    /**
     * La faille classique des bibliothèques JWT : lire l'algorithme dans le
     * jeton lui-même, et accepter qu'il annonce « aucune signature ».
     */
    public function test_an_unsigned_token_is_refused(): void
    {
        $header = $this->base64Url(json_encode(['alg' => 'none', 'kid' => self::KID, 'typ' => 'JWT']));
        $payload = $this->base64Url(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'usurpateur',
            'email' => 'victime@example.com',
            'exp' => time() + 600,
        ]));

        $this->postJson('/api/auth/social/google', [
            'id_token' => "{$header}.{$payload}.",
            'role' => 'proprietaire',
        ])->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_nonce_mismatch_is_refused(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(['nonce' => 'valeur-du-fournisseur']),
            'nonce' => 'ce-que-le-client-attendait',
            'role' => 'proprietaire',
        ])->assertStatus(422);
    }

    public function test_an_unconfigured_provider_is_refused(): void
    {
        config()->set('services.apple.client_id', []);

        $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->token(),
            'role' => 'proprietaire',
        ])->assertStatus(422);
    }

    public function test_an_unknown_provider_returns_404(): void
    {
        $this->postJson('/api/auth/social/facebook', [
            'id_token' => $this->token(),
            'role' => 'proprietaire',
        ])->assertStatus(404);
    }

    /* ----------------------------------------------------------------------
     | Rattachement à un compte existant
     |----------------------------------------------------------------------*/

    public function test_an_attested_address_links_to_the_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'jean@example.com']);

        $this->postJson('/api/auth/social/google', ['id_token' => $this->token()])
            ->assertStatus(200)
            ->assertJsonPath('created', false);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
        ]);

        $this->assertSame(1, User::count());
    }

    /**
     * Sans l'attestation du fournisseur, l'égalité des adresses ne prouve rien :
     * s'en contenter permettrait de prendre la main sur le compte d'autrui.
     */
    public function test_an_unattested_address_does_not_take_over_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'jean@example.com']);

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(['email_verified' => false]),
            'role' => 'proprietaire',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('social_accounts', ['user_id' => $user->id]);
    }

    /** Apple renvoie « true » sous forme de chaîne : un test le fige. */
    public function test_apple_string_valued_email_verified_is_understood(): void
    {
        $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->token([
                'iss' => 'https://appleid.apple.com',
                'aud' => 'fr.immopro.app',
                'email_verified' => 'true',
                'sub' => 'apple-001',
            ]),
            'role' => 'locataire',
        ])->assertStatus(201);

        $this->assertNotNull(User::where('email', 'jean@example.com')->sole()->email_verified_at);
    }

    public function test_the_string_false_is_not_taken_for_true(): void
    {
        User::factory()->create(['email' => 'jean@example.com']);

        $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->token([
                'iss' => 'https://appleid.apple.com',
                'aud' => 'fr.immopro.app',
                'email_verified' => 'false',
                'sub' => 'apple-002',
            ]),
            'role' => 'locataire',
        ])->assertStatus(409);
    }

    /* ----------------------------------------------------------------------
     | Rattachement depuis le profil
     |----------------------------------------------------------------------*/

    public function test_a_signed_in_user_can_link_a_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/auth/social/google/link', ['id_token' => $this->token()])
            ->assertStatus(200);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
        ]);
    }

    public function test_a_provider_already_taken_cannot_be_linked_twice(): void
    {
        $owner = User::factory()->create();
        SocialAccount::factory()->create([
            'user_id' => $owner->id,
            'provider' => 'google',
            'provider_user_id' => '1029384756',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/auth/social/google/link', ['id_token' => $this->token()])
            ->assertStatus(409);
    }

    /**
     * Détacher le dernier moyen de connexion enfermerait le titulaire dehors.
     */
    public function test_the_last_sign_in_method_cannot_be_detached(): void
    {
        $user = User::factory()->withoutPassword()->create();
        SocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/auth/social/google')
            ->assertStatus(409);

        $this->assertSame(1, $user->socialAccounts()->count());
    }

    public function test_a_provider_can_be_detached_when_a_password_remains(): void
    {
        $user = User::factory()->create();
        SocialAccount::factory()->create(['user_id' => $user->id, 'provider' => 'google']);

        $this->actingAs($user)
            ->deleteJson('/api/auth/social/google')
            ->assertStatus(200);

        $this->assertSame(0, $user->socialAccounts()->count());
    }

    /* ----------------------------------------------------------------------
     | Catalogue public
     |----------------------------------------------------------------------*/

    public function test_only_configured_providers_are_advertised(): void
    {
        config()->set('services.apple.client_id', []);

        $response = $this->getJson('/api/auth/providers')->assertStatus(200);

        $values = array_column($response->json('data'), 'value');

        $this->assertContains(SocialProvider::Google->value, $values);
        $this->assertNotContains(SocialProvider::Apple->value, $values);
    }

    /* ----------------------------------------------------------------------
     | Espace locataire
     |----------------------------------------------------------------------*/

    public function test_a_tenant_signing_in_with_google_finds_their_file(): void
    {
        $landlord = User::factory()->create();

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'jean@example.com',
        ]);

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->token(),
            'role' => 'locataire',
        ])->assertStatus(201);

        $this->assertSame(
            User::where('email', 'jean@example.com')->sole()->id,
            $profile->refresh()->account_user_id
        );
    }
}
