<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Politique de session : 12 h absolues depuis la connexion, 2 h sans activité.
 *
 * Les jetons sont utilisés pour de vrai (en-tête Authorization) et non par
 * `actingAs`, qui court-circuite le garde et ne prouverait donc rien.
 */
class SessionExpirationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    /**
     * Oublie le garde entre deux appels du même test.
     *
     * Dans un test, l'application n'est instanciée qu'une fois : le garde
     * Sanctum conserve l'utilisateur résolu au premier appel et ne rejoue pas
     * la résolution du jeton au second. Sans cet oubli, un test qui vérifie
     * qu'un jeton devient invalide passerait toujours — pour la mauvaise
     * raison.
     */
    private function forgetGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_login_announces_when_the_session_will_end(): void
    {
        $user = User::factory()->create(['password' => 'motdepasse123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'motdepasse123',
        ]);

        $response->assertOk()
            ->assertJsonPath('session.ttl_minutes', 720)
            ->assertJsonPath('session.idle_minutes', 120)
            ->assertJsonPath('session.ends_because', 'inactivite');

        // Une session neuve se ferme d'abord par inactivité : deux heures
        // arrivent avant douze.
        $this->assertNotNull($response->json('session.ends_at'));
        $this->assertNotNull($response->json('session.expires_at'));
    }

    public function test_a_token_older_than_the_absolute_ttl_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        // Le jeton reste utilisé régulièrement : seule la durée absolue le
        // périme. C'est bien ce qu'on veut vérifier ici.
        Carbon::setTestNow(now()->addHours(13));
        PersonalAccessToken::query()->update(['last_used_at' => now()->subMinute()]);

        $this->withToken($token)->getJson('/api/auth/user')->assertUnauthorized();

        Carbon::setTestNow();
    }

    public function test_a_token_unused_for_more_than_two_hours_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/auth/user')->assertOk();

        PersonalAccessToken::query()->update(['last_used_at' => now()->subHours(3)]);
        $this->forgetGuard();

        $response = $this->withToken($token)->getJson('/api/auth/user');

        $response->assertUnauthorized()
            // Le motif distingue « session expirée » de « jeton invalide » :
            // les deux n'appellent pas la même réaction côté utilisateur.
            ->assertJsonPath('reason', 'session_expired');
    }

    public function test_an_unknown_token_is_not_reported_as_an_expired_session(): void
    {
        $this->withToken('999|inexistant')->getJson('/api/auth/user')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'unauthenticated');
    }

    public function test_activity_pushes_back_the_idle_deadline(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        PersonalAccessToken::query()->update(['last_used_at' => now()->subMinutes(110)]);

        // Encore dans la fenêtre : l'appel passe et repousse l'échéance.
        $this->withToken($token)->getJson('/api/auth/user')->assertOk();

        $this->assertTrue(
            PersonalAccessToken::firstOrFail()->last_used_at->greaterThan(now()->subMinute()),
            'L\'activité doit repousser l\'échéance d\'inactivité.'
        );
    }

    public function test_extend_session_refreshes_the_idle_deadline(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        PersonalAccessToken::query()->update(['last_used_at' => now()->subMinutes(115)]);

        $response = $this->withToken($token)->postJson('/api/auth/session/extend');

        $response->assertOk();

        $idleExpiry = Carbon::parse($response->json('session.idle_expires_at'));

        $this->assertTrue(
            $idleExpiry->greaterThan(now()->addMinutes(119)),
            'Après prolongation, la session doit à nouveau courir sur deux heures pleines.'
        );
    }

    /**
     * L'horodatage d'usage n'est pas réécrit à chaque requête : c'est une
     * écriture distante de ~150 ms qui serait payée sur toutes les pages.
     */
    public function test_last_used_at_is_not_rewritten_on_every_request(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/auth/user')->assertOk();

        $first = PersonalAccessToken::firstOrFail()->last_used_at;

        Carbon::setTestNow(now()->addMinutes(2));
        $this->forgetGuard();
        $this->withToken($token)->getJson('/api/auth/user')->assertOk();

        $this->assertTrue(
            PersonalAccessToken::firstOrFail()->last_used_at->equalTo($first),
            'Deux minutes plus tard, la valeur enregistrée ne doit pas avoir bougé.'
        );

        Carbon::setTestNow(now()->addMinutes(10));
        $this->forgetGuard();
        $this->withToken($token)->getJson('/api/auth/user')->assertOk();

        $this->assertTrue(
            PersonalAccessToken::firstOrFail()->last_used_at->greaterThan($first),
            'Au-delà de la fenêtre de fraîcheur, la valeur doit être réécrite.'
        );

        Carbon::setTestNow();
    }

    public function test_a_forged_token_secret_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $id = explode('|', $token)[0];

        $this->withToken($id.'|secret-invente')->getJson('/api/auth/user')->assertUnauthorized();
    }
}
