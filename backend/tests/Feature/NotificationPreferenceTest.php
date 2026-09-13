<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_is_filtered_by_profile(): void
    {
        $landlord = User::factory()->create();

        $topics = array_column(
            $this->actingAs($landlord)->getJson('/api/auth/notification-preferences')
                ->assertStatus(200)
                ->json('data.topics'),
            'value'
        );

        $this->assertContains(NotificationTopic::LoyerImpaye->value, $topics);

        // Proposer au bailleur de couper « relance reçue » n'aurait aucun sens :
        // c'est le locataire qui la reçoit.
        $this->assertNotContains(NotificationTopic::RelanceLoyer->value, $topics);
    }

    public function test_a_tenant_sees_their_own_topics(): void
    {
        $topics = array_column(
            $this->actingAs(User::factory()->tenant()->create())
                ->getJson('/api/auth/notification-preferences')
                ->assertStatus(200)
                ->json('data.topics'),
            'value'
        );

        $this->assertContains(NotificationTopic::RelanceLoyer->value, $topics);
        $this->assertNotContains(NotificationTopic::LoyerImpaye->value, $topics);
    }

    public function test_defaults_apply_before_any_choice(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/auth/notification-preferences')
            ->assertStatus(200)
            ->assertJsonPath(
                'data.preferences.'.NotificationTopic::LoyerImpaye->value,
                ['mail', 'database']
            );
    }

    public function test_a_channel_can_be_switched_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/auth/notification-preferences', [
            'preferences' => [
                NotificationTopic::LoyerImpaye->value => ['database'],
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.preferences.'.NotificationTopic::LoyerImpaye->value, ['database']);

        $this->assertFalse(
            $user->refresh()->acceptsNotification(NotificationTopic::LoyerImpaye, NotificationChannel::Mail)
        );
    }

    /** « Aucun canal » est une réponse légitime, distincte d'un sujet absent. */
    public function test_every_channel_can_be_switched_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/auth/notification-preferences', [
            'preferences' => [NotificationTopic::FinBail->value => []],
        ])->assertStatus(200);

        $this->assertSame([], $user->refresh()->notificationPreferences()->channelsFor(NotificationTopic::FinBail));
    }

    /**
     * Un enregistrement partiel ne touche pas aux sujets absents : sans quoi
     * l'écran d'un profil effacerait les réglages de l'autre.
     */
    public function test_saving_one_topic_leaves_the_others_alone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/auth/notification-preferences', [
            'preferences' => [NotificationTopic::LoyerImpaye->value => []],
        ])->assertStatus(200);

        $this->actingAs($user)->putJson('/api/auth/notification-preferences', [
            'preferences' => [NotificationTopic::FinBail->value => ['mail']],
        ])->assertStatus(200);

        $preferences = $user->refresh()->notificationPreferences();

        $this->assertSame([], $preferences->channelsFor(NotificationTopic::LoyerImpaye));
        $this->assertSame([NotificationChannel::Mail], $preferences->channelsFor(NotificationTopic::FinBail));
    }

    /** Un sujet étranger au profil est ignoré, pas rejeté. */
    public function test_a_topic_from_the_other_profile_is_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/auth/notification-preferences', [
            'preferences' => [
                NotificationTopic::RelanceLoyer->value => [],
                NotificationTopic::FinBail->value => ['mail'],
            ],
        ])->assertStatus(200);

        $preferences = $user->refresh()->notificationPreferences();

        $this->assertSame([NotificationChannel::Mail], $preferences->channelsFor(NotificationTopic::FinBail));
        // Le sujet du locataire garde son défaut.
        $this->assertSame(
            NotificationTopic::RelanceLoyer->defaultChannels(),
            $preferences->channelsFor(NotificationTopic::RelanceLoyer)
        );
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/auth/notification-preferences', [
                'preferences' => [NotificationTopic::FinBail->value => ['pigeon-voyageur']],
            ])
            ->assertStatus(422);
    }

    /**
     * Une adresse non vérifiée ne reçoit rien par courriel, quelle que soit la
     * préférence : le dire évite de chercher pourquoi rien n'arrive.
     */
    public function test_an_unverified_address_disables_mail(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/notification-preferences')
            ->assertStatus(200)
            ->assertJsonPath('data.mail_available', false);

        $this->assertFalse(
            $user->acceptsNotification(NotificationTopic::LoyerImpaye, NotificationChannel::Mail)
        );

        $this->assertSame(
            ['database'],
            $user->notificationChannelsFor(NotificationTopic::LoyerImpaye)
        );
    }

    public function test_preferences_require_authentication(): void
    {
        $this->getJson('/api/auth/notification-preferences')->assertStatus(401);
    }
}
