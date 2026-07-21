<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Preview;

/**
 * A signed, time-limited permission to view one unpublished page.
 *
 * A preview link hands out unpublished content, so the link itself has to be
 * the credential. Two properties make that safe, and both are enforced here
 * rather than by whoever happens to build the URL:
 *
 * - **It cannot be guessed.** The signature is a 256-bit HMAC over a secret the
 *   server alone holds. There is no shorter path to a working link than
 *   producing that MAC.
 * - **It cannot be pointed somewhere else.** The slug is signed even though it
 *   is not carried in the token. A link for the unfinished pricing page is
 *   therefore useless against the unannounced product page — the two produce
 *   different signatures, and only the server can compute either.
 *
 * Expiry is inside the signed material for the same reason: a holder who could
 * edit the timestamp would hold a permanent link. Because it is signed, the
 * expiry can be read straight from the token without any stored record, which
 * is what lets a link be shared with somebody who has no account at all.
 *
 * Deliberately pure. Hashing is computation, not I/O, so this stays in the
 * domain and can be tested without a filesystem; the secret is read by
 * {@see \Click\Cms\Application\Preview\PreviewLinks}, which is where I/O lives.
 */
final class PreviewToken
{
    /** An hour is long enough to look at a page and short enough to forget. */
    public const DEFAULT_TTL_SECONDS = 3600;

    /**
     * No link outlives a week, whatever a caller asks for.
     *
     * A cap belongs here rather than at the call site because a token that has
     * been issued cannot be withdrawn: nothing is stored, so there is nothing
     * to revoke. The only bound on the damage from a leaked link is how soon it
     * stops working.
     */
    public const MAX_TTL_SECONDS = 604800;

    private function __construct() {}

    /**
     * Mint a token permitting one page to be previewed until it expires.
     */
    public static function issue(
        string $secret,
        string $slug,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?int $now = null,
    ): string {
        $now ??= time();
        $ttl = max(1, min($ttlSeconds, self::MAX_TTL_SECONDS));
        $expiresAt = $now + $ttl;

        return $expiresAt . '.' . self::sign($secret, $slug, $expiresAt);
    }

    /**
     * Whether a presented token really does permit this page, right now.
     *
     * Every failure returns the same false. Saying which of "malformed",
     * "wrong page" and "expired" applies would tell someone probing the
     * endpoint which part of their guess was right.
     */
    public static function accepts(
        string $secret,
        string $slug,
        ?string $token,
        ?int $now = null,
    ): bool {
        // No secret means the server cannot distinguish a real token from an
        // invented one, so it must accept neither. Failing closed here is the
        // difference between an unreadable secret file and an open site.
        if ($secret === '' || !is_string($token) || $token === '') {
            return false;
        }

        if (preg_match('/^([0-9]{1,12})\.([a-f0-9]{64})$/', $token, $matches) !== 1) {
            return false;
        }

        $expiresAt = (int) $matches[1];

        if ($expiresAt <= ($now ?? time())) {
            return false;
        }

        // Constant time, so the signature cannot be recovered a byte at a time
        // by measuring how long a rejection takes.
        return hash_equals(self::sign($secret, $slug, $expiresAt), $matches[2]);
    }

    /**
     * When a token stops working, or null if it is not one we would honour.
     *
     * Only for telling an editor how long their link lasts. It verifies the
     * signature first so an unsigned guess cannot produce a plausible answer.
     */
    public static function expiresAt(string $secret, string $slug, ?string $token, ?int $now = null): ?int
    {
        if (!self::accepts($secret, $slug, $token, $now)) {
            return null;
        }

        return (int) explode('.', (string) $token, 2)[0];
    }

    private static function sign(string $secret, string $slug, int $expiresAt): string
    {
        // The separator is a character a slug cannot contain, so no pair of
        // different (slug, expiry) inputs can produce the same signed string.
        return hash_hmac('sha256', $slug . "\0" . $expiresAt, $secret);
    }
}
