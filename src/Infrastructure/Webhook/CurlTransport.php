<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Webhook;

use Click\Cms\Domain\Webhook\HttpTransport;
use Click\Cms\Domain\Webhook\TransportResult;

/**
 * Delivery over ext-curl, the preferred path where it exists.
 *
 * Preferred over streams because it can be told not to follow redirects, given
 * a separate connect timeout, and made to verify TLS explicitly rather than by
 * inheriting whatever the stream context defaults happen to be on this build.
 */
final class CurlTransport implements HttpTransport
{
    public function post(string $url, string $body, array $headers, int $timeoutSeconds): TransportResult
    {
        $handle = curl_init();
        if ($handle === false) {
            return TransportResult::failed('curl could not be initialised.');
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $formatted,
            CURLOPT_RETURNTRANSFER => true,

            // Not followed, deliberately. The URL was checked against
            // `WebhookUrlPolicy` before this call; following a redirect would
            // land somewhere that was never checked, which is the SSRF hole the
            // policy exists to close, reopened by a receiver's 302.
            CURLOPT_FOLLOWLOCATION => false,

            CURLOPT_TIMEOUT => $timeoutSeconds,
            // A separate, shorter connect timeout so an unroutable host fails
            // fast instead of spending the whole budget on a TCP handshake that
            // is never going to complete.
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),

            // Stated rather than assumed. These are curl's defaults, but they
            // are also the two settings most often disabled in a config file by
            // somebody debugging a certificate, and a webhook carrying a
            // signature over an unverified connection is worth being explicit
            // about.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // The body is discarded — nothing here reads it — but it still has
            // to be received for the request to complete. Capping it stops a
            // receiver answering with a gigabyte from taking the process down.
            CURLOPT_BUFFERSIZE => 16384,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        curl_close($handle);

        if ($response === false || $status === 0) {
            return TransportResult::failed($error !== '' ? $error : 'The request did not complete.');
        }

        return TransportResult::responded($status);
    }
}
