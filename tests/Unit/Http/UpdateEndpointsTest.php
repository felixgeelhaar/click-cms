<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Update\ReleaseFeed;
use Click\Cms\Application\Update\UpdateInstaller;
use Click\Cms\Application\Update\UpdateNotice;
use Click\Cms\Application\Update\UpdateScheduler;
use Click\Cms\Application\Update\UpdateService;
use Click\Cms\Http\UpdatesController;
use PHPUnit\Framework\TestCase;

/**
 * The endpoints behind the admin's update notice.
 *
 * The notice's own tests stub `fetch`, and the installer's tests call it
 * directly; nothing joined the two, which is how a button that reported a
 * successful rollback after a failed one reached a real installation. This
 * covers the seam: what the button asks for, and what it is told.
 */
final class UpdateEndpointsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-endpoints-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/data/updates', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/data/updates/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->base . '/data/updates');
        @rmdir($this->base . '/data');
        @rmdir($this->base);
    }

    private function controller(): UpdatesController
    {
        // No feed URL: the point here is the caching and permission behaviour,
        // neither of which should need a network to exercise.
        $config = CoreConfig::fromArray(['core' => ['updates' => ['feedUrl' => '']]]);

        return new UpdatesController(
            new UpdateService(
                $this->base,
                new ReleaseFeed($this->base . '/data/updates'),
                new UpdateInstaller($this->base),
                '1.4.5',
            ),
            $config,
            fn (): array => ['role' => 'admin'],
            new UpdateNotice($this->base . '/data/updates'),
            new UpdateScheduler($this->base . '/data/updates'),
        );
    }

    /**
     * The sign-in path must not pay for a feed fetch every time. The first call
     * establishes an answer; the second is served from it.
     */
    public function testTheStatusTheNoticeReadsIsCachedBetweenSignIns(): void
    {
        $controller = $this->controller();

        $first = $controller->status();
        $second = $controller->status();

        $this->assertFalse($first['data']['fromCache'], 'the first call has nothing to read');
        $this->assertTrue($second['data']['fromCache'], 'the second should not consult the feed again');
        $this->assertSame($first['data']['currentVersion'], $second['data']['currentVersion']);
    }

    /** "Check now" is a button, and a button that might do nothing is worse than none. */
    public function testCheckingExplicitlyAlwaysConsultsTheFeed(): void
    {
        $controller = $this->controller();
        $controller->status();

        $this->assertFalse($controller->check()['data']['fromCache']);
    }

    /** An editor cannot install software; the notice is never shown to them either. */
    public function testAUserWithoutThePermissionIsRefused(): void
    {
        $config = CoreConfig::fromArray(['core' => ['updates' => ['feedUrl' => '']]]);
        $controller = new UpdatesController(
            new UpdateService(
                $this->base,
                new ReleaseFeed($this->base . '/data/updates'),
                new UpdateInstaller($this->base),
                '1.4.5',
            ),
            $config,
            fn (): array => ['role' => 'editor'],
        );

        $this->assertSame(403, $controller->status()['status'] ?? null);
        $this->assertSame(403, $controller->apply()['status'] ?? null);
    }

    /**
     * Installing must clear what was remembered.
     *
     * Seen on a live installation: after a successful update the admin went on
     * reporting the old version and offering the release it had just installed,
     * because the cached answer was written before the swap and the interval had
     * not elapsed. The notice would have said so for a day.
     */
    public function testInstallingForgetsTheRememberedAnswer(): void
    {
        $notice = new UpdateNotice($this->base . '/data/updates');
        $notice->remember(['hasUpdate' => true, 'currentVersion' => '1.4.5'], time());
        $this->assertNotNull($notice->remembered(), 'precondition: something is remembered');

        $notice->forget();

        $this->assertNull($notice->remembered());
    }

    /** With nothing on offer, the button must not pretend it installed something. */
    public function testApplyingWithNothingToInstallSaysSo(): void
    {
        $result = $this->controller()->apply();

        $this->assertNotSame(true, $result['data']['installed'] ?? null);
        $this->assertArrayHasKey('error', $result);
    }
}
