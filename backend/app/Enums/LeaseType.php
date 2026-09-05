<?php

namespace App\Enums;

/**
 * Types de baux d'habitation régis par la loi n° 89-462 du 6 juillet 1989.
 *
 * Trois durées sont à distinguer, et les confondre revient à refuser des
 * contrats parfaitement licites :
 *
 * - le **plancher** (`floorDurationInMonths`), en dessous duquel aucune
 *   circonstance ne permet de descendre ;
 * - la **durée de droit commun** (`standardDurationInMonths`), celle que la loi
 *   pose par défaut mais à laquelle elle admet des exceptions ;
 * - le **plafond** (`maxDurationInMonths`), quand le type est borné.
 *
 * L'application rejette ce qui sort du plancher et du plafond. Entre le
 * plancher et la durée de droit commun, elle accepte et signale : le motif qui
 * autorise la réduction (art. 11) vit dans le contrat signé, pas dans un
 * formulaire, et le logiciel n'a pas à trancher à la place du bailleur.
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
     * Durée contractuelle de droit commun, en mois.
     *
     * Art. 10 (nu : 3 ans pour un bailleur personne physique, 6 ans pour une
     * personne morale), art. 25-7 (meublé : 1 an ; étudiant : 9 mois).
     *
     * Le bail mobilité n'a pas de durée de droit commun : la loi lui fixe un
     * intervalle, pas une valeur de référence.
     */
    public function standardDurationInMonths(): ?int
    {
        return match ($this) {
            self::Nu => 36,
            self::Meuble => 12,
            self::Etudiant => 9,
            self::Mobilite => null,
        };
    }

    /**
     * Durée en dessous de laquelle le contrat n'est licite dans aucun cas.
     *
     * Elle ne se confond pas avec la durée de droit commun. Un bail vide peut
     * légalement être conclu pour moins de trois ans — mais jamais moins d'un an
     * — lorsqu'un événement familial ou professionnel précis justifie que le
     * bailleur ait à reprendre le logement (art. 11). Refuser ces baux, comme le
     * faisait l'application, rendait impossible d'enregistrer un contrat
     * pourtant valable.
     *
     * Pour le meublé, aucune exception n'existe hors du bail étudiant, qui est
     * un type à part entière ici.
     */
    public function floorDurationInMonths(): int
    {
        return match ($this) {
            self::Nu => 12,       // art. 11 : durée réduite, au moins un an
            self::Meuble => 12,   // art. 25-7 : un an, sans exception
            self::Etudiant => 9,  // art. 25-7 : la réduction s'arrête à neuf mois
            self::Mobilite => 1,  // art. 25-12
        };
    }

    /**
     * Durée contractuelle maximale en mois, null si sans limite.
     *
     * Le bail mobilité est strictement borné à dix mois et non renouvelable
     * (art. 25-12).
     *
     * Le bail étudiant, lui, n'est pas figé à neuf mois : neuf mois est la durée
     * *réduite* que la loi autorise, pas une valeur imposée. Au-delà d'un an, on
     * quitte le régime et il faut choisir « location meublée ». Une année
     * universitaire courant de septembre à juin — dix mois — était refusée par
     * l'ancienne borne à neuf mois.
     */
    public function maxDurationInMonths(): ?int
    {
        return match ($this) {
            self::Etudiant => 12,
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
