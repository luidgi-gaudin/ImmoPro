<?php

namespace App\Enums;

/**
 * Canal par lequel une notification atteint son destinataire.
 *
 * Séparer le canal du sujet est ce qui rend les préférences utilisables :
 * couper les courriels de relance sans perdre la pastille dans l'application
 * est le réglage que les gens cherchent réellement, et un simple interrupteur
 * « notifications » ne sait pas l'exprimer.
 */
enum NotificationChannel: string
{
    /** Courriel. */
    case Mail = 'mail';

    /** Cloche de l'application : une alerte enregistrée, lue à la connexion. */
    case Database = 'database';

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'E-mail',
            self::Database => 'Dans l\'application',
        };
    }

    /** @return list<array<string, string>> */
    public static function catalogue(): array
    {
        return array_map(fn (self $channel) => [
            'value' => $channel->value,
            'label' => $channel->label(),
        ], self::cases());
    }
}
