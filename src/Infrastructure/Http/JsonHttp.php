<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Http;

use RuntimeException;

/**
 * Outbound JSON requests, over whichever transport this installation has.
 *
 * Separate from the webhook transport next door despite the overlap, because the
 * two want opposite things. A webhook delivery cares about the status code
 * (so it can decide whether to retry) and never reads the body; this cares only
 * about the body and treats any non-2xx as a failure. Folding them together
 * would produce one interface returning a union of both, and both callers
 * checking for the half they do not want.
 *
 * curl where it exists, the stream wrapper otherwise, and a loud failure when
 * neither does — the same reasoning as `TransportFactory`, and the same
 * no-runtime-dependency constraint that rules out a real HTTP client.
 */
final class JsonHttp
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public function getJson(string $url): array
    {
        return $this->decode($this->request($url, null), $url);
    }

    /**
     * A form-encoded POST answering with JSON, which is what an OAuth token
     * endpoint is.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public function postFormJson(string $url, array $fields): array
    {
        return $this->decode($this->request($url, http_build_query($fields, '', '&', PHP_QUERY_RFC3986)), $url);
    }

    /**
     * @throws RuntimeException
     */
    private function request(string $url, ?string $body): string
    {
        // https only, always. These requests carry a client secret and receive
        // an identity assertion; neither belongs on a cleartext connection, and
        // unlike a webhook endpoint there is no legitimate site configuration
        // where plain HTTP is the only option — an identity provider that
        // cannot offer TLS is not one to trust anyway.
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('Single sign-on endpoints must be https.');
        }

        if (function_exists('curl_init')) {
            return $this->viaCurl($url, $body);
        }

        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return $this->viaStream($url, $body);
        }

        throw new RuntimeException(
            'Single sign-on needs a way to make outbound HTTPS requests, and this installation has '
            . 'none: the curl extension is not loaded and allow_url_fopen is off.'
        );
    }

    private function viaCurl(string $url, ?string $body): string
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new RuntimeException('curl could not be initialised.');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Not followed. A redirect from a token endpoint is somewhere this
            // has not agreed to send a client secret.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];

        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException("The request to the identity provider failed: {$error}");
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("The identity provider answered {$status}.");
        }

        return (string) $response;
    }

    private function viaStream(string $url, ?string $body): string
    {
        $header = "Accept: application/json\r\n";

        $http = [
            'method' => $body === null ? 'GET' : 'POST',
            'timeout' => self::TIMEOUT_SECONDS,
            'follow_location' => 0,
            'max_redirects' => 0,
            'ignore_errors' => true,
        ];

        if ($body !== null) {
            $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $http['content'] = $body;
        }

        $http['header'] = $header;

        $context = stream_context_create([
            'http' => $http,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $context);
        $received = $http_response_header ?? null;

        if ($response === false || !is_array($received) || $received === []) {
            throw new RuntimeException('The request to the identity provider did not complete.');
        }

        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $received[0], $m) !== 1) {
            throw new RuntimeException('The identity provider answered something that was not HTTP.');
        }

        $status = (int) $m[1];

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("The identity provider answered {$status}.");
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw, string $url): array
    {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // The URL, not the body. A provider's error body can contain
            // anything, and putting it in an exception message that may reach a
            // log or a screen is a way to carry somebody else's content into
            // this site's output.
            throw new RuntimeException("The identity provider's response at {$url} was not JSON.");
        }

        return $decoded;
    }
}
