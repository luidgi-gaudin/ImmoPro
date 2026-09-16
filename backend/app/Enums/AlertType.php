<?php

namespace App\Enums;

/**
 * Nature d'une alerte.
 *
 * Les quatre premières s'adressent au bailleur et naissent du balayage
 * périodique (voir App\Services\Alerts\AlertScanner). Les suivantes
 * s'adressent au locataire et naissent d'un geste : une relance envoyée, une
 * pièce déposée. Le destinataire se lit sur `alerts.user_id` — c'est lui, et
 * lui seul, qui décide de qui voit quoi.
 */
enum AlertType: string
{
    /* --- Bailleur ---------------------------------------------------- */

    case LoyerImpaye = 'loyer_impaye';       // loyer non enregistré à J+3
    case RevisionIrl = 'revision_irl';       // révision annuelle IRL à venir (J-30)
    case FinBail = 'fin_bail';               // approche de la fin du bail (6/3/1 mois)
    case DpeExpiration = 'dpe_expiration';   // DPE arrivant à expiration (validité 10 ans)
    case DepotLocataire = 'depot_locataire'; // pièce téléversée depuis l'espace locataire

    /* --- Locataire ---------------------------------------------------- */

    case RelanceLoyer = 'relance_loyer';             // relance envoyée par le bailleur
    case QuittanceDisponible = 'quittance_disponible';
    case DocumentPartage = 'document_partage';       // pièce déposée par le bailleur
    case InformationBailleur = 'information_bailleur';

    /**
     * Sujet de préférence correspondant.
     *
     * Le lien est explicite plutôt que déduit d'une égalité de chaînes : les
     * deux énumérations ont grandi séparément, et un rapprochement par nom
     * romprait en silence à la première divergence — l'alerte partirait alors
     * sans regarder les préférences.
     */
    public function topic(): NotificationTopic
    {
        return match ($this) {
            self::LoyerImpaye => NotificationTopic::LoyerImpaye,
            self::RevisionIrl => NotificationTopic::RevisionIrl,
            self::FinBail => NotificationTopic::FinBail,
            self::DpeExpiration => NotificationTopic::ExpirationDocument,
            self::DepotLocataire => NotificationTopic::DepotLocataire,
            self::RelanceLoyer => NotificationTopic::RelanceLoyer,
            self::QuittanceDisponible => NotificationTopic::QuittanceDisponible,
            self::DocumentPartage => NotificationTopic::DocumentPartage,
            self::InformationBailleur => NotificationTopic::InformationBailleur,
        };
    }
}
