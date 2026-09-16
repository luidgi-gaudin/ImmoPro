<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Services\Auth\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailOtpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les compteurs de débit vivent dans le cache, que `RefreshDatabase` ne
     * touche pas : sans ce nettoyage, un test hérite du quota déjà consommé par
     * le précédent et échoue selon l'ordre d'exécution.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Inscrit un compte et rend le code réellement envoyé.
     *
     * Le code est lu sur la notification interceptée, jamais sur la réponse
     * HTTP : l'API ne le rend pas, et ne doit pas le rendre. Un code qui
     * traverserait la réponse serait lisible dans l'onglet réseau du
     * navigateur, ce qui viderait de son sens le fait de l'avoir haché en base.
     *
     * @return array{0: string, 1: User}
     */
    private function register(array $overrides = []): array
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', array_merge([
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'proprietaire',
        ], $overrides));

        $response->assertStatus(201);

        $user = User::where('email', $response->json('data.email'))->sole();

        return [$this->sentCode($user), $user];
    }

    /** Code porté par la notification interceptée pour ce compte. */
    private function sentCode(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, EmailOtpNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->assertNotNull($code, 'Aucun code n\'a été envoyé.');

        return (string) $code;
    }

    public function test_registration_sends_a_code(): void
    {
        [, $user] = $this->register();

        Notification::assertSentTo($user, EmailOtpNotification::class);
    }

    /**
     * Le code ne doit pas être lisible en base : la table `users` échappe à la
     * Row Level Security, puisqu'elle est lue avant qu'une identité existe.
     */
    public function test_the_code_is_stored_hashed(): void
    {
        [$code, $user] = $this->register();

        $this->assertNotNull($user->otp_code_hash);
        $this->assertNotSame($code, $user->otp_code_hash);
        $this->assertTrue(password_verify($code, $user->otp_code_hash));
    }

    public function test_verifying_the_code_issues_a_token_and_marks_the_email_verified(): void
    {
        [$code, $user] = $this->register();

        $this->postJson('/api/auth/otp/verify', [
            'email' => $user->email,
            'code' => $code,
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['token', 'data' => ['id', 'email']])
            ->assertJsonPath('data.email_verified', true);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_the_code_cannot_be_used_twice(): void
    {
        [$code, $user] = $this->register();

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(200);

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_an_expired_code_is_refused(): void
    {
        [$code, $user] = $this->register();

        $user->forceFill(['otp_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertNull($user->refresh()->email_verified_at);
    }

    /**
     * Second rempart contre le balayage : le débit de la route.
     *
     * Le compteur d'essais par compte ne suffit pas — il se contourne en
     * répartissant la recherche sur plusieurs comptes. Le plafond par appelant
     * ferme cette porte-là. Le cap par compte, lui, est éprouvé au niveau du
     * service, où il n'est pas masqué par ce plafond.
     */
    public function test_the_verification_route_is_rate_limited(): void
    {
        [$code, $user] = $this->register();

        $wrong = $code === '000000' ? '999999' : '000000';

        // Dix essais passent le contrôleur — refusés sur le fond, pas sur le
        // débit — puis le plafond de la route se referme.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $wrong])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $wrong])
            ->assertStatus(429);
    }

    /**
     * Le quota se compte par adresse visée, pas seulement par appelant.
     *
     * Sans cela, l'inscription consommerait le quota du code qui la suit, et
     * s'acharner sur un compte bloquerait la vérification de tous ceux qui
     * partagent la même sortie réseau.
     */
    public function test_the_quota_is_counted_per_target_address(): void
    {
        [$code, $user] = $this->register();

        $wrong = $code === '000000' ? '999999' : '000000';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $wrong])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $wrong])
            ->assertStatus(429);

        // Une autre adresse garde le sien.
        $other = User::factory()->unverified()->create(['email' => 'autre@example.com']);

        $this->postJson('/api/auth/otp/verify', ['email' => $other->email, 'code' => '123456'])
            ->assertStatus(422);
    }

    /**
     * Un code émis pour un changement d'adresse ne doit pas valider une
     * inscription : deux gestes, un même secret, et un contrôle contourné.
     */
    public function test_a_code_issued_for_another_purpose_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $code = app(EmailOtpService::class)->send($user, OtpPurpose::EmailChange);

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * Le renvoi ne doit pas dire si l'adresse est inscrite : la réponse serait
     * un outil d'énumération.
     */
    public function test_resending_says_the_same_thing_for_an_unknown_address(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/auth/otp/send', ['email' => 'inconnu@example.com']);
        $known->assertStatus(200);

        User::factory()->unverified()->create(['email' => 'connu@example.com']);

        $this->postJson('/api/auth/otp/send', ['email' => 'connu@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('message', $known->json('message'));
    }

    /**
     * Le code ne doit apparaître nulle part dans la réponse.
     *
     * Il l'a fait un temps, pour dérouler le parcours en local sans ouvrir de
     * boîte de réception. C'était commode et c'était une faille : la réponse se
     * lit dans l'onglet réseau du navigateur, et un code lisible là annule tout
     * l'intérêt de le hacher en base.
     */
    public function test_the_code_never_travels_in_the_response(): void
    {
        Notification::fake();

        $registration = $this->postJson('/api/auth/register', [
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'proprietaire',
        ])->assertStatus(201);

        $user = User::where('email', 'jean@example.com')->sole();
        $code = $this->sentCode($user);

        $this->assertStringNotContainsString($code, $registration->getContent());

        $user->forceFill(['otp_sent_at' => now()->subHour()])->save();

        $resend = $this->postJson('/api/auth/otp/send', ['email' => 'jean@example.com'])
            ->assertStatus(200);

        $this->assertStringNotContainsString($this->sentCode($user->refresh()), $resend->getContent());
    }

    public function test_verifying_an_unknown_address_says_nothing_more_than_a_wrong_code(): void
    {
        $this->postJson('/api/auth/otp/verify', [
            'email' => 'inconnu@example.com',
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * Sans intervalle, le formulaire de renvoi devient un robinet à courriels
     * pointé sur l'adresse de son choix.
     */
    public function test_resending_too_soon_is_refused(): void
    {
        [, $user] = $this->register();

        $this->postJson('/api/auth/otp/send', ['email' => $user->email])
            ->assertStatus(429);
    }

    /**
     * Une panne d'envoi ne doit pas emporter l'inscription.
     *
     * Le compte est créé avant que le code parte. Un échec fatal ici
     * enfermerait son titulaire dehors pour de bon : il ne pourrait ni se
     * réinscrire, l'adresse étant prise, ni se connecter, l'adresse n'étant pas
     * vérifiée.
     */
    public function test_a_mail_failure_does_not_lose_the_account(): void
    {
        Notification::fake();

        Notification::shouldReceive('send')
            ->andThrow(new \RuntimeException('Serveur de messagerie injoignable'));

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'proprietaire',
        ]);

        $response->assertStatus(201)->assertJsonPath('email_verification_required', true);

        $this->assertDatabaseHas('users', ['email' => 'jean@example.com']);
        $this->assertStringContainsString('échoué', $response->json('message'));
    }

    /**
     * L'adresse vérifiée prouve que la personne contrôle la boîte que son
     * bailleur a inscrite au dossier : c'est ce qui rend le rattachement sûr.
     */
    public function test_verifying_links_the_tenant_profiles_carrying_that_address(): void
    {
        $landlord = User::factory()->create();

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ]);

        [$code, $user] = $this->register([
            'name' => 'Lea Martin',
            'email' => 'lea@example.com',
            'role' => 'locataire',
        ]);

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('linked_tenant_profiles', 1);

        $this->assertSame($user->id, $profile->refresh()->account_user_id);
    }

    /** Un dossier déjà réclamé ne change pas de main. */
    public function test_an_already_linked_profile_is_not_reassigned(): void
    {
        $landlord = User::factory()->create();
        $first = User::factory()->tenant()->create();

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
            'account_user_id' => $first->id,
        ]);

        [$code, $user] = $this->register([
            'name' => 'Autre Lea',
            'email' => 'lea@example.com',
            'role' => 'locataire',
        ]);

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('linked_tenant_profiles', 0);

        $this->assertSame($first->id, $profile->refresh()->account_user_id);
    }

    /** Un bailleur ne récupère pas les dossiers portant son adresse. */
    public function test_a_landlord_account_claims_nothing(): void
    {
        $other = User::factory()->create();

        $profile = Tenant::factory()->create([
            'user_id' => $other->id,
            'email' => 'jean@example.com',
        ]);

        [$code, $user] = $this->register(['email' => 'jean@example.com', 'role' => 'proprietaire']);

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('linked_tenant_profiles', 0);

        $this->assertNull($profile->refresh()->account_user_id);
    }
}
