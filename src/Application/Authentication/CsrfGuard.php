<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication;

/**
 * Cross-site request forgery protection for state-changing requests.
 *
 * Without this, any page a signed-in administrator visits can make requests to
 * the CMS as them. On endpoints that install plugins, that turns a forged
 * request into remote code execution — which is why this is not optional and
 * not configurable off.
 *
 * The scheme is a per-session token the browser sends back in a header. A
 * header is used rather than a form field because every request the admin UI
 * makes is `fetch`, and because a cross-origin page cannot set a custom header
 * on a simple request without passing a CORS preflight it will fail.
 *
 * Safe methods are exempt: GET and HEAD must not change state, so there is
 * nothing to forge.
 *
 * There is deliberately no cookie in this scheme. The token is minted into the
 * session and handed to the client by `GET /api/auth/check`; the browser never
 * holds a copy the CMS can read back. A `COOKIE` constant naming `click_csrf`
 * used to sit beside HEADER, referenced by nothing and set by nothing, which
 * described a double-submit-cookie design this does not implement.
 */
final class CsrfGuard
{
    public const HEADER = 'X-Click-CSRF';

    /** Methods that must not change state, and so need no token. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Compare a submitted token against the session's.
     *
     * hash_equals rather than === so that comparison time does not leak how
     * much of a guess was correct.
     */
    public static function matches(?string $expected, ?string $given): bool
    {
        if (!is_string($expected) || $expected === '' || !is_string($given) || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }

    /**
     * Read the token a client submitted.
     *
     * Header only. Accepting it from the body as well would let a plain HTML
     * form post carry it, which is exactly the shape of request this is meant
     * to reject.
     */
    public static function tokenFromRequest(array $server): ?string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper(self::HEADER));

        $value = $server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
