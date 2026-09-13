<?php

namespace App\Enums;

/**
 * Régime de propriété de l'immeuble dont dépend le bien.
 *
 * En copropriété, le bailleur n'est pas seul maître du bâti : charges
 * récupérables, travaux votés en assemblée et règlement de copropriété
 * s'imposent à lui, et le syndic est l'interlocuteur pour tout ce qui dépasse
 * les parties privatives. En monopropriété, ces informations n'existent pas —
 * d'où un champ distinct plutôt qu'un syndic laissé vide.
 */
enum OwnershipType: string
{
    case Copropriete = 'copropriete';
    case Monopropriete = 'monopropriete';

    public function label(): string
    {
        return match ($this) {
            self::Copropriete => 'Copropriété',
            self::Monopropriete => 'Monopropriété',
        };
    }

    /** Le bloc « syndicat » n'a de sens que pour un bien en copropriété. */
    public function requiresSyndic(): bool
    {
        return $this === self::Copropriete;
    }
}
