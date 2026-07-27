<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * Making one HTTP request, expressed narrowly enough to fake.
 *
 * A port in the domain with implementations outside it, for the usual reason
 * and one specific one: without it, every test of the sending logic would need
 * a live HTTP server, so the retry rules — the part most likely to be wrong —
 * would be the part least likely to be tested.
 *
 * Narrow on purpose. It does not model redirects, cookies, authentication,
 * streaming or anything else a general HTTP client offers, because a webhook
 * delivery is one POST that either lands or does not. A redirect in particular
 * is deliberately *not* followed: the URL was checked against
 * {@see WebhookUrlPolicy} before the request, and following a 302 would land
 * somewhere that never was.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers, int $timeoutSeconds): TransportResult;
}
