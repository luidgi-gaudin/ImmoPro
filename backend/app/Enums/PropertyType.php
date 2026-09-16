<?php

namespace App\Enums;

/**
 * Nature du bien.
 *
 * `Studio` n'est pas un doublon d'`Appartement` : c'est la ligne la plus
 * fréquente d'un parc locatif urbain, et l'annoncer comme un appartement d'une
 * pièce oblige à relire la surface pour comprendre de quoi il s'agit.
 * `Terrain` est conservé pour les biens déjà enregistrés sous ce type.
 */
enum PropertyType: string
{
    case Appartement = 'appartement';
    case Maison = 'maison';
    case Studio = 'studio';
    case Terrain = 'terrain';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Appartement => 'Appartement',
            self::Maison => 'Maison',
            self::Studio => 'Studio',
            self::Terrain => 'Terrain',
            self::Autre => 'Autre',
        };
    }

    /** @return list<array<string, string>> */
    public static function catalogue(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ], self::cases());
    }
}
