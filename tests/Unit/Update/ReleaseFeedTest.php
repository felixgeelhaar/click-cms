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
        return (string) json_encode(['releases' => $releases]);
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
        [$url] = $this->publish((string) json_encode(['something' => 'else']));

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
}
