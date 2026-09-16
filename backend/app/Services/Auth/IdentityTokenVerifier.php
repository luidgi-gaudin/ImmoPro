<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use App\Support\Jwt\RsaPublicKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vérification d'un jeton d'identité OpenID Connect (Google, Apple).
 *
 * Le client envoie le jeton obtenu du fournisseur ; le serveur le vérifie
 * lui-même. Il ne s'agit pas de prudence excessive : un jeton d'identité est un
 * simple texte, et rien n'empêche d'en fabriquer un qui affirme n'importe quoi.
 * Ce qui vaut preuve, c'est la signature du fournisseur, contrôlée ici contre
 * les clés publiques qu'il publie.
 *
 * Quatre contrôles, dont aucun ne peut sauter :
 *
 *   1. **Signature.** Sinon le jeton n'engage personne.
 *   2. **Émetteur** (`iss`). Sinon une clé d'un autre fournisseur ferait foi.
 *   3. **Destinataire** (`aud`). Le plus facile à oublier, et le plus grave :
 *      sans lui, un jeton parfaitement valide émis pour *une autre application*
 *      est accepté. N'importe qui peut faire signer par Google un jeton pour
 *      son propre service, puis le présenter ici et se connecter sous
 *      l'identité de sa victime.
 *   4. **Fraîcheur** (`exp`, `iat`). Sinon un jeton intercepté reste utilisable
 *      indéfiniment.
 *
 * L'algorithme est imposé à RS256, jamais lu depuis l'en-tête du jeton. Accepter
 * l'algorithme annoncé par le jeton lui-même est la faille classique des
 * bibliothèques JWT : il suffit de déclarer `alg: none`, ou de signer en HMAC
 * avec la clé publique — publique, donc connue — pour que la vérification passe.
 */
class IdentityTokenVerifier
{
    /** Tolérance d'horloge, en secondes, entre le serveur et le fournisseur. */
    private const CLOCK_SKEW = 60;

    /** Durée de mise en cache d'un jeu de clés, en secondes. */
    private const JWKS_TTL = 21600; // 6 heures

    /**
     * @param  string|null  $expectedNonce  Valeur à retrouver dans le jeton, quand
     *                                      le client en a fourni une à l'ouverture
     *                                      de session. Protège du rejeu.
     *
     * @throws ValidationException
     */
    public function verify(SocialProvider $provider, string $idToken, ?string $expectedNonce = null): VerifiedIdentity
    {
        if (! $provider->isConfigured()) {
            $this->fail("La connexion {$provider->label()} n'est pas configurée sur ce serveur.");
        }

        [$header, $payload] = $this->parse($idToken, $provider);

        $this->assertClaims($payload, $provider, $expectedNonce);

        return new VerifiedIdentity(
            provider: $provider,
            subject: (string) $payload['sub'],
            email: isset($payload['email']) ? (string) $payload['email'] : null,
            emailVerified: $this->readEmailVerified($payload),
            name: $this->readName($payload),
            picture: isset($payload['picture']) ? (string) $payload['picture'] : null,
        );
    }

    /**
     * Découpe le jeton, contrôle la signature et rend l'en-tête et la charge.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function parse(string $idToken, SocialProvider $provider): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            $this->fail('Jeton d\'identité malformé.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeSegment($encodedHeader);
        $payload = $this->decodeSegment($encodedPayload);
        $signature = RsaPublicKey::base64UrlDecode($encodedSignature);

        if ($header === null || $payload === null || $signature === null) {
            $this->fail('Jeton d\'identité illisible.');
        }

        // Imposé, jamais négocié : voir la note sur `alg: none` en tête de classe.
        if (($header['alg'] ?? null) !== 'RS256') {
            $this->fail('Algorithme de signature non accepté.');
        }

        $kid = isset($header['kid']) ? (string) $header['kid'] : null;

        if ($kid === null) {
            $this->fail('Jeton d\'identité sans identifiant de clé.');
        }

        $pem = $this->publicKey($provider, $kid);

        if ($pem === null) {
            $this->fail('Clé de signature inconnue du fournisseur.');
        }

        $verified = openssl_verify(
            "{$encodedHeader}.{$encodedPayload}",
            $signature,
            $pem,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            $this->fail('Signature du jeton d\'identité invalide.');
        }

        return [$header, $payload];
    }

    /**
     * Contrôle des revendications.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function assertClaims(array $payload, SocialProvider $provider, ?string $expectedNonce): void
    {
        if (blank($payload['sub'] ?? null)) {
            $this->fail('Jeton d\'identité sans sujet.');
        }

        if (! in_array((string) ($payload['iss'] ?? ''), $provider->issuers(), true)) {
            $this->fail('Émetteur du jeton inattendu.');
        }

        // `aud` est une chaîne ou un tableau selon les fournisseurs.
        $audiences = (array) ($payload['aud'] ?? []);

        if (array_intersect($audiences, $provider->audiences()) === []) {
            $this->fail('Ce jeton n\'a pas été émis pour cette application.');
        }

        $now = time();

        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;

        if ($exp <= 0 || $exp + self::CLOCK_SKEW < $now) {
            $this->fail('Jeton d\'identité expiré. Reprenez la connexion.');
        }

        // Un jeton daté du futur signale une horloge fausse ou un jeton forgé.
        $iat = isset($payload['iat']) ? (int) $payload['iat'] : null;

        if ($iat !== null && $iat - self::CLOCK_SKEW > $now) {
            $this->fail('Jeton d\'identité daté du futur.');
        }

        if ($expectedNonce !== null && ! hash_equals($expectedNonce, (string) ($payload['nonce'] ?? ''))) {
            $this->fail('Jeton d\'identité rejoué ou détourné.');
        }
    }

    /**
     * Clé publique correspondant au `kid`, au format PEM.
     *
     * Les fournisseurs font tourner leurs clés. Un `kid` absent du cache n'est
     * donc pas une erreur mais le signe d'une rotation : le jeu est rechargé une
     * fois avant d'abandonner. Sans cette seconde chance, chaque rotation
     * rendrait la connexion impossible pendant toute la durée du cache.
     */
    private function publicKey(SocialProvider $provider, string $kid): ?string
    {
        $keys = $this->jwks($provider);

        if (! isset($keys[$kid])) {
            $keys = $this->jwks($provider, refresh: true);
        }

        $key = $keys[$kid] ?? null;

        if ($key === null || ($key['kty'] ?? null) !== 'RSA') {
            return null;
        }

        return RsaPublicKey::toPem((string) ($key['n'] ?? ''), (string) ($key['e'] ?? ''));
    }

    /**
     * Jeu de clés du fournisseur, indexé par `kid`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function jwks(SocialProvider $provider, bool $refresh = false): array
    {
        $key = "auth.jwks.{$provider->value}";

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::JWKS_TTL, function () use ($provider): array {
            try {
                $response = Http::timeout(5)->retry(2, 200)->get($provider->jwksUrl());
            } catch (Throwable) {
                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            $indexed = [];

            foreach ((array) $response->json('keys', []) as $entry) {
                if (is_array($entry) && isset($entry['kid'])) {
                    $indexed[(string) $entry['kid']] = $entry;
                }
            }

            return $indexed;
        });
    }

    /** @return array<string, mixed>|null */
    private function decodeSegment(string $segment): ?array
    {
        $json = RsaPublicKey::base64UrlDecode($segment);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * `email_verified` arrive tantôt en booléen, tantôt en chaîne « true » —
     * Apple emploie la seconde forme. Un `if ($payload['email_verified'])` naïf
     * traiterait la chaîne « false » comme vraie.
     *
     * @param  array<string, mixed>  $payload
     */
    private function readEmailVerified(array $payload): bool
    {
        return filter_var(
            $payload['email_verified'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Nom affichable.
     *
     * Apple ne met pas de nom dans le jeton : il ne le transmet qu'une seule
     * fois, à la toute première autorisation, et hors du jeton. Le client le
     * relaie alors séparément — d'où l'absence assumée de nom ici pour Apple.
     *
     * @param  array<string, mixed>  $payload
     */
    private function readName(array $payload): ?string
    {
        foreach (['name', 'given_name'] as $claim) {
            if (filled($payload[$claim] ?? null)) {
                return (string) $payload[$claim];
            }
        }

        return null;
    }

    /** @throws ValidationException */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['id_token' => $message])->status(422);
    }
}
