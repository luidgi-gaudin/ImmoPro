<?php

namespace App\Enums;

/**
 * Sujets de notification que l'utilisateur peut régler un par un.
 *
 * Chaque sujet dit à quel profil il s'adresse. Proposer au bailleur de couper
 * « relance de loyer reçue » n'aurait aucun sens : c'est le locataire qui la
 * reçoit. Un écran de préférences qui liste des réglages sans effet est pire
 * qu'un écran qui en liste peu.
 *
 * `security` est volontairement absent de cette liste : un changement de mot de
 * passe ou une connexion depuis un nouvel appareil se notifie toujours. Une
 * préférence qui permettrait de le taire servirait surtout à celui qui vient de
 * voler le compte.
 */
enum NotificationTopic: string
{
    /* --- Bailleur ---------------------------------------------------- */

    /** Un loyer n'est pas arrivé à l'échéance. */
    case LoyerImpaye = 'loyer_impaye';

    /** Le bail approche de son terme. */
    case FinBail = 'fin_bail';

    /** La révision annuelle du loyer selon l'IRL est ouverte. */
    case RevisionIrl = 'revision_irl';

    /** Un diagnostic ou une attestation arrive à expiration. */
    case ExpirationDocument = 'expiration_document';

    /** Le locataire a déposé une pièce depuis son espace. */
    case DepotLocataire = 'depot_locataire';

    /* --- Locataire ---------------------------------------------------- */

    /** Relance de loyer envoyée par le bailleur. */
    case RelanceLoyer = 'relance_loyer';

    /** Une quittance vient d'être mise à disposition. */
    case QuittanceDisponible = 'quittance_disponible';

    /** Le bailleur a déposé une pièce dans le dossier. */
    case DocumentPartage = 'document_partage';

    /** Message d'information du bailleur, hors relance. */
    case InformationBailleur = 'information_bailleur';

    public function label(): string
    {
        return match ($this) {
            self::LoyerImpaye => 'Loyer impayé',
            self::FinBail => 'Fin de bail approchante',
            self::RevisionIrl => 'Révision annuelle du loyer',
            self::ExpirationDocument => 'Document arrivant à expiration',
            self::DepotLocataire => 'Pièce déposée par un locataire',
            self::RelanceLoyer => 'Relance de loyer',
            self::QuittanceDisponible => 'Quittance disponible',
            self::DocumentPartage => 'Document mis à disposition',
            self::InformationBailleur => 'Information du bailleur',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LoyerImpaye => 'Quand une échéance de loyer n\'est pas encaissée à la date prévue.',
            self::FinBail => 'Six, trois puis un mois avant le terme d\'un bail.',
            self::RevisionIrl => 'Un mois avant la date anniversaire ouvrant la révision.',
            self::ExpirationDocument => 'Quand un DPE, un diagnostic ou une attestation arrive à échéance.',
            self::DepotLocataire => 'Quand un locataire téléverse une pièce depuis son espace.',
            self::RelanceLoyer => 'Quand votre bailleur vous relance sur une échéance impayée.',
            self::QuittanceDisponible => 'Quand une nouvelle quittance est déposée dans votre espace.',
            self::DocumentPartage => 'Quand votre bailleur ajoute une pièce à votre dossier.',
            self::InformationBailleur => 'Messages d\'information envoyés par votre bailleur.',
        };
    }

    /** Profil auquel ce sujet s'adresse. */
    public function audience(): UserRole
    {
        return match ($this) {
            self::RelanceLoyer, self::QuittanceDisponible,
            self::DocumentPartage, self::InformationBailleur => UserRole::Locataire,
            default => UserRole::Proprietaire,
        };
    }

    /**
     * Canaux activés tant que l'utilisateur n'a rien réglé.
     *
     * Tout est allumé au départ, sauf les sujets purement informatifs qui ne
     * demandent aucune action : les recevoir par courriel transforme la boîte
     * de réception en journal d'activité, et c'est ainsi qu'on finit par ne
     * plus lire non plus ceux qui comptent.
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::DepotLocataire, self::DocumentPartage => [NotificationChannel::Database],
            default => [NotificationChannel::Mail, NotificationChannel::Database],
        };
    }

    /**
     * Sujets proposés à un profil donné.
     *
     * @return list<self>
     */
    public static function forRole(UserRole $role): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $topic) => $topic->audience() === $role
        ));
    }

    /** @return list<array<string, mixed>> */
    public static function catalogue(UserRole $role): array
    {
        return array_map(fn (self $topic) => [
            'value' => $topic->value,
            'label' => $topic->label(),
            'description' => $topic->description(),
            'default_channels' => array_map(
                fn (NotificationChannel $channel) => $channel->value,
                $topic->defaultChannels()
            ),
        ], self::forRole($role));
    }
}
