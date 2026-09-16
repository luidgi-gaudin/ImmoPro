<?php

namespace App\Support\Jwt;

/**
 * Reconstitue une clé publique RSA au format PEM à partir de son module et de
 * son exposant, tels que les publie un jeu de clés JWKS.
 *
 * Google et Apple publient leurs clés en JSON (`{"n": "...", "e": "AQAB"}`),
 * alors qu'OpenSSL n'accepte que du PEM. Il faut donc réencoder la clé en DER —
 * une structure `SubjectPublicKeyInfo` — puis l'envelopper en base64.
 *
 * Écrit à la main plutôt qu'apporté par une bibliothèque : c'est une quarantaine
 * de lignes d'encodage figées par la norme, contre une dépendance de plus dans
 * le chemin d'authentification, c'est-à-dire à l'endroit du code où une
 * bibliothèque compromise fait le plus de dégâts.
 */
final class RsaPublicKey
{
    /**
     * Identifiant d'algorithme DER pour `rsaEncryption` (OID 1.2.840.113549.1.1.1),
     * suivi du paramètre NULL qu'impose la RFC 3279. Constant : il ne dépend pas
     * de la clé.
     */
    private const RSA_ALGORITHM_IDENTIFIER = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    /**
     * @param  string  $modulus  Module RSA, en base64url (champ `n` du JWKS).
     * @param  string  $exponent  Exposant public, en base64url (champ `e`).
     */
    public static function toPem(string $modulus, string $exponent): ?string
    {
        $n = self::base64UrlDecode($modulus);
        $e = self::base64UrlDecode($exponent);

        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }

        $publicKey = self::sequence(self::integer($n).self::integer($e));

        // Le préfixe \x00 de la chaîne de bits déclare « zéro bit inutilisé ».
        $spki = self::sequence(
            self::RSA_ALGORITHM_IDENTIFIER.self::bitString("\x00".$publicKey)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function sequence(string $contents): string
    {
        return "\x30".self::length($contents).$contents;
    }

    private static function bitString(string $contents): string
    {
        return "\x03".self::length($contents).$contents;
    }

    /**
     * Entier DER.
     *
     * Les entiers DER sont signés : un premier octet dont le bit de poids fort
     * vaut 1 serait lu comme un nombre négatif. Un octet nul est donc ajouté
     * devant, ce qui est le cas de la quasi-totalité des modules RSA.
     */
    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length($bytes).$bytes;
    }

    /**
     * Longueur DER.
     *
     * En deçà de 128 octets, la longueur tient dans un octet. Au-delà, un
     * premier octet annonce combien d'octets la portent — forme dite longue,
     * indispensable ici puisqu'un module RSA de 2048 bits fait 256 octets.
     */
    private static function length(string $contents): string
    {
        $length = strlen($contents);

        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
