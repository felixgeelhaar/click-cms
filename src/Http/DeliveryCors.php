<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * Decides whether a cross-origin request may read the delivery API from a
 * browser, and what CORS headers to answer with.
 *
 * A cross-cutting security concern that had grown into the kernel. It is its own
 * unit now because the rule is subtle and getting it wrong is dangerous in a
 * quiet way: open one origin too many and any page on that origin can read a
 * site's content; answer a preflight for a path that is not actually public and
 * the browser is told a private endpoint is reachable.
 *
 * Three guarantees, each pinned by a test:
 *  - only origins the site has explicitly listed are answered — never a wildcard,
 *    because the delivery API is anonymous and a public read API any page can
 *    call is a decision to make on purpose;
 *  - only genuinely public paths are opened, decided by the same {@see ApiGuard}
 *    the rest of the request uses, so CORS can never widen what is reachable;
 *  - credentials are never allowed, so a cross-origin request cannot carry a
 *    session and become a way around CSRF.
 *
 * The decision is pure: it returns the headers to send rather than sending them,
 * so it can be reasoned about and tested without a live response. The kernel
 * emits them.
 */
final class DeliveryCors
{
    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private readonly array $allowedOrigins,
        private readonly ApiGuard $guard,
    ) {}

    /**
     * @param array<string, mixed> $server The request's $_SERVER.
     * @return array{headers: array<string, string>, preflight: array<string, mixed>|null}|null
     *         Null when CORS does not apply (same-origin, an origin not on the
     *         list, or a path that is not public). Otherwise the headers to emit,
     *         and a 204 response to return when this is a preflight.
     */
    public function evaluate(string $path, string $method, array $server): ?array
    {
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || !in_array($origin, $this->allowedOrigins, true)) {
            return null;
        }

        // A preflight asks about the request that follows, so the answer has to
        // consider that method rather than OPTIONS itself.
        $intended = $method === 'OPTIONS'
            ? strtoupper((string) ($server['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? 'GET'))
            : $method;

        if (!$this->guard->isPublic($path, $intended)) {
            return null;
        }

        return [
            'headers' => [
                'Access-Control-Allow-Origin' => $origin,
                'Vary' => 'Origin',
                'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
                'Access-Control-Max-Age' => '600',
            ],
            'preflight' => $method === 'OPTIONS'
                ? ['status' => 204, 'raw' => true, 'html' => '']
                : null,
        ];
    }
}
