<?php

namespace Tests\Unit;

use App\Support\Jwt\RsaPublicKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reconstitution d'une clé publique RSA à partir d'un JWKS.
 *
 * Le code est court mais son échec serait silencieux et grave : une clé mal
 * reconstituée fait échouer toutes les vérifications de signature, ce qui
 * ressemble à « Google refuse la connexion » et non à « notre encodage DER est
 * faux ». La référence est donc OpenSSL lui-même.
 */
class RsaPublicKeyTest extends TestCase
{
    /** @return array<string, array{0: int}> */
    public static function keySizes(): array
    {
        return [
            // 2048 bits produit un module de 256 octets, ce qui force la forme
            // longue de l'encodage des longueurs DER — le cas où l'implémentation
            // naïve se trompe.
            '2048 bits' => [2048],
            '4096 bits' => [4096],
        ];
    }

    #[DataProvider('keySizes')]
    public function test_the_rebuilt_key_matches_openssl(int $bits): void
    {
        [$details] = $this->keyPair($bits);

        $rebuilt = RsaPublicKey::toPem(
            $this->base64Url($details['rsa']['n']),
            $this->base64Url($details['rsa']['e'])
        );

        $this->assertSame(trim($details['key']), trim((string) $rebuilt));
    }

    #[DataProvider('keySizes')]
    public function test_a_signature_verifies_against_the_rebuilt_key(int $bits): void
    {
        [$details, $private] = $this->keyPair($bits);

        $rebuilt = RsaPublicKey::toPem(
            $this->base64Url($details['rsa']['n']),
            $this->base64Url($details['rsa']['e'])
        );

        openssl_sign('charge utile', $signature, $private, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, openssl_verify('charge utile', $signature, (string) $rebuilt, OPENSSL_ALGO_SHA256));
    }

    public function test_an_unusable_key_yields_nothing(): void
    {
        $this->assertNull(RsaPublicKey::toPem('', 'AQAB'));
        $this->assertNull(RsaPublicKey::toPem('bWFsdmVpbGxhbnQ', ''));
    }

    public function test_base64url_accepts_the_alphabet_without_padding(): void
    {
        $this->assertSame('ok?', RsaPublicKey::base64UrlDecode('b2s_'));
    }

    /** @return array{0: array<string, mixed>, 1: \OpenSSLAsymmetricKey} */
    private function keyPair(int $bits): array
    {
        $private = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        return [openssl_pkey_get_details($private), $private];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
