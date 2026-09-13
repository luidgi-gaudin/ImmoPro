<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;

/**
 * Identité extraite d'un jeton signé par Google ou Apple, une fois la
 * signature et les revendications vérifiées.
 *
 * Rien de ce que le client a envoyé ne survit ici : chaque champ vient du
 * jeton, et le jeton a été validé.
 */
final readonly class VerifiedIdentity
{
    public function __construct(
        public SocialProvider $provider,

        /**
         * Identifiant stable du compte chez le fournisseur (revendication `sub`).
         *
         * C'est la seule clé de rattachement fiable. L'adresse ne peut pas jouer
         * ce rôle : Apple permet de la relayer derrière un alias jetable, et une
         * adresse changée chez Google désignerait toujours la même personne.
         */
        public string $subject,

        public ?string $email,

        /**
         * Le fournisseur atteste-t-il de l'adresse ?
         *
         * Sans cette attestation, rattacher un compte existant sur la seule
         * égalité des adresses permettrait de prendre la main sur le compte de
         * n'importe qui, en déclarant son adresse chez un fournisseur qui ne la
         * vérifie pas.
         */
        public bool $emailVerified,

        public ?string $name = null,
        public ?string $picture = null,
    ) {}
}
