<?php

namespace App\Support;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\UserRole;

/**
 * Réglages de notification d'un compte, lus depuis la colonne JSON `users`.
 *
 * La colonne ne stocke que les écarts au défaut. Deux raisons, et la seconde
 * est la vraie : d'abord une ligne plus courte, ensuite et surtout un sujet
 * ajouté plus tard arrive allumé chez tout le monde. Si la colonne figeait la
 * liste complète au moment de l'inscription, chaque nouveau sujet naîtrait
 * éteint pour les comptes existants — silencieusement, et sans que personne ne
 * comprenne pourquoi il ne reçoit rien.
 *
 * Une valeur absente vaut donc « défaut du sujet », jamais « désactivé ». Pour
 * couper un canal, la colonne porte explicitement la liste amputée.
 */
final readonly class NotificationPreferences
{
    /** @param array<string, list<string>> $overrides */
    private function __construct(private array $overrides) {}

    /** @param array<string, mixed>|null $stored */
    public static function fromArray(?array $stored): self
    {
        if (! $stored) {
            return new self([]);
        }

        $clean = [];

        foreach ($stored as $topic => $channels) {
            if (! NotificationTopic::tryFrom((string) $topic) || ! is_array($channels)) {
                continue;
            }

            $clean[(string) $topic] = array_values(array_unique(array_filter(
                array_map(
                    fn ($channel) => is_string($channel)
                        ? NotificationChannel::tryFrom($channel)?->value
                        : null,
                    $channels
                )
            )));
        }

        return new self($clean);
    }

    /**
     * Canaux actifs pour un sujet : l'écart enregistré, à défaut le défaut.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(NotificationTopic $topic): array
    {
        if (! array_key_exists($topic->value, $this->overrides)) {
            return $topic->defaultChannels();
        }

        return array_values(array_filter(array_map(
            fn (string $channel) => NotificationChannel::tryFrom($channel),
            $this->overrides[$topic->value]
        )));
    }

    public function allows(NotificationTopic $topic, NotificationChannel $channel): bool
    {
        return in_array($channel, $this->channelsFor($topic), true);
    }

    /**
     * Vue complète destinée à l'écran de réglages : tous les sujets du profil,
     * défauts compris. Le front n'a pas à connaître la règle de repli.
     *
     * @return array<string, list<string>>
     */
    public function resolvedFor(UserRole $role): array
    {
        $resolved = [];

        foreach (NotificationTopic::forRole($role) as $topic) {
            $resolved[$topic->value] = array_map(
                fn (NotificationChannel $channel) => $channel->value,
                $this->channelsFor($topic)
            );
        }

        return $resolved;
    }

    /**
     * Applique un réglage partiel : seuls les sujets présents sont touchés.
     *
     * Un écran qui n'affiche que les sujets d'un profil ne doit pas effacer
     * ceux de l'autre en enregistrant — un compte qui change de profil
     * retrouve ses réglages tels qu'il les avait laissés.
     *
     * @param  array<string, list<string>>  $changes
     */
    public function merge(array $changes): self
    {
        return self::fromArray(array_merge($this->overrides, $changes));
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->overrides;
    }
}
