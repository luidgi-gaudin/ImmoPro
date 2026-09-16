<?php

namespace App\Enums;

/**
 * État locatif d'un bien.
 *
 * Le booléen « loué / pas loué » qu'employait l'application confondait deux
 * situations que rien ne rapproche : un logement vacant se remet en location,
 * un logement en travaux ne le peut pas. Le taux d'occupation et les relances
 * de mise en location s'en trouvaient faussés.
 */
enum OccupancyStatus: string
{
    case Loue = 'loue';
    case Vacant = 'vacant';
    case EnTravaux = 'en_travaux';

    public function label(): string
    {
        return match ($this) {
            self::Loue => 'Loué',
            self::Vacant => 'Vacant',
            self::EnTravaux => 'En travaux',
        };
    }

    /** Un bien en travaux n'est pas « disponible » : il est indisponible. */
    public function isAvailableForRent(): bool
    {
        return $this === self::Vacant;
    }

    /** @return list<array<string, string>> */
    public static function catalogue(): array
    {
        return array_map(fn (self $status) => [
            'value' => $status->value,
            'label' => $status->label(),
        ], self::cases());
    }
}
