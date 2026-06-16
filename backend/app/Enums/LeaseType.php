<?php

namespace App\Enums;

/**
 * Types de baux d'habitation régis par la loi n° 89-462 du 6 juillet 1989.
 */
enum LeaseType: string
{
    case Nu = 'nu';        // Location vide (titre Ier)
    case Meuble = 'meuble';    // Location meublée (titre Ier bis)
    case Etudiant = 'etudiant';  // Bail meublé étudiant de 9 mois (art. 25-7)
    case Mobilite = 'mobilite';  // Bail mobilité de 1 à 10 mois (art. 25-12)

    /**
     * Plafond du dépôt de garantie, en nombre de mois de loyer hors charges.
     * Art. 22 (nu : 1 mois), art. 25-6 (meublé : 2 mois), art. 25-13 (mobilité : interdit).
     */
    public function depositCapInMonths(): int
    {
        return match ($this) {
            self::Nu => 1,
            self::Meuble, self::Etudiant => 2,
            self::Mobilite => 0,
        };
    }

    /**
     * Durée contractuelle minimale en mois (bailleur personne physique).
     * Art. 10 (nu : 3 ans), art. 25-7 (meublé : 1 an, étudiant : 9 mois), art. 25-12 (mobilité : 1 mois).
     */
    public function minDurationInMonths(): int
    {
        return match ($this) {
            self::Nu => 36,
            self::Meuble => 12,
            self::Etudiant => 9,
            self::Mobilite => 1,
        };
    }

    /**
     * Durée contractuelle maximale en mois, null si sans limite.
     * Art. 25-7 (étudiant : 9 mois non renouvelable), art. 25-12 (mobilité : 10 mois).
     */
    public function maxDurationInMonths(): ?int
    {
        return match ($this) {
            self::Etudiant => 9,
            self::Mobilite => 10,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nu => 'Location vide',
            self::Meuble => 'Location meublée',
            self::Etudiant => 'Bail meublé étudiant',
            self::Mobilite => 'Bail mobilité',
        };
    }
}
