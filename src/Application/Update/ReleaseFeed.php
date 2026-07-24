<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

use Click\Cms\Domain\Update\Release;

/**
 * Fetches the list of published releases and refuses to believe an unsigned one.
 *
 * The feed is what tells this installation to go and run somebody's code, so it
 * is the point where a compromise would be worth the most: an attacker who can
 * answer for the feed host, or sit between it and this server, can otherwise
 * name any package URL they like. Everything downstream — the policy, the
 * checksum, the installer's rollback — protects the *application* of a release;
 * only the signature protects the *choice* of one.
 *
 * So the rule here is absolute and has no configuration knob: releases are
 * returned only when a detached signature over the exact bytes received verifies
 * against the configured public key. A feed that is unreachable, unsigned,
 * wrongly signed or malformed all produce the same outcome — zero releases and a
 * sentence explaining why. Nothing throws, because a broken feed must degrade to
 * "no update available" rather than take an admin page down with it.
 *
 * The signature lives beside the feed at `<feedUrl>.sig`, base64-encoded, so the
 * feed itself stays a plain document that can be signed by whatever releases it
 * — the signature cannot be carried inside a document it also covers.
 */
final class ReleaseFeed
{
    /** Where the detached signature for a feed is expected to live. */
    private const SIGNATURE_SUFFIX = '.sig';

    /**
     * The releases a verified feed offers.
     *
     * @return array{releases: list<Release>, error: ?string}
     */
    public function fetch(string $feedUrl, string $publicKey): array
    {
        if (trim($feedUrl) === '') {
            return $this->nothing('No update feed is configured.');
        }
        if (trim($publicKey) === '') {
            // Without a key there is no way to tell a real feed from a forged
            // one, and a feed nobody can verify is worse than no feed at all.
            return $this->nothing('No update signing key is configured, so the feed was not trusted.');
        }

        $raw = $this->get($feedUrl);
        if ($raw === null) {
            return $this->nothing('The update feed could not be reached.');
        }

        $signature = $this->get($feedUrl . self::SIGNATURE_SUFFIX);
        if ($signature === null) {
            return $this->nothing('The update feed is not signed, so nothing from it was used.');
        }

        // Verified against the bytes as received, before they are parsed — a
        // signature checked after decoding would only vouch for our reading of
        // the document rather than the document.
        if (!$this->verifySignature($raw, trim($signature), $publicKey)) {
            return $this->nothing('The update feed signature does not verify, so nothing from it was used.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->nothing('The update feed is not valid JSON.');
        }

        $entries = $decoded['releases'] ?? null;
        if (!is_array($entries)) {
            return $this->nothing('The update feed lists no releases.');
        }

        $releases = [];
        foreach ($entries as $entry) {
            // A malformed entry costs that one release rather than the feed: a
            // typo in an old release must not hide the security fix below it.
            if (!is_array($entry)) {
                continue;
            }
            $release = Release::fromArray($entry);
            if ($release !== null) {
                $releases[] = $release;
            }
        }

        return ['releases' => $releases, 'error' => null];
    }

    /**
     * @return array{releases: list<Release>, error: string}
     */
    private function nothing(string $error): array
    {
        return ['releases' => [], 'error' => $error];
    }

    private function get(string $url): ?string
    {
        $context = stream_context_create(['http' => [
            'timeout' => 10,
            'follow_location' => 1,
            'max_redirects' => 3,
            'user_agent' => 'ClickCMS-Updater',
        ]]);

        $data = @file_get_contents($url, false, $context);

        return $data === false ? null : $data;
    }

    private function verifySignature(string $payload, string $signature, string $publicKey): bool
    {
        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return false;
        }

        return openssl_verify($payload, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
