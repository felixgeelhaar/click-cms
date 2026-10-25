<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * Keeps private HTTP responses out of browser and intermediary caches while
 * leaving the explicitly public delivery API and ordinary public pages alone.
 */
final class SensitiveResponseCache
{
    public function __construct(private readonly ?ApiGuard $apiGuard) {}

    /** @return array<string, string> */
    public function headersFor(string $requestPath, string $method, bool $authenticated = false): array
    {
        $path = (string) parse_url($requestPath, PHP_URL_PATH);

        if ((($path === '/admin' || str_starts_with($path, '/admin/'))
                && pathinfo($path, PATHINFO_EXTENSION) === '')
            || str_starts_with($path, '/preview/')
            || preg_match('#^/api/auth(?:/|$)#', $path) === 1
        ) {
            return ['Cache-Control' => 'no-store, private'];
        }

        if (!str_starts_with($path, '/api/')) {
            return [];
        }

        $apiPath = substr($path, strlen('/api/'));
        if ($authenticated) {
            return ['Cache-Control' => 'no-store, private'];
        }

        // Signed preview delivery is public to the API guard by design, but its
        // response contains unpublished content and a bearer token may be in URL.
        if (preg_match('#^collections/[^/]+/preview/[^/]+$#', $apiPath) === 1) {
            return ['Cache-Control' => 'no-store, private'];
        }

        // The guard's allowlist is the source of truth: every other API route
        // carries authenticated management data (including refusals) and must
        // not be stored. When used before boot, fail closed for API responses.
        if ($this->apiGuard === null || !$this->apiGuard->isPublic($apiPath, $method)) {
            return ['Cache-Control' => 'no-store, private'];
        }

        return [];
    }
}
