<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\ReleaseFeed;
use PHPUnit\Framework\TestCase;

/**
 * The feed is what tells this installation to go and run somebody's code, so the
 * only property that really matters is negative: nothing comes out of it that a
 * signature over the exact bytes received did not vouch for. Everything else the
 * update system does — the policy, the checksum, the installer's rollback —
 * assumes the list of releases is genuine, so if this file's refusals are wrong,
 * none of those protections are pointing at the right thing.
 *
 * The second property is that a broken feed is boring: unreachable, unsigned,
 * mis-signed and malformed all produce "no releases, here is why" rather than an
 * exception that takes an admin page down.
 */
final class ReleaseFeedTest extends TestCase
{
    private string $dir;
    /** @var resource|\OpenSSLAsymmetricKey */
    private $keypair;
    private string $publicKey;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-feed-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);

        $keypair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($keypair === false) {
            $this->markTestSkipped('OpenSSL key generation is unavailable.');
        }
        $this->keypair = $keypair;
        $this->publicKey = openssl_pkey_get_details($keypair)['key'];
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /* ----------------------------------------------------------- helpers -- */

    /** @return array{0: string, 1: string} the feed URL and its raw bytes */
    private function publish(string $body, bool $sign = true, mixed $signWith = null): array
    {
        $path = $this->dir . '/releases.json';
        file_put_contents($path, $body);

        if ($sign) {
            $key = $signWith ?? $this->keypair;
            openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);
            file_put_contents($path . '.sig', base64_encode($signature));
        }

        return ['file://' . $path, $body];
    }

    /** @param list<array<string, mixed>> $releases */
    private function feedJson(array $releases): string
    {
        // A believable feed says when it expires and carries a sequence, so the
        // freeze and rollback checks have something to accept.
        return (string) json_encode([
            'sequence' => 1,
            'expires' => gmdate('c', time() + 86400),
            'releases' => $releases,
        ]);
    }

    /** @return array<string, mixed> */
    private function release(string $version, bool $security = false): array
    {
        return [
            'version' => $version,
            'packageUrl' => "https://example.com/click-cms-$version.zip",
            'sha256' => str_repeat('a', 64),
            'security' => $security,
            'notes' => "Notes for $version",
            'requiresPhp' => '8.1',
        ];
    }

    /* -------------------------------------------------------- happy path -- */

    public function testAProperlySignedFeedYieldsItsReleases(): void
    {
        [$url] = $this->publish($this->feedJson([
            $this->release('1.1.0'),
            $this->release('1.0.1', security: true),
        ]));

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['releases']);
        $this->assertSame('1.1.0', $result['releases'][0]->version->toString());
        $this->assertTrue($result['releases'][1]->security);
        $this->assertSame('Notes for 1.0.1', $result['releases'][1]->notes);
    }

    /* ------------------------------------------------------- the refusals -- */

    /**
     * The security-critical one. A feed nobody signed is a feed anyone could
     * have written, and the whole update system downstream would take it at its
     * word — so it must produce exactly nothing.
     */
    public function testAnUnsignedFeedYieldsNoReleases(): void
    {
        [$url] = $this->publish($this->feedJson([$this->release('1.1.0')]), sign: false);

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertSame([], $result['releases'], 'an unsigned feed must never offer a release');
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('not signed', (string) $result['error']);
    }

    public function testAFeedSignedByTheWrongKeyYieldsNoReleases(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        [$url] = $this->publish($this->feedJson([$this->release('1.1.0')]), signWith: $other);

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertSame([], $result['releases'], 'a feed signed by someone else must never offer a release');
        $this->assertStringContainsString('signature', (string) $result['error']);
    }

    /**
     * Signed once, then edited. This is the attack the signature exists to stop
     * — a mirror or a proxy swapping a package URL under a good signature — so
     * verification must run against the bytes as received.
     */
    public function testAFeedTamperedWithAfterSigningYieldsNoReleases(): void
    {
        [$url] = $this->publish($this->feedJson([$this->release('1.1.0')]));
        file_put_contents(
            $this->dir . '/releases.json',
            $this->feedJson([$this->release('9.9.9')])
        );

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('signature', (string) $result['error']);
    }

    /** Malformed input is an answer, never an exception — an admin page depends on it. */
    public function testAMalformedFeedYieldsNoReleasesAndAnExplanation(): void
    {
        [$url] = $this->publish('{ this is not json');

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('valid JSON', (string) $result['error']);
    }

    public function testAValidJsonFeedWithNoReleasesListIsReportedNotAssumedEmpty(): void
    {
        // Otherwise believable — signed, in date, sequenced — but with no
        // releases list at all, so the omission is what is reported.
        [$url] = $this->publish((string) json_encode([
            'sequence' => 1,
            'expires' => gmdate('c', time() + 86400),
            'something' => 'else',
        ]));

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('no releases', (string) $result['error']);
    }

    public function testAnUnreachableFeedIsReportedRatherThanThrown(): void
    {
        $result = (new ReleaseFeed())->fetch('file://' . $this->dir . '/absent.json', $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('could not be reached', (string) $result['error']);
    }

    public function testAFeedWithNoConfiguredKeyIsNotTrusted(): void
    {
        [$url] = $this->publish($this->feedJson([$this->release('1.1.0')]));

        $result = (new ReleaseFeed())->fetch($url, '');

        $this->assertSame([], $result['releases'], 'nothing can be verified without a key');
        $this->assertStringContainsString('signing key', (string) $result['error']);
    }

    public function testAnUnconfiguredFeedSaysSoRatherThanFailingObscurely(): void
    {
        $result = (new ReleaseFeed())->fetch('', $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('No update feed', (string) $result['error']);
    }

    /**
     * One bad entry costs that release, not the feed. A typo in an old release
     * must not be able to hide the security fix published below it.
     */
    public function testAMalformedEntryIsDroppedWithoutLosingTheRestOfTheFeed(): void
    {
        [$url] = $this->publish($this->feedJson([
            ['version' => 'not-a-version', 'packageUrl' => 'https://example.com/x.zip', 'sha256' => str_repeat('a', 64)],
            $this->release('1.0.1', security: true),
        ]));

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['releases']);
        $this->assertSame('1.0.1', $result['releases'][0]->version->toString());
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /* ------------------------------------- replay defences (TUF-style) -- */

    /**
     * The freeze attack. Yesterday's feed is still perfectly signed, so an
     * attacker who can keep serving it leaves the site reporting itself up to
     * date while a published security release goes untaken — the most
     * comfortable possible way to stay vulnerable.
     */
    public function testAnExpiredFeedIsRefusedEvenThoughItsSignatureIsValid(): void
    {
        $body = (string) json_encode([
            'sequence' => 1,
            'expires' => gmdate('c', time() - 60),
            'releases' => [$this->release('9.9.9')],
        ]);
        [$url] = $this->publish($body);

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('expired', (string) $result['error']);
    }

    public function testAFeedWithNoExpiryIsNotTrusted(): void
    {
        [$url] = $this->publish((string) json_encode([
            'sequence' => 1,
            'releases' => [$this->release('9.9.9')],
        ]));

        $result = (new ReleaseFeed())->fetch($url, $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('expires', (string) $result['error']);
    }

    /**
     * The rollback attack. Replaying an older, genuinely signed feed pins the
     * site to a release whose vulnerability is public. Remembering the highest
     * sequence seen is what makes a valid old document unbelievable.
     */
    public function testAFeedThatGoesBackwardsIsRefused(): void
    {
        $state = $this->dir . '/state';
        $feed = new ReleaseFeed($state);

        // Sequence 5 is seen and believed.
        [$newer] = $this->publish((string) json_encode([
            'sequence' => 5,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [$this->release('2.0.0')],
        ]));
        $this->assertCount(1, $feed->fetch($newer, $this->publicKey)['releases']);

        // Then an older one is replayed at the same URL.
        [$older] = $this->publish((string) json_encode([
            'sequence' => 4,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [$this->release('1.0.0')],
        ]));

        $result = (new ReleaseFeed($state))->fetch($older, $this->publicKey);

        $this->assertSame([], $result['releases']);
        $this->assertStringContainsString('backwards', (string) $result['error']);
    }

    public function testTheSameSequenceIsStillAcceptedSoARepublishIsNotBroken(): void
    {
        $state = $this->dir . '/state';
        $body = (string) json_encode([
            'sequence' => 5,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [$this->release('2.0.0')],
        ]);
        [$url] = $this->publish($body);

        $this->assertCount(1, (new ReleaseFeed($state))->fetch($url, $this->publicKey)['releases']);
        $this->assertCount(1, (new ReleaseFeed($state))->fetch($url, $this->publicKey)['releases']);
    }

    /**
     * A refused feed must not raise the bar: recording its sequence would let a
     * forged high number lock out every legitimate feed that follows.
     */
    public function testARefusedFeedDoesNotRaiseTheRollbackFloor(): void
    {
        $state = $this->dir . '/state';

        // Signed by the wrong key, but claiming a very high sequence.
        [$forged] = $this->publish((string) json_encode([
            'sequence' => 999,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [$this->release('9.9.9')],
        ]), signWith: $this->otherKeypair());
        $this->assertSame([], (new ReleaseFeed($state))->fetch($forged, $this->publicKey)['releases']);

        // The legitimate feed at a normal sequence must still be believed.
        [$real] = $this->publish((string) json_encode([
            'sequence' => 2,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [$this->release('1.1.0')],
        ]));

        $this->assertCount(1, (new ReleaseFeed($state))->fetch($real, $this->publicKey)['releases']);
    }

    private function otherKeypair(): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    /* ------------------------------------------------------ key rotation -- */

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} a keypair and its public half */
    private function newKeypair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        return [$key, openssl_pkey_get_details($key)['key']];
    }

    /** @param list<array<string, mixed>> $releases */
    private function feedAnnouncing(array $publicKeys, int $sequence, array $releases): string
    {
        return (string) json_encode([
            'sequence' => $sequence,
            'expires' => gmdate('c', time() + 86400),
            'keys' => $publicKeys,
            'releases' => $releases,
        ]);
    }

    /**
     * The point of rotation: a feed signed by the *current* key can hand trust
     * to a new one, and the next feed — signed only by that new key — is
     * believed. Without this, retiring a key means editing every installation by
     * hand, which is why in practice it never happens.
     */
    public function testAFeedCanHandTrustToANewKeyWhichThenWorksOnItsOwn(): void
    {
        $state = $this->dir . '/state';
        [$newKey, $newPublic] = $this->newKeypair();

        // Announced by a feed signed with the configured key.
        [$url] = $this->publish($this->feedAnnouncing([$newPublic], 10, [$this->release('1.1.0')]));
        $this->assertCount(1, (new ReleaseFeed($state))->fetch($url, $this->publicKey)['releases']);

        // The next feed is signed ONLY by the new key.
        [$next] = $this->publish($this->feedAnnouncing([$newPublic], 11, [$this->release('1.2.0')]), signWith: $newKey);
        $result = (new ReleaseFeed($state))->fetch($next, $this->publicKey);

        $this->assertNull($result['error']);
        $this->assertSame('1.2.0', $result['releases'][0]->version->toString());
    }

    /**
     * The obvious attack: an untrusted key announcing itself. If this worked,
     * the signature would be decorative — anyone could mint their own trust.
     */
    public function testAFeedSignedByAnUntrustedKeyCannotAnnounceItself(): void
    {
        $state = $this->dir . '/state';
        [$attackerKey, $attackerPublic] = $this->newKeypair();

        [$url] = $this->publish(
            $this->feedAnnouncing([$attackerPublic], 10, [$this->release('9.9.9')]),
            signWith: $attackerKey
        );

        $result = (new ReleaseFeed($state))->fetch($url, $this->publicKey);
        $this->assertSame([], $result['releases']);

        // And the attempt must leave no trust behind for a second try.
        [$again] = $this->publish($this->feedAnnouncing([$attackerPublic], 11, [$this->release('9.9.9')]), signWith: $attackerKey);
        $this->assertSame([], (new ReleaseFeed($state))->fetch($again, $this->publicKey)['releases']);
    }

    /**
     * The operator's configured key is the anchor. A feed may add keys but must
     * never be able to talk an installation out of the one its operator typed —
     * otherwise a compromised online key could lock the owner out of their own
     * update channel.
     */
    public function testAnAnnouncementCannotRevokeTheConfiguredKey(): void
    {
        $state = $this->dir . '/state';
        [, $otherPublic] = $this->newKeypair();

        // A feed that announces only some other key.
        [$url] = $this->publish($this->feedAnnouncing([$otherPublic], 10, [$this->release('1.1.0')]));
        (new ReleaseFeed($state))->fetch($url, $this->publicKey);

        // The configured key must still verify a later feed. Sequence 11, since
        // the announcement above was 10 and a feed may never go backwards.
        [$next] = $this->publish((string) json_encode([
            'sequence' => 11,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [$this->release('1.2.0')],
        ]));
        $result = (new ReleaseFeed($state))->fetch($next, $this->publicKey);

        $this->assertNull($result['error'], 'the configured key must remain trusted');
    }

    /**
     * A retired key really is retired: the announcement replaces the set rather
     * than growing it, or a compromised online key would stay trusted forever —
     * exactly what rotation exists to undo.
     */
    public function testAnAnnouncedKeyCanBeRetiredByALaterAnnouncement(): void
    {
        $state = $this->dir . '/state';
        [$firstKey, $firstPublic] = $this->newKeypair();
        [, $secondPublic] = $this->newKeypair();

        [$a] = $this->publish($this->feedAnnouncing([$firstPublic], 10, [$this->release('1.1.0')]));
        (new ReleaseFeed($state))->fetch($a, $this->publicKey);

        // Configured key drops the first announced key and names another.
        [$b] = $this->publish($this->feedAnnouncing([$secondPublic], 11, [$this->release('1.2.0')]));
        (new ReleaseFeed($state))->fetch($b, $this->publicKey);

        // The retired key must no longer verify anything.
        [$c] = $this->publish($this->feedAnnouncing([$firstPublic], 12, [$this->release('9.9.9')]), signWith: $firstKey);
        $this->assertSame([], (new ReleaseFeed($state))->fetch($c, $this->publicKey)['releases']);
    }

    /**
     * An ordinary feed carries no `keys` block. Adopting one must not be the only
     * thing that keeps the set alive, or a rotation would quietly undo itself on
     * the very next fetch.
     */
    public function testAnAnnouncedKeySurvivesAFeedThatSaysNothingAboutKeys(): void
    {
        $state = $this->dir . '/state';
        [$newKey, $newPublic] = $this->newKeypair();

        [$a] = $this->publish($this->feedAnnouncing([$newPublic], 10, [$this->release('1.1.0')]));
        (new ReleaseFeed($state))->fetch($a, $this->publicKey);

        // An ordinary feed, no keys block, signed by the configured key.
        [$b] = $this->publish((string) json_encode([
            'sequence' => 11,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [$this->release('1.2.0')],
        ]));
        (new ReleaseFeed($state))->fetch($b, $this->publicKey);

        // The rotated key must still work.
        [$c] = $this->publish($this->feedAnnouncing([$newPublic], 12, [$this->release('1.3.0')]), signWith: $newKey);
        $this->assertCount(1, (new ReleaseFeed($state))->fetch($c, $this->publicKey)['releases']);
    }

    /** Several configured keys are all trusted, so a rotation has an overlap window. */
    public function testAnyOfSeveralConfiguredKeysIsTrusted(): void
    {
        [$secondKey, $secondPublic] = $this->newKeypair();
        [$url] = $this->publish($this->feedJson([$this->release('1.1.0')]), signWith: $secondKey);

        $result = (new ReleaseFeed())->fetch($url, [$this->publicKey, $secondPublic]);

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['releases']);
    }
}
