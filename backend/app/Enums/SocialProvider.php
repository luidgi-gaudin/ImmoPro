<?php

namespace App\Enums;

/**
 * Fournisseurs d'identité acceptés pour l'inscription et la connexion.
 *
 * Chaque fournisseur signe ses jetons d'identité avec des clés qu'il publie, et
 * c'est cette signature — pas la parole du client — qui fait foi. Le jeu de
 * clés et l'émetteur attendus vivent donc ici, à côté du fournisseur.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Apple => 'Apple',
        };
    }

    /** Émetteurs acceptés dans la revendication `iss` du jeton d'identité. */
    /** @return list<string> */
    public function issuers(): array
    {
        return match ($this) {
            // Google émet historiquement sous les deux formes.
            self::Google => ['https://accounts.google.com', 'accounts.google.com'],
            self::Apple => ['https://appleid.apple.com'],
        };
    }

    /** Jeu de clés publiques du fournisseur, au format JWKS. */
    public function jwksUrl(): string
    {
        return match ($this) {
            self::Google => 'https://www.googleapis.com/oauth2/v3/certs',
            self::Apple => 'https://appleid.apple.com/auth/keys',
        };
    }

    /**
     * Identifiant client attendu dans la revendication `aud`.
     *
     * Sans ce contrôle, un jeton parfaitement valide émis pour une *autre*
     * application serait accepté : n'importe quel développeur pourrait faire
     * signer par Google un jeton pour son propre service, puis le présenter
     * ici et se connecter sous l'identité de sa victime. La vérification de
     * signature seule ne protège de rien ; c'est `aud` qui lie le jeton à
     * cette application.
     *
     * @return list<string>
     */
    public function audiences(): array
    {
        $configured = config("services.{$this->value}.client_id");

        return array_values(array_filter(
            is_array($configured) ? $configured : [$configured]
        ));
    }

    public function isConfigured(): bool
    {
        return $this->audiences() !== [];
    }

    /**
     * Catalogue destiné au navigateur.
     *
     * L'identifiant client en fait partie, et c'est sans danger : il est
     * public par construction, puisque le navigateur doit le présenter au
     * fournisseur pour ouvrir la fenêtre de connexion. Le secret client, lui,
     * n'existe pas dans cette architecture — le serveur ne joue pas le rôle de
     * client OAuth, il se contente de vérifier le jeton rapporté.
     *
     * Seul le premier identifiant est publié quand plusieurs sont déclarés :
     * les suivants servent à accepter des jetons émis pour l'application
     * mobile, que ce navigateur n'a aucune raison d'employer.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $provider) => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'enabled' => $provider->isConfigured(),
            'client_id' => $provider->audiences()[0] ?? null,
        ], self::cases());
    }
}
