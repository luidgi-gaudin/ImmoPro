<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TenantAccountLinker;
use App\Support\Rls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rattachement d'un dossier locataire au compte de la personne qu'il décrit.
 *
 * Une précaution particulière porte sur l'identité posée pour la base. Sous
 * PostgreSQL, l'écriture passe par une fonction qui lit cette identité dans la
 * session, et le rattachement survient précisément au moment où personne n'est
 * encore connecté : la validation du code et la connexion Google se font sur
 * des routes publiques. Une identité déduite de la session authentifiée y vaut
 * « personne », et le dossier reste détaché sans qu'aucune erreur ne le dise.
 *
 * Ces tests tournent sur SQLite, qui n'a pas de policy : ils ne peuvent donc
 * pas éprouver la fonction elle-même. Ils vérifient ce dont elle dépend —
 * que l'identité soit bien posée, et sur le bon compte.
 */
class TenantAccountLinkerTest extends TestCase
{
    use RefreshDatabase;

    private TenantAccountLinker $linker;

    protected function setUp(): void
    {
        parent::setUp();

        // L'identité survit d'un test à l'autre dans un même processus.
        Rls::forget();

        $this->linker = app(TenantAccountLinker::class);
    }

    public function test_it_claims_the_files_carrying_the_verified_address(): void
    {
        $landlord = User::factory()->create();
        $account = User::factory()->tenant()->create(['email' => 'lea@example.com']);

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ]);

        $this->assertSame(1, $this->linker->link($account));
        $this->assertSame($account->id, $profile->refresh()->account_user_id);
    }

    /**
     * Le point qui manquait, et qui ne se voyait qu'en production.
     *
     * La fonction PostgreSQL lit `app_user_id()`. Sans identité posée sur le
     * compte qu'on rattache, elle ne trouve rien à réclamer.
     */
    public function test_it_binds_the_identity_of_the_account_being_linked(): void
    {
        $landlord = User::factory()->create();
        $account = User::factory()->tenant()->create(['email' => 'lea@example.com']);

        Tenant::factory()->create(['user_id' => $landlord->id, 'email' => 'lea@example.com']);

        $this->assertSame(false, Rls::boundTo(), 'Rien ne doit être posé avant.');

        $this->linker->link($account);

        $this->assertSame($account->id, Rls::boundTo());
    }

    /**
     * L'identité posée ne doit pas dépendre de qui est authentifié.
     *
     * Sur la route de validation du code, ce n'est personne. Le test le
     * reproduit en n'authentifiant volontairement aucun compte.
     */
    public function test_it_does_not_rely_on_an_authenticated_session(): void
    {
        $landlord = User::factory()->create();
        $account = User::factory()->tenant()->create(['email' => 'lea@example.com']);

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ]);

        $this->assertNull(auth()->user(), 'Ce parcours est public : personne n\'est connecté.');

        $this->linker->link($account);

        $this->assertSame($account->id, $profile->refresh()->account_user_id);
    }

    /** La casse de l'adresse ne doit pas empêcher le rapprochement. */
    public function test_the_address_matches_regardless_of_case(): void
    {
        $landlord = User::factory()->create();
        $account = User::factory()->tenant()->create(['email' => 'Lea@Example.com']);

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ]);

        $this->assertSame(1, $this->linker->link($account));
        $this->assertSame($account->id, $profile->refresh()->account_user_id);
    }

    /**
     * Une adresse non vérifiée ne prouve rien.
     *
     * S'en contenter permettrait de s'inscrire avec l'adresse d'un tiers pour
     * lire son bail, ses quittances et sa pièce d'identité.
     */
    public function test_an_unverified_address_claims_nothing(): void
    {
        $landlord = User::factory()->create();
        $account = User::factory()->tenant()->unverified()->create(['email' => 'lea@example.com']);

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ]);

        $this->assertSame(0, $this->linker->link($account));
        $this->assertNull($profile->refresh()->account_user_id);

        // Rien ne doit être posé non plus : le compte n'a rien prouvé.
        $this->assertSame(false, Rls::boundTo());
    }

    public function test_a_landlord_claims_nothing(): void
    {
        $other = User::factory()->create();
        $account = User::factory()->create(['email' => 'jean@example.com']);

        $profile = Tenant::factory()->create([
            'user_id' => $other->id,
            'email' => 'jean@example.com',
        ]);

        $this->assertSame(0, $this->linker->link($account));
        $this->assertNull($profile->refresh()->account_user_id);
    }

    /** Un dossier déjà réclamé ne change pas de main. */
    public function test_an_already_claimed_file_is_left_alone(): void
    {
        $landlord = User::factory()->create();
        $first = User::factory()->tenant()->create();
        $second = User::factory()->tenant()->create(['email' => 'lea@example.com']);

        $profile = Tenant::factory()->create([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
            'account_user_id' => $first->id,
        ]);

        $this->assertSame(0, $this->linker->link($second));
        $this->assertSame($first->id, $profile->refresh()->account_user_id);
    }

    /** Une même personne peut louer chez deux bailleurs. */
    public function test_it_claims_every_matching_file(): void
    {
        $account = User::factory()->tenant()->create(['email' => 'lea@example.com']);

        foreach (range(1, 2) as $ignored) {
            Tenant::factory()->create([
                'user_id' => User::factory()->create()->id,
                'email' => 'lea@example.com',
            ]);
        }

        $this->assertSame(2, $this->linker->link($account));
    }
}
