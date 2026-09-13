<?php

namespace App\Enums;

/**
 * Nature de la garantie apportée par un garant.
 *
 * Le type n'est pas une étiquette : il commande ce que le bailleur peut faire
 * en cas d'impayé. Une caution simple oblige à poursuivre d'abord le locataire
 * (bénéfice de discussion, art. 2305 du code civil) ; une caution solidaire
 * permet de réclamer directement au garant. Visale et une garantie loyers
 * impayés ne se réclament pas à une personne mais à un organisme, sur dossier
 * et dans un délai contractuel.
 */
enum GuaranteeType: string
{
    case CautionSimple = 'caution_simple';
    case CautionSolidaire = 'caution_solidaire';
    case Visale = 'visale';
    case GarantieLoyersImpayes = 'garantie_loyers_impayes';
    case DepotBancaire = 'depot_bancaire';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::CautionSimple => 'Caution simple',
            self::CautionSolidaire => 'Caution solidaire',
            self::Visale => 'Visale (Action Logement)',
            self::GarantieLoyersImpayes => 'Garantie loyers impayés (assurance)',
            self::DepotBancaire => 'Caution bancaire',
            self::Autre => 'Autre garantie',
        };
    }

    /**
     * La garantie est-elle portée par une personne physique ?
     *
     * Visale et une GLI sont portées par un organisme : leur demander une date
     * de naissance ou une pièce d'identité n'a pas de sens, un numéro de
     * contrat en a un.
     */
    public function isPersonal(): bool
    {
        return match ($this) {
            self::Visale, self::GarantieLoyersImpayes => false,
            default => true,
        };
    }

    /** @return list<array<string, mixed>> */
    public static function catalogue(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'is_personal' => $type->isPersonal(),
        ], self::cases());
    }
}
