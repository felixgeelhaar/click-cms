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
 *
 * ## What a signature alone does not protect against
 *
 * A correctly signed document stays correctly signed forever, so an attacker who
 * can decide *which* signed document this server sees still has two moves. Both
 * are standard in the update-security literature (they are among the attacks The
 * Update Framework enumerates), and both are closed here:
 *
 *  - **Freeze.** Keep serving yesterday's genuinely signed feed. The site never
 *    learns about the security release published since and reports itself up to
 *    date — the most comfortable possible way to stay vulnerable. Closed by
 *    requiring the feed to carry an `expires` instant and refusing it once past:
 *    a signed document is then only believable for as long as its publisher said.
 *
 *  - **Rollback.** Replay an *older* signed feed to pin this site to a release
 *    whose vulnerability is public. Closed by requiring a monotonic `sequence`
 *    and remembering the highest one seen: a feed that goes backwards is refused
 *    even though its signature is perfectly valid.
 *
 * Both fields sit inside the signed bytes, so neither can be edited in transit.
 */
final class ReleaseFeed
{
    /** Where the detached signature for a feed is expected to live. */
    private const SIGNATURE_SUFFIX = '.sig';

    /**
     * @param ?string $stateDir Where the highest feed sequence seen is
     *        remembered, for rollback protection. Null disables that check —
     *        only for callers with nowhere to write, since it is a real defence.
     */
    public function __construct(private readonly ?string $stateDir = null)
    {
    }

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

        // Freeze protection. A signed feed stays signed forever, so a publisher
        // has to say how long it should be believed; past that, a replayed
        // yesterday is refused rather than mistaken for today.
        $expires = $decoded['expires'] ?? null;
        $expiresAt = is_string($expires) ? strtotime($expires) : (is_int($expires) ? $expires : false);
        if ($expiresAt === false) {
            return $this->nothing('The update feed does not say when it expires, so it was not trusted.');
        }
        if ($expiresAt < time()) {
            return $this->nothing('The update feed expired on ' . gmdate('c', $expiresAt) . ', so it was not trusted.');
        }

        // Rollback protection. An older feed is a valid document; replaying one
        // is how a site gets pinned to a release with a published vulnerability.
        $sequence = $decoded['sequence'] ?? null;
        if (!is_int($sequence) || $sequence < 0) {
            return $this->nothing('The update feed has no sequence number, so it was not trusted.');
        }
        $highest = $this->highestSequenceSeen();
        if ($highest !== null && $sequence < $highest) {
            return $this->nothing(sprintf(
                'The update feed went backwards (sequence %d, already saw %d), so it was not trusted.',
                $sequence,
                $highest
            ));
        }

        $entries = $decoded['releases'] ?? null;
        if (!is_array($entries)) {
            return $this->nothing('The update feed lists no releases.');
        }

        // Recorded only once the feed is fully believed, so a rejected feed
        // cannot raise the bar and lock out the legitimate one behind it.
        $this->rememberSequence($sequence);

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

    /** The highest feed sequence this installation has ever believed. */
    private function highestSequenceSeen(): ?int
    {
        if ($this->stateDir === null) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($this->stateDir . '/feed-state.json'), true);
        $seen = is_array($decoded) ? ($decoded['highestSequence'] ?? null) : null;

        return is_int($seen) ? $seen : null;
    }

    private function rememberSequence(int $sequence): void
    {
        if ($this->stateDir === null) {
            return;
        }
        $highest = $this->highestSequenceSeen();
        if ($highest !== null && $sequence <= $highest) {
            return;
        }
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0o775, true);
        }

        $path = $this->stateDir . '/feed-state.json';
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['highestSequence' => $sequence], JSON_PRETTY_PRINT), LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
    }
}
