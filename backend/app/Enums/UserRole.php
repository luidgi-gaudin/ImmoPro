<?php

namespace App\Enums;

/**
 * Profil choisi à l'inscription.
 *
 * Ce n'est pas un rôle d'administration : c'est le point de vue depuis lequel
 * la personne utilise l'application. Le bailleur gère un parc ; le locataire
 * consulte le sien et dépose ses justificatifs. Les deux se connectent par le
 * même formulaire — c'est le rôle porté par le compte qui décide de l'écran
 * d'arrivée et des routes ouvertes.
 */
enum UserRole: string
{
    case Proprietaire = 'proprietaire';
    case Locataire = 'locataire';

    public function label(): string
    {
        return match ($this) {
            self::Proprietaire => 'Propriétaire bailleur',
            self::Locataire => 'Locataire',
        };
    }

    /** Écran d'arrivée après connexion, propre à chaque profil. */
    public function homePath(): string
    {
        return match ($this) {
            self::Proprietaire => '/dashboard',
            self::Locataire => '/espace-locataire',
        };
    }

    /** @return list<array<string, string>> */
    public static function catalogue(): array
    {
        return array_map(fn (self $role) => [
            'value' => $role->value,
            'label' => $role->label(),
        ], self::cases());
    }
}
