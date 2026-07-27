<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * ## Why this is hand-rolled, when hand-rolled crypto is a bad idea
 *
 * It is, and this is the case where the usual advice does not apply. RFC 6238 is
 * HMAC-SHA1 over a counter derived from the clock; `hash_hmac` does the actual
 * cryptography and this file does the counter arithmetic and the truncation.
 * The standard publishes test vectors, every authenticator app implements the
 * same thing, and the tests here check against those vectors.
 *
 * What makes it acceptable is that the failure mode is *loud*. A wrong
 * implementation does not produce subtly weak codes that look fine — it produces
 * codes no app agrees with, which the first enrolment attempt reveals. That is
 * the opposite of, say, hand-rolling a password hash.
 *
 * The alternative was requiring a Composer package so that a site could turn on
 * two-factor, which would break the no-runtime-dependencies rule that is the
 * point of the project.
 *
 * ## SHA-1, deliberately
 *
 * RFC 6238 permits SHA-256 and SHA-512, and this uses SHA-1 anyway, because
 * Google Authenticator and most of its imitators only implement SHA-1 and
 * silently ignore the `algorithm` parameter in the enrolment URI. Choosing the
 * stronger hash would produce an enrolment that most apps accept and then
 * generate wrong codes for. SHA-1's weaknesses are collision weaknesses, which
 * do not apply to HMAC — HMAC-SHA1 is not broken, and the code it produces is
 * six digits valid for thirty seconds regardless.
 */
final class Totp
{
    /** Seconds per code. Thirty is the RFC default and what every app assumes. */
    public const PERIOD = 30;

    /** Six, likewise: apps show six and people expect six. */
    public const DIGITS = 6;

    /**
     * How many steps either side of now to accept.
     *
     * One. A phone's clock drifts and a person takes a few seconds to read and
     * type, so zero would reject correct codes routinely. More than one widens
     * the guessing window for no usability gain: at ±1 an attacker guessing
     * blind has three codes in a million rather than one, which is still far
     * below what the login throttle allows anyone to try.
     */
    public const DRIFT_STEPS = 1;

    private function __construct() {}

    /**
     * A fresh secret, in the base32 an authenticator app expects.
     *
     * 160 bits, which is what RFC 4226 recommends and what every app is built
     * around.
     */
    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    /**
     * The code for a raw (already decoded) secret at a point in time.
     *
     * Takes raw bytes rather than base32 so the RFC's own vectors — which state
     * the secret as an ASCII string — can be used directly, and so a caller
     * cannot accidentally hash the base32 text instead of the key it stands for.
     * That second mistake is the single most common way this is got wrong, and
     * it produces an implementation that is self-consistent and agrees with
     * nothing.
     */
    public static function codeAt(string $rawSecret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::PERIOD);

        // The counter is a 64-bit big-endian integer. `pack('J')` is exactly
        // that, and avoids the manual byte loop this is usually written as.
        $hash = hash_hmac('sha1', pack('J', $counter), $rawSecret, true);

        // Dynamic truncation, RFC 4226 section 5.3: the low nibble of the last
        // byte picks where to read four bytes from, and the top bit of those is
        // masked off so the result is positive on every platform.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        // Padded, because a code with a leading zero is still six digits and
        // dropping it would make one code in ten fail to match what the app
        // displays.
        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Whether a typed code is right for this secret, now.
     *
     * @param string $rawSecret Raw bytes, as {@see codeAt()} takes.
     */
    public static function verify(string $rawSecret, string $code, int $now): bool
    {
        // People type the space they see on screen.
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return false;
        }

        for ($step = -self::DRIFT_STEPS; $step <= self::DRIFT_STEPS; $step++) {
            // Constant time. A code is six digits, so a timing side channel is
            // not the most pressing problem here, but the comparison is free to
            // do properly and an early-exit `===` in authentication code is the
            // habit worth not having.
            if (hash_equals(self::codeAt($rawSecret, $now + ($step * self::PERIOD)), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `otpauth://` string an authenticator app reads, usually from a QR code.
     *
     * The label is `Issuer:account` and both halves are escaped: an issuer
     * containing a colon or a slash would otherwise change what the app thinks
     * the account is called, and two accounts that collide in an app's list are
     * two accounts whose codes get mixed up.
     *
     * The issuer appears twice — in the label and as a parameter — which looks
     * redundant and is not: older apps read one, newer apps read the other, and
     * omitting either produces an entry labelled with something unhelpful in
     * somebody's authenticator.
     */
    public static function enrolmentUri(string $base32Secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        $parameters = http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$parameters}";
    }
}
