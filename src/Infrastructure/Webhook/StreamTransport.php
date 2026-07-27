<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Webhook;

use Click\Cms\Domain\Webhook\HttpTransport;
use Click\Cms\Domain\Webhook\TransportResult;

/**
 * Delivery over the HTTP stream wrapper, for installations without ext-curl.
 *
 * The fallback rather than the default, because the wrapper gives less control:
 * there is no separate connect timeout, and error reporting is a warning
 * suppressed into a `false` return rather than a message. What it does give is
 * availability on a stock PHP build with no extensions at all, which is the
 * hosting this project targets.
 */
final class StreamTransport implements HttpTransport
{
    public function post(string $url, string $body, array $headers, int $timeoutSeconds): TransportResult
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= "{$name}: {$value}\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $lines,
                'content' => $body,
                'timeout' => $timeoutSeconds,

                // Not followed, for the reason `CurlTransport` gives: the URL
                // passed the SSRF policy, a redirect target has not.
                'follow_location' => 0,
                'max_redirects' => 0,

                // Without this a 4xx or 5xx makes the wrapper return false with
                // no way to see the status, so a receiver answering 500 would be
                // indistinguishable from one that never answered — and the retry
                // logic wants to tell those apart.
                'ignore_errors' => true,
            ],
            'ssl' => [
                // Stated rather than inherited. Both default to true on a modern
                // build, and both are worth pinning where a signature is being
                // carried.
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Suppressed because a failed request raises a warning *and* returns
        // false, and the warning would reach the CLI's output as noise around a
        // failure this reports properly a line later.
        $response = @file_get_contents($url, false, $context);

        // Set by the wrapper as a side effect, in the local scope, and only when
        // a response actually arrived.
        $headersReceived = $http_response_header ?? null;

        if (!is_array($headersReceived) || $headersReceived === []) {
            return TransportResult::failed(
                $response === false
                    ? 'The request did not complete: no response, timeout or connection failure.'
                    : 'The request completed with no status line.'
            );
        }

        $status = self::statusFrom($headersReceived[0]);

        if ($status === null) {
            return TransportResult::failed('The endpoint answered something that was not HTTP.');
        }

        return TransportResult::responded($status);
    }

    private static function statusFrom(string $statusLine): ?int
    {
        return preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $statusLine, $m) === 1
            ? (int) $m[1]
            : null;
    }
}
