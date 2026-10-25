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
 *
 * ## Key rotation
 *
 * A single permanent key is its own problem: retiring it means editing every
 * installation by hand, so in practice it never gets retired. A feed may
 * therefore announce the set of keys that should be trusted from now on, in a
 * `keys` array, and — because that announcement is inside the signed bytes — it
 * is only believed when the feed carrying it was itself signed by a key already
 * trusted. Announced keys are remembered, so the new signing key keeps working
 * on the next fetch and the old one can then be dropped by a later feed.
 *
 * The operator's configured key is deliberately NOT revocable this way. It is
 * always trusted, whatever a feed says, so the anchor of trust stays the thing
 * the operator typed rather than something the network can talk them out of.
 * The intended arrangement is the one TUF describes: a rarely-used offline key
 * in the configuration, and an online signing key that it rotates.
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
    /**
     * @param string|list<string> $publicKey The configured key, or several. These
     *        are the permanent anchor; any key a previous feed announced is
     *        trusted in addition to them.
     */
    public function fetch(string $feedUrl, string|array $publicKey): array
    {
        $configured = array_values(array_filter(
            array_map(trim(...), is_array($publicKey) ? $publicKey : [$publicKey]),
            static fn (string $k): bool => $k !== ''
        ));

        if (trim($feedUrl) === '') {
            return $this->nothing('No update feed is configured.');
        }
        if ($configured === []) {
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
        // The configured keys plus anything a previously trusted feed announced.
        $trusted = array_merge($configured, $this->announcedKeys());
        if (!$this->verifiesAgainstAny($raw, trim($signature), $trusted)) {
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

        // A sequence identifies one exact signed metadata document. Reusing a
        // sequence for changed contents would let a mirror replay the older of
        // two valid documents at that same sequence. Keep the digest alongside
        // the high-water mark so equal-sequence retries remain safe only when
        // the bytes are identical.
        $feedDigest = hash('sha256', $raw);
        $highestDigest = $this->highestSequenceDigest();
        if ($highest !== null && $sequence === $highest && $highestDigest !== null
            && !hash_equals($highestDigest, $feedDigest)) {
            return $this->nothing(sprintf(
                'The update feed changed at the same sequence (%d), so it was not trusted.',
                $sequence
            ));
        }

        $entries = $decoded['releases'] ?? null;
        if (!is_array($entries)) {
            return $this->nothing('The update feed lists no releases.');
        }

        // Recorded only once the feed is fully believed, so a rejected feed
        // cannot raise the bar and lock out the legitimate one behind it.
        $announced = isset($decoded['keys']) && is_array($decoded['keys']) ? $decoded['keys'] : null;
        if (!$this->rememberSequence($sequence, $feedDigest, $raw, trim($signature), $announced, $configured)) {
            return $this->nothing(sprintf(
                'The update feed changed while it was being checked at sequence %d, so it was not trusted.',
                $sequence
            ));
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

    /** Digest of the exact signed feed at the remembered sequence, if known. */
    private function highestSequenceDigest(): ?string
    {
        if ($this->stateDir === null) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($this->stateDir . '/feed-state.json'), true);
        $digest = is_array($decoded) ? ($decoded['highestDigest'] ?? null) : null;

        return is_string($digest) && preg_match('/^[a-f0-9]{64}$/', $digest) === 1 ? $digest : null;
    }

    private function rememberSequence(
        int $sequence,
        string $digest,
        string $raw,
        string $signature,
        ?array $announced,
        array $configured,
    ): bool
    {
        if ($this->stateDir === null) {
            return true;
        }
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0o775, true);
        }

        $path = $this->stateDir . '/feed-state.json';
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            // If persistent rollback state cannot be protected, do not accept
            // an update that would silently disable replay protection.
            return false;
        }

        // Merged, not replaced. Writing only the sequence would drop the
        // announced keys on the next ordinary feed, so a site that had just
        // rotated would lose the new key and stop trusting the feed signed with
        // it — the rotation quietly undoing itself one fetch later.
        $state = json_decode((string) @file_get_contents($path), true);
        $state = is_array($state) ? $state : [];
        // Signature verification happened before this lock to avoid holding it
        // over network I/O. Recheck against the now-current trust set while
        // serialized with state changes: an in-flight fetch signed by a key
        // retired by a newer feed must not commit afterward.
        $currentlyAnnounced = $state['announcedKeys'] ?? null;
        $currentKeys = array_merge(
            $configured,
            is_array($currentlyAnnounced)
                ? array_values(array_filter($currentlyAnnounced, static fn ($key): bool => is_string($key) && $key !== ''))
                : [],
        );
        if (!$this->verifiesAgainstAny($raw, $signature, $currentKeys)) {
            flock($lock, LOCK_UN);
            fclose($lock);

            return false;
        }
        $highest = is_int($state['highestSequence'] ?? null) ? $state['highestSequence'] : null;
        $knownDigest = is_string($state['highestDigest'] ?? null) ? $state['highestDigest'] : null;
        if (($highest !== null && $sequence < $highest)
            || ($highest !== null && $sequence === $highest && $knownDigest !== null
                && !hash_equals($knownDigest, $digest))) {
            flock($lock, LOCK_UN);
            fclose($lock);

            return false;
        }
        if ($highest !== null && $sequence === $highest && $knownDigest !== null
            && hash_equals($knownDigest, $digest)) {
            flock($lock, LOCK_UN);
            fclose($lock);

            return true;
        }
        $state['highestSequence'] = $sequence;
        $state['highestDigest'] = $digest;

        // Keep sequence/digest and key rotation in one locked state update. If
        // these were separate writes, an older concurrent fetch could finish
        // last and reinstate a key retired by the newer feed.
        if ($announced !== null) {
            $state['announcedKeys'] = $this->validatedAnnouncedKeys($announced, $configured);
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) !== false
            && @rename($tmp, $path);
        if (!$written) {
            @unlink($tmp);
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return $written;
    }

    /* ------------------------------------------------------ key rotation -- */

    /** True when the signature verifies under any one of the trusted keys. */
    private function verifiesAgainstAny(string $payload, string $signature, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->verifySignature($payload, $signature, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys a previously trusted feed asked this installation to trust.
     *
     * @return list<string>
     */
    private function announcedKeys(): array
    {
        if ($this->stateDir === null) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($this->stateDir . '/feed-state.json'), true);
        $keys = is_array($decoded) ? ($decoded['announcedKeys'] ?? null) : null;
        if (!is_array($keys)) {
            return [];
        }

        return array_values(array_filter($keys, static fn ($k): bool => is_string($k) && $k !== ''));
    }

    /**
     * Validate a signed feed's replacement set of announced keys.
     *
     * Replace rather than merge, so retiring a key is possible at all: a set
     * that only ever grew would mean a compromised online key stayed trusted
     * forever. Configured keys are excluded because they are trusted
     * unconditionally and should remain visibly operator-controlled.
     *
     * @param array<mixed> $announced
     * @param list<string> $configured
     * @return list<string>
     */
    private function validatedAnnouncedKeys(array $announced, array $configured): array
    {
        $keys = [];
        foreach ($announced as $key) {
            // Only something OpenSSL will actually accept as a public key; a
            // malformed entry would silently shrink the trusted set.
            if (!is_string($key) || $key === '' || openssl_pkey_get_public($key) === false) {
                continue;
            }
            if (!in_array($key, $configured, true)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
