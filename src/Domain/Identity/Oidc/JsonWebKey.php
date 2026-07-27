<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity\Oidc;

/**
 * One RSA public key from a provider's JWKS, in a form `openssl_verify` accepts.
 *
 * A JWK states the modulus and exponent as base64url integers; OpenSSL wants a
 * PEM. The conversion is DER assembly by hand, which is unpleasant and is here
 * because the alternative is a Composer dependency the project does not take.
 *
 * PHP 8.1 has no `openssl_pkey_new(['n' => …, 'e' => …])` — that arrived later —
 * so building the structure is the portable route, and 8.1 is the floor
 * `composer.json` and every entry in the update feed promise.
 */
final class JsonWebKey
{
    private function __construct(
        public readonly string $id,
        public readonly string $pem,
    ) {}

    /**
     * @param array<string, mixed> $jwk
     */
    public static function fromArray(array $jwk): ?self
    {
        // RSA signing keys only. Anything else — an EC key, an encryption key —
        // is something this CMS has no use for, and a provider legitimately
        // publishes them alongside the ones it signs with.
        if (($jwk['kty'] ?? null) !== 'RSA') {
            return null;
        }

        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            return null;
        }

        $modulus = self::decode($jwk['n'] ?? null);
        $exponent = self::decode($jwk['e'] ?? null);

        if ($modulus === null || $exponent === null) {
            return null;
        }

        $pem = self::toPem($modulus, $exponent);

        if ($pem === null) {
            return null;
        }

        return new self((string) ($jwk['kid'] ?? ''), $pem);
    }

    private static function decode(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * Wrap the modulus and exponent as a DER SubjectPublicKeyInfo, then PEM.
     *
     * Long-winded but mechanical: two INTEGERs in a SEQUENCE make the RSA public
     * key, that goes in a BIT STRING beside the rsaEncryption OID, and the whole
     * thing is base64 in a PEM envelope.
     */
    private static function toPem(string $modulus, string $exponent): ?string
    {
        $key = self::sequence(self::integer($modulus) . self::integer($exponent));

        // The rsaEncryption algorithm identifier, 1.2.840.113549.1.1.1, with the
        // NULL parameters the structure requires. Fixed bytes because it never
        // varies and assembling it from parts would be more code saying less.
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $der = self::sequence($algorithm . self::bitString($key));

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        // Proved rather than assumed: a key that does not load is a key that
        // would fail at verification time, where the failure reads as "bad
        // signature" and sends whoever is debugging it in the wrong direction.
        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private static function integer(string $bytes): string
    {
        // DER integers are signed, so a leading byte with the high bit set would
        // read as negative. A zero byte in front is what every implementation
        // does about it.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function sequence(string $contents): string
    {
        return "\x30" . self::length(strlen($contents)) . $contents;
    }

    private static function bitString(string $contents): string
    {
        // The leading zero is the count of unused bits in the final byte, which
        // is always zero for whole bytes.
        $contents = "\x00" . $contents;

        return "\x03" . self::length(strlen($contents)) . $contents;
    }

    /**
     * DER length: short form under 128, otherwise a byte count followed by the
     * length itself.
     */
    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
