<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Preview;

use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

final class PreviewLinksTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-preview-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function links(int $ttl = 3600): PreviewLinks
    {
        return new PreviewLinks($this->dir . '/preview-secret', $ttl);
    }

    public function testAnIssuedLinkVerifies(): void
    {
        $link = $this->links()->issue(ContentKey::page('pricing'));

        $this->assertNotNull($link);
        $this->assertStringStartsWith('/preview/pricing?token=', $link['path']);
        $this->assertTrue($this->links()->accepts(ContentKey::page('pricing'), $link['token']));
    }

    /**
     * A restart, or a second process, must honour links already handed out —
     * which means the secret has to survive on disk rather than in memory.
     */
    public function testTheSecretPersistsAcrossInstances(): void
    {
        $token = $this->links()->issue(ContentKey::page('pricing'))['token'];

        $this->assertTrue($this->links()->accepts(ContentKey::page('pricing'), $token));
    }

    public function testALinkForOnePageDoesNotOpenAnother(): void
    {
        $token = $this->links()->issue(ContentKey::page('pricing'))['token'];

        $this->assertFalse($this->links()->accepts(ContentKey::page('unannounced-product'), $token));
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        // A one-second life, then waited out. Cheaper than sleeping longer, and
        // the boundary itself is covered by PreviewTokenTest with a fixed clock.
        $token = $this->links(1)->issue(ContentKey::page('pricing'))['token'];
        sleep(2);

        $this->assertFalse($this->links()->accepts(ContentKey::page('pricing'), $token));
    }

    /**
     * Verification must never create the secret. If it did, the first anonymous
     * request would mint a key, and the answer to "is this token valid" would
     * depend on who asked first.
     */
    public function testVerifyingNeverCreatesASecret(): void
    {
        $this->assertFalse($this->links()->accepts(ContentKey::page('pricing'), 'anything'));
        $this->assertFileDoesNotExist($this->dir . '/preview-secret');
    }

    /**
     * With no secret established there is nothing to verify against, so
     * everything is refused rather than everything accepted.
     */
    public function testWithNoSecretNothingIsAccepted(): void
    {
        $links = $this->links();

        $this->assertFalse($links->accepts(ContentKey::page('pricing'), (time() + 600) . '.' . str_repeat('0', 64)));
        $this->assertFalse($links->accepts(ContentKey::page('pricing'), null));
    }

    /**
     * A truncated secret file is not a usable key. Reading it as one would mean
     * every link signed with near-nothing.
     */
    public function testATruncatedSecretFileIsNotUsedForVerification(): void
    {
        file_put_contents($this->dir . '/preview-secret', 'short');

        $this->assertFalse($this->links()->accepts(ContentKey::page('pricing'), (time() + 600) . '.' . str_repeat('0', 64)));
    }

    public function testTheSecretIsNotWorldReadable(): void
    {
        $this->links()->issue(ContentKey::page('pricing'));

        $mode = fileperms($this->dir . '/preview-secret') & 0o777;

        $this->assertSame(0o600, $mode, sprintf('mode was %o', $mode));
    }

    public function testTheSecretIsLongEnoughToBeUnguessable(): void
    {
        $this->links()->issue(ContentKey::page('pricing'));

        $this->assertGreaterThanOrEqual(64, strlen(trim(file_get_contents($this->dir . '/preview-secret'))));
    }

    public function testTheReportedExpiryMatchesTheToken(): void
    {
        $link = $this->links(120)->issue(ContentKey::page('pricing'));

        $this->assertGreaterThan(time(), $link['expiresAt']);
        $this->assertLessThanOrEqual(time() + 120, $link['expiresAt']);
    }

    /**
     * Deleting the secret is the only revocation there is, since nothing about
     * an issued token is recorded.
     */
    public function testRemovingTheSecretInvalidatesEveryLink(): void
    {
        $token = $this->links()->issue(ContentKey::page('pricing'))['token'];
        unlink($this->dir . '/preview-secret');

        $this->assertFalse($this->links()->accepts(ContentKey::page('pricing'), $token));
    }
}
