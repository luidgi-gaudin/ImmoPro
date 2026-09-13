<?php

namespace Tests\Unit;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\UserRole;
use App\Support\NotificationPreferences;
use PHPUnit\Framework\TestCase;

class NotificationPreferencesTest extends TestCase
{
    public function test_an_empty_store_falls_back_to_the_defaults(): void
    {
        $preferences = NotificationPreferences::fromArray(null);

        $this->assertSame(
            NotificationTopic::LoyerImpaye->defaultChannels(),
            $preferences->channelsFor(NotificationTopic::LoyerImpaye)
        );
    }

    /**
     * Le point qui justifie de ne stocker que les écarts : un sujet ajouté plus
     * tard doit arriver allumé chez les comptes existants, pas naître éteint.
     */
    public function test_a_topic_never_configured_keeps_its_default(): void
    {
        $preferences = NotificationPreferences::fromArray([
            NotificationTopic::LoyerImpaye->value => [],
        ]);

        $this->assertSame([], $preferences->channelsFor(NotificationTopic::LoyerImpaye));

        $this->assertSame(
            NotificationTopic::FinBail->defaultChannels(),
            $preferences->channelsFor(NotificationTopic::FinBail)
        );
    }

    /** Un tableau vide vaut « aucun canal », il ne vaut pas « défaut ». */
    public function test_an_empty_list_is_a_real_choice(): void
    {
        $preferences = NotificationPreferences::fromArray([
            NotificationTopic::FinBail->value => [],
        ]);

        $this->assertFalse($preferences->allows(NotificationTopic::FinBail, NotificationChannel::Mail));
    }

    public function test_unknown_topics_and_channels_are_discarded(): void
    {
        $preferences = NotificationPreferences::fromArray([
            'sujet-inexistant' => ['mail'],
            NotificationTopic::FinBail->value => ['mail', 'pigeon-voyageur'],
        ]);

        $this->assertSame([NotificationChannel::Mail], $preferences->channelsFor(NotificationTopic::FinBail));
        $this->assertSame([], $preferences->toArray()['sujet-inexistant'] ?? []);
    }

    public function test_merging_only_touches_the_topics_present(): void
    {
        $preferences = NotificationPreferences::fromArray([
            NotificationTopic::LoyerImpaye->value => [],
        ])->merge([
            NotificationTopic::FinBail->value => ['mail'],
        ]);

        $this->assertSame([], $preferences->channelsFor(NotificationTopic::LoyerImpaye));
        $this->assertSame([NotificationChannel::Mail], $preferences->channelsFor(NotificationTopic::FinBail));
    }

    public function test_the_resolved_view_covers_every_topic_of_the_profile(): void
    {
        $resolved = NotificationPreferences::fromArray(null)->resolvedFor(UserRole::Locataire);

        foreach (NotificationTopic::forRole(UserRole::Locataire) as $topic) {
            $this->assertArrayHasKey($topic->value, $resolved);
        }

        $this->assertArrayNotHasKey(NotificationTopic::LoyerImpaye->value, $resolved);
    }

    public function test_a_duplicated_channel_is_kept_once(): void
    {
        $preferences = NotificationPreferences::fromArray([
            NotificationTopic::FinBail->value => ['mail', 'mail', 'database'],
        ]);

        $this->assertSame(
            [NotificationChannel::Mail, NotificationChannel::Database],
            $preferences->channelsFor(NotificationTopic::FinBail)
        );
    }
}
