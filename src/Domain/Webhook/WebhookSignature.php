<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * How a receiver knows a delivery came from this CMS and not from someone who
 * learned the URL.
 *
 * The threat is forgery, not eavesdropping. HTTPS already keeps a delivery
 * private in transit; what it cannot do is tell the receiver who sent it. A
 * webhook endpoint is a URL that accepts POSTs — usually from anywhere, usually
 * to trigger something expensive like a full site rebuild — so without a
 * signature, anyone who ever sees the address can drive it.
 *
 * ## The scheme, and why it is somebody else's
 *
 * `t=<unix seconds>,v1=<hex hmac-sha256>` over `<t>.<raw body>`, which is what
 * Stripe and GitHub converged on. Copied deliberately rather than invented:
 * every receiving framework already has a verifier for this shape, and the one
 * thing worse than no signature is a bespoke signature a receiver implements
 * slightly wrong.
 *
 * Two properties follow from the details:
 *
 * - **The timestamp is inside the signed material**, not merely alongside it.
 *   That is what makes it a replay defence: a captured delivery re-sent an hour
 *   later still carries its original `t`, and re-signing it needs the secret.
 * - **The body is signed byte for byte**, before any parsing. A receiver that
 *   verifies against re-encoded JSON will get intermittent failures, which is
 *   why {@see verify()} takes the raw string.
 *
 * The `v1` label exists so a second algorithm can be added later without
 * breaking receivers that only understand this one — the header is a list.
 */
final class WebhookSignature
{
    /**
     * How far apart the clocks may be, in seconds.
     *
     * Five minutes each way. Long enough that a server whose clock nobody
     * synchronises still delivers, short enough that a captured delivery is
     * useless by the time it is worth replaying. Symmetric because a receiver
     * running slightly ahead of us is exactly as ordinary as one running behind,
     * and a one-sided tolerance would fail every delivery to half of them.
     */
    public const TOLERANCE_SECONDS = 300;

    private function __construct() {}

    /**
     * The `X-Click-Signature` header for this body.
     */
    public static function sign(string $body, string $secret, int $timestamp): string
    {
        $digest = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return "t={$timestamp},v1={$digest}";
    }

    /**
     * Whether a header genuinely signs this body, recently enough to accept.
     *
     * Shipped with the CMS even though the CMS never calls it: it is what the
     * documentation points a receiver's author at, and a reference verifier
     * that exists and is tested is worth more than a paragraph describing one.
     */
    public static function verify(string $body, string $header, string $secret, int $now): bool
    {
        $parts = self::parse($header);
        if ($parts === null) {
            return false;
        }

        [$timestamp, $digest] = $parts;

        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        // Constant time. A byte-by-byte comparison leaks how much of a guess was
        // right through how long the comparison took, which over enough attempts
        // recovers the digest — and a webhook endpoint is something an attacker
        // can retry against freely.
        return hash_equals(self::sign($body, $secret, $timestamp), $header);
    }

    /**
     * A fresh endpoint secret.
     *
     * Prefixed so it is recognisable in a log or a paste and can be scanned for
     * by secret-detection tooling, which is the same reason every provider that
     * issues these prefixes them.
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private static function parse(string $header): ?array
    {
        $timestamp = null;
        $digest = null;

        foreach (explode(',', $header) as $pair) {
            $bits = explode('=', trim($pair), 2);
            if (count($bits) !== 2) {
                continue;
            }

            [$name, $value] = $bits;

            if ($name === 't' && preg_match('/^\d+$/', $value) === 1) {
                $timestamp = (int) $value;
            }

            if ($name === 'v1' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                $digest = $value;
            }
        }

        return $timestamp === null || $digest === null ? null : [$timestamp, $digest];
    }
}
