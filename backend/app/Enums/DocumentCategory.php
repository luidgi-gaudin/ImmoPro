<?php

namespace App\Enums;

use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;

/**
 * Nature réglementaire ou métier d'une pièce jointe.
 *
 * La catégorie n'est pas décorative : c'est elle qui détermine à quelle entité
 * le document peut être rattaché, s'il porte une date de fin de validité, et
 * donc s'il doit déclencher un rappel. « bail_signe.pdf » ne dit rien à
 * l'application ; `BailSigne` lui dit tout.
 */
enum DocumentCategory: string
{
    case BailSigne = 'bail_signe';
    case EtatDesLieux = 'etat_des_lieux';
    case EtatDesLieuxEntree = 'etat_des_lieux_entree';
    case EtatDesLieuxSortie = 'etat_des_lieux_sortie';
    case Quittance = 'quittance';
    case RecuPaiement = 'recu_paiement';
    case JustificatifPaiement = 'justificatif_paiement';
    case Dpe = 'dpe';
    case Diagnostic = 'diagnostic';
    case AssuranceHabitation = 'assurance_habitation';
    case AttestationAssurance = 'attestation_assurance';
    case PieceIdentite = 'piece_identite';
    case JustificatifRevenus = 'justificatif_revenus';
    case JustificatifDomicile = 'justificatif_domicile';
    case ActeCaution = 'acte_caution';
    case Mandat = 'mandat';
    case ReglementCopropriete = 'reglement_copropriete';
    case TaxeFonciere = 'taxe_fonciere';
    case Facture = 'facture';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::BailSigne => 'Bail signé',
            self::EtatDesLieux => 'État des lieux',
            self::EtatDesLieuxEntree => 'État des lieux d\'entrée',
            self::EtatDesLieuxSortie => 'État des lieux de sortie',
            self::Quittance => 'Quittance de loyer',
            self::RecuPaiement => 'Reçu de paiement',
            self::JustificatifPaiement => 'Justificatif de paiement',
            self::Dpe => 'Diagnostic de performance énergétique',
            self::Diagnostic => 'Diagnostic technique',
            self::AssuranceHabitation => 'Assurance propriétaire non occupant',
            self::AttestationAssurance => 'Attestation d\'assurance du locataire',
            self::PieceIdentite => 'Pièce d\'identité',
            self::JustificatifRevenus => 'Justificatif de revenus',
            self::JustificatifDomicile => 'Justificatif de domicile',
            self::ActeCaution => 'Acte de cautionnement',
            self::Mandat => 'Mandat de gestion',
            self::ReglementCopropriete => 'Règlement de copropriété',
            self::TaxeFonciere => 'Taxe foncière',
            self::Facture => 'Facture de travaux',
            self::Autre => 'Autre document',
        };
    }

    /**
     * Entités auxquelles cette catégorie a un sens.
     *
     * Une pièce d'identité se range dans un dossier locataire, pas sur un
     * portefeuille ; un règlement de copropriété appartient au bien, pas au
     * locataire. Proposer partout toutes les catégories transformerait le menu
     * en inventaire et laisserait ranger les pièces n'importe où.
     *
     * @return list<class-string>
     */
    public function attachableTo(): array
    {
        return match ($this) {
            self::BailSigne, self::EtatDesLieux, self::EtatDesLieuxEntree,
            self::EtatDesLieuxSortie, self::Quittance, self::RecuPaiement,
            self::JustificatifPaiement, self::AttestationAssurance,
            self::ActeCaution => [Lease::class],

            self::Dpe, self::Diagnostic, self::ReglementCopropriete,
            self::TaxeFonciere, self::AssuranceHabitation => [Property::class],

            self::PieceIdentite, self::JustificatifRevenus,
            self::JustificatifDomicile => [Tenant::class],

            self::Mandat => [Portfolio::class, Property::class],

            self::Facture => [Property::class, Lease::class],

            self::Autre => [Lease::class, Property::class, Tenant::class, Portfolio::class],
        };
    }

    /**
     * Durée de validité en mois, quand la loi en fixe une.
     *
     * Sert à proposer une date d'expiration par défaut au dépôt, et à signaler
     * les pièces périmées. Le DPE vaut dix ans depuis la réforme de 2021 ; une
     * attestation d'assurance se renouvelle chaque année.
     */
    public function validityMonths(): ?int
    {
        return match ($this) {
            self::Dpe => 120,
            self::AttestationAssurance, self::AssuranceHabitation => 12,
            self::Diagnostic => 36,
            default => null,
        };
    }

    /**
     * Le locataire peut-il déposer cette pièce depuis son espace ?
     *
     * Le dépôt par le locataire est un droit d'écriture sur le dossier du
     * bailleur : il doit rester borné à ce qu'on lui demande de fournir.
     * Une attestation d'assurance, oui — c'est une obligation annuelle
     * (art. 7 g de la loi de 1989). Une quittance, non : c'est le bailleur qui
     * l'émet, et laisser déposer la sienne ouvrirait la porte à un faux
     * justificatif de paiement versé au dossier.
     */
    public function isTenantUploadable(): bool
    {
        return match ($this) {
            self::AttestationAssurance,
            self::JustificatifPaiement,
            self::PieceIdentite,
            self::JustificatifRevenus,
            self::JustificatifDomicile,
            self::EtatDesLieuxEntree,
            self::EtatDesLieuxSortie,
            self::Autre => true,
            default => false,
        };
    }

    /**
     * Catégories que le locataire peut déposer depuis son espace.
     *
     * @return list<self>
     */
    public static function tenantUploadable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $category) => $category->isTenantUploadable()
        ));
    }

    /**
     * Catalogue destiné au front, pour que le menu déroulant et les règles de
     * rattachement viennent d'une seule source.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $category) => [
            'value' => $category->value,
            'label' => $category->label(),
            'attachable_to' => array_map(
                fn (string $class) => strtolower(class_basename($class)),
                $category->attachableTo()
            ),
            'validity_months' => $category->validityMonths(),
            'tenant_uploadable' => $category->isTenantUploadable(),
        ], self::cases());
    }
}
