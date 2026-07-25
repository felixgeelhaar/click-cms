<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\ReleaseFeed;
use Click\Cms\Application\Update\UpdateInstaller;
use Click\Cms\Application\Update\UpdateService;
use Click\Cms\Domain\Update\UpdatePolicy;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The sequencing layer: fetch, decide, maybe install, always write it down.
 *
 * Two things are worth pinning here. The first is that the policy is obeyed on
 * the unattended path — a site set to notify must not install anything while
 * nobody is looking, and that is the difference between a CMS that updates
 * itself and one that changes underneath its owner. The second is the history:
 * an update that happens invisibly and a failure that happens invisibly are both
 * the same bad morning for whoever has to work out what changed, so an attempt
 * is recorded whether it worked or not.
 *
 * Packages are served through a stand-in `https` stream wrapper rather than a
 * real network call, so the installer runs its genuine download-and-verify path
 * against bytes this test controls.
 */
final class UpdateServiceTest extends TestCase
{
    private string $base;
    private string $dir;
    /** @var resource|\OpenSSLAsymmetricKey */
    private $keypair;
    private string $publicKey;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-svc-' . bin2hex(random_bytes(6));
        $this->dir = $this->base . '-feed';
        mkdir($this->base . '/src', 0o755, true);
        mkdir($this->dir, 0o755, true);
        file_put_contents($this->base . '/src/App.php', '<?php // version 1.0.0');

        $keypair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($keypair === false) {
            $this->markTestSkipped('OpenSSL key generation is unavailable.');
        }
        $this->keypair = $keypair;
        $this->publicKey = openssl_pkey_get_details($keypair)['key'];

        FakeHttps::$files = [];
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', FakeHttps::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_restore('https');
        FakeHttps::$files = [];
        $this->rrmdir($this->base);
        $this->rrmdir($this->dir);
    }

    /* ----------------------------------------------------------- helpers -- */

    /** Publishes a package at an https URL this test answers for, and returns [url, sha256]. */
    private function publishPackage(string $version): array
    {
        $path = $this->dir . "/click-cms-$version.zip";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("click-cms-$version/src/App.php", "<?php // version $version");
        $zip->close();

        $url = "https://releases.example.com/click-cms-$version.zip";
        FakeHttps::$files[$url] = (string) file_get_contents($path);

        return [$url, hash('sha256', FakeHttps::$files[$url])];
    }

    /**
     * Writes a signed feed offering one release, and returns its URL.
     *
     * @param ?string $sha256 overrides the package's real hash, to exercise the
     *        installer's refusal path without a second fixture.
     */
    private function publishFeed(string $version, bool $security = false, ?string $sha256 = null): string
    {
        [$url, $realHash] = $this->publishPackage($version);

        $body = (string) json_encode([
            'sequence' => 1,
            'expires' => gmdate('c', time() + 86400),
            'releases' => [[
            'version' => $version,
            'packageUrl' => $url,
            'sha256' => $sha256 ?? $realHash,
            'security' => $security,
            'notes' => "Release $version",
        ]]]);

        $path = $this->dir . '/releases.json';
        file_put_contents($path, $body);
        openssl_sign($body, $signature, $this->keypair, OPENSSL_ALGO_SHA256);
        file_put_contents($path . '.sig', base64_encode($signature));

        return 'file://' . $path;
    }

    private function service(string $current = '1.0.0'): UpdateService
    {
        return new UpdateService(
            $this->base,
            new ReleaseFeed(),
            new UpdateInstaller($this->base, $this->base . '/data/updates'),
            $current,
        );
    }

    /** @return list<array<string, mixed>> */
    private function historyOnDisk(): array
    {
        $path = $this->base . '/data/updates/history.json';
        if (!is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true);
    }

    /* -------------------------------------------------------------- check -- */

    public function testCheckOffersTheNewestReleaseTheFeedVouchesFor(): void
    {
        $feed = $this->publishFeed('1.2.0');

        $decision = $this->service()->check($feed, $this->publicKey, UpdatePolicy::Notify, false);

        $this->assertTrue($decision->hasUpdate());
        $this->assertSame('1.2.0', $decision->release->version->toString());
        $this->assertSame('minor', $decision->step->value);
    }

    /**
     * A feed that gave nothing because it could not be verified must be
     * distinguishable from one that gave nothing because the site is current —
     * otherwise a misconfigured key reads as "you are up to date" forever.
     */
    public function testAFeedThatCouldNotBeVerifiedIsReportedRatherThanLookingCurrent(): void
    {
        $feed = $this->publishFeed('1.2.0');
        $service = $this->service();

        $decision = $service->check($feed, 'not a key', UpdatePolicy::Security, false);

        $this->assertFalse($decision->hasUpdate());
        $this->assertNotNull($service->lastFeedError());
    }

    /* --------------------------------------------------- the unattended path -- */

    public function testApplyIfAutomaticDoesNothingWhenThePolicyDoesNotAllowIt(): void
    {
        // Notify checks and tells somebody; it installs nothing on its own.
        $feed = $this->publishFeed('1.0.1', security: true);

        $result = $this->service()->applyIfAutomatic($feed, $this->publicKey, UpdatePolicy::Notify, false);

        $this->assertFalse($result['attempted']);
        $this->assertTrue($result['decision']['hasUpdate'], 'the update is still offered, just not taken');
        $this->assertSame('<?php // version 1.0.0', file_get_contents($this->base . '/src/App.php'));
        $this->assertSame([], $this->historyOnDisk(), 'declining to act is the normal state, not an event');
    }

    /**
     * The other half of the same rule: a security patch under the default policy
     * is exactly what unattended updating exists for, so it must actually run.
     */
    public function testApplyIfAutomaticInstallsASecurityPatchUnderTheDefaultPolicy(): void
    {
        $feed = $this->publishFeed('1.0.1', security: true);

        $result = $this->service()->applyIfAutomatic($feed, $this->publicKey, UpdatePolicy::Security, false);

        $this->assertTrue($result['attempted']);
        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('<?php // version 1.0.1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testApplyIfAutomaticLeavesANonSecurityMinorForAHumanUnderTheDefaultPolicy(): void
    {
        $feed = $this->publishFeed('1.2.0');

        $result = $this->service()->applyIfAutomatic($feed, $this->publicKey, UpdatePolicy::Security, false);

        $this->assertFalse($result['attempted']);
        $this->assertSame('<?php // version 1.0.0', file_get_contents($this->base . '/src/App.php'));
    }

    /* ------------------------------------------------------ the admin button -- */

    /** There is nothing to approve when nothing is offered. */
    public function testApplyApprovedRefusesWhenThereIsNoUpdate(): void
    {
        $feed = $this->publishFeed('0.9.0'); // older than what is running

        $result = $this->service()->applyApproved($feed, $this->publicKey, UpdatePolicy::Security, false);

        $this->assertFalse($result['attempted']);
        $this->assertNotNull($result['error']);
        $this->assertSame('<?php // version 1.0.0', file_get_contents($this->base . '/src/App.php'));
        $this->assertSame([], $this->historyOnDisk());
    }

    /**
     * The button's whole purpose: a release the policy would never take
     * unattended still installs when a person asks for it.
     */
    public function testApplyApprovedInstallsAnUpdateThePolicyWouldNotTakeOnItsOwn(): void
    {
        $feed = $this->publishFeed('2.0.0'); // a major — automatic under nothing but "all"

        $result = $this->service()->applyApproved($feed, $this->publicKey, UpdatePolicy::Security, false);

        $this->assertFalse($result['decision']['automatic'], 'this must not have been automatic');
        $this->assertTrue($result['attempted']);
        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('<?php // version 2.0.0', file_get_contents($this->base . '/src/App.php'));
    }

    /* ------------------------------------------------------------- history -- */

    public function testASuccessfulUpdateIsRecorded(): void
    {
        $feed = $this->publishFeed('1.0.1', security: true);

        $this->service()->applyIfAutomatic($feed, $this->publicKey, UpdatePolicy::Security, false);

        $history = $this->historyOnDisk();
        $this->assertCount(1, $history);
        $this->assertSame('1.0.0', $history[0]['from']);
        $this->assertSame('1.0.1', $history[0]['to']);
        $this->assertTrue($history[0]['ok']);
        $this->assertNull($history[0]['error']);
        $this->assertNotNull($history[0]['backup'], 'the way back must be findable afterwards');
        $this->assertNotEmpty($history[0]['at']);
    }

    /**
     * The more important half. A failed update is the one somebody will need to
     * explain later, so the reason has to survive the request that produced it.
     */
    public function testAFailedUpdateIsRecordedWithItsReason(): void
    {
        // The feed vouches for a hash the package does not have, so the installer
        // refuses it — the closest thing to a tampered mirror.
        $feed = $this->publishFeed('1.0.1', security: true, sha256: str_repeat('b', 64));

        $result = $this->service()->applyIfAutomatic($feed, $this->publicKey, UpdatePolicy::Security, false);

        $this->assertTrue($result['attempted'], 'it was attempted');
        $this->assertFalse($result['success']);
        $this->assertSame('<?php // version 1.0.0', file_get_contents($this->base . '/src/App.php'), 'nothing may change');

        $history = $this->historyOnDisk();
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['ok']);
        $this->assertStringContainsString('checksum', (string) $history[0]['error']);
    }

    public function testHistoryIsReadBackNewestFirst(): void
    {
        $service = $this->service();
        $service->applyIfAutomatic(
            $this->publishFeed('1.0.1', security: true, sha256: str_repeat('b', 64)),
            $this->publicKey,
            UpdatePolicy::Security,
            false,
        );
        $service->applyIfAutomatic(
            $this->publishFeed('1.0.2', security: true, sha256: str_repeat('c', 64)),
            $this->publicKey,
            UpdatePolicy::Security,
            false,
        );

        $history = $service->history();

        $this->assertCount(2, $history);
        $this->assertSame('1.0.2', $history[0]['to']);
        $this->assertSame('1.0.1', $history[1]['to']);
    }

    public function testHistoryIsEmptyRatherThanFailingBeforeAnythingHasHappened(): void
    {
        $this->assertSame([], $this->service()->history());
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

/**
 * A stand-in for `https` while these tests run, so the installer's real
 * download-and-verify path executes against bytes the test controls rather than
 * a network the test suite has no business touching.
 */
final class FakeHttps
{
    /** @var array<string, string> URL => body */
    public static array $files = [];

    /** @var resource */
    public $context;

    private string $body = '';
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!isset(self::$files[$path])) {
            return false;
        }
        $this->body = self::$files[$path];
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->body, $this->pos, $count);
        $this->pos += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen($this->body);
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_CUR => $this->pos + $offset,
            SEEK_END => strlen($this->body) + $offset,
            default => $offset,
        };
        if ($target < 0 || $target > strlen($this->body)) {
            return false;
        }
        $this->pos = $target;

        return true;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->body)];
    }

    public function stream_close(): void
    {
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return isset(self::$files[$path]) ? ['size' => strlen(self::$files[$path])] : false;
    }
}
