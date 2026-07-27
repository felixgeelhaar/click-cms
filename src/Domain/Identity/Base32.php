<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity;

/**
 * RFC 4648 base32, because a TOTP secret is exchanged in it and PHP has no
 * built-in.
 *
 * `base64_encode` is in core; base32 is not, and there is no way to reach one
 * without a Composer dependency this project does not take. It is forty lines
 * of table lookup, so the alternative — requiring a package so a site can turn
 * on two-factor — is the wrong trade.
 *
 * The tests pin it to the RFC's own vectors. That matters more here than the
 * size of the code suggests: if the alphabet or the padding is wrong, an
 * authenticator app derives a *different key* from the same QR code, every code
 * it produces is rejected, and nothing in any log says why. A wrong base32 does
 * not fail loudly; it fails as "two-factor is broken for everyone".
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function __construct() {}

    public static function encode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            // The final chunk may be short; padding it with zeros on the right
            // is what the RFC specifies, and is why the `=` count below is
            // derivable from the length rather than tracked separately.
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // Base32 encodes in 40-bit groups, so output is padded to a multiple of
        // eight characters.
        $remainder = strlen($encoded) % 8;

        return $remainder === 0 ? $encoded : $encoded . str_repeat('=', 8 - $remainder);
    }

    /**
     * Decode, tolerantly, or null when the input is not base32 at all.
     *
     * Tolerant on purpose: people retype these from a screen. Lower case, the
     * spaces an app inserts every four characters, and missing padding are all
     * things a person produces, and refusing them would mean refusing a correct
     * secret for being written the way people write.
     *
     * Null rather than an exception, because every caller here is validating
     * user input and would have to catch it anyway.
     */
    public static function decode(string $encoded): ?string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $encoded) ?? '');
        $clean = rtrim($clean, '=');

        if ($clean === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($clean) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                return null;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $raw = '';
        foreach (str_split($bits, 8) as $chunk) {
            // A trailing partial byte is the zero padding the encoder added, not
            // data. Emitting it would append a spurious NUL and produce a key
            // one byte longer than the one that was encoded.
            if (strlen($chunk) === 8) {
                $raw .= chr(bindec($chunk));
            }
        }

        return $raw;
    }
}
