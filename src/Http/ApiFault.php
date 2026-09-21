<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * Additive shaping for known API fault responses.
 *
 * Clients today read `error` (a human-readable string). Known faults MAY also
 * carry `code` — a stable machine string such as `forbidden` — without removing
 * `error`. Call sites opt in; nothing rewrites every endpoint. Blank 500s for
 * unexpected faults stay opaque on purpose and must not use this helper.
 */
final class ApiFault
{
    /**
     * @return array{status: int, error: string, code: string}
     */
    public static function of(int $status, string $error, string $code): array
    {
        return [
            'status' => $status,
            'error' => $error,
            'code' => $code,
        ];
    }
}
