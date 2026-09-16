<?php

namespace App\Enums;

/**
 * Nature de la pièce d'identité présentée au dossier.
 *
 * Distincte du fichier téléversé : le scan se range dans les documents, la
 * nature et le numéro se lisent sur la fiche sans avoir à ouvrir un PDF.
 */
enum IdentityDocumentType: string
{
    case CarteIdentite = 'carte_identite';
    case Passeport = 'passeport';
    case TitreSejour = 'titre_sejour';
    case PermisConduire = 'permis_conduire';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::CarteIdentite => 'Carte nationale d\'identité',
            self::Passeport => 'Passeport',
            self::TitreSejour => 'Titre de séjour',
            self::PermisConduire => 'Permis de conduire',
            self::Autre => 'Autre pièce',
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
