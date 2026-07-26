<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\UpdateNotice;
use PHPUnit\Framework\TestCase;

/**
 * What the admin is told about updates when it loads, without asking the feed.
 *
 * The admin screen calls the update endpoint every time someone signs in, and
 * the endpoint fetched the release feed on every call. That is a network round
 * trip in the sign-in path — slow when the feed is slow, broken when it is
 * unreachable, and a poll rate set by how often people log in, which is not a
 * rate anybody chose.
 *
 * So a decision is remembered, and refreshed on a schedule instead. This is the
 * remembering: a small, boring store whose only job is to answer "what did we
 * last learn, and when?" without going anywhere.
 */
final class UpdateNoticeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-notice-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function notice(): UpdateNotice
    {
        return new UpdateNotice($this->dir);
    }

    public function testASiteThatHasNeverCheckedRemembersNothing(): void
    {
        $this->assertNull($this->notice()->remembered());
    }

    public function testWhatWasLearnedIsReadBack(): void
    {
        $state = ['hasUpdate' => true, 'release' => ['version' => '1.5.0'], 'currentVersion' => '1.4.5'];

        $this->notice()->remember($state, 1_700_000_000);

        $this->assertSame($state + ['checkedAt' => 1_700_000_000], $this->notice()->remembered());
    }

    /** Rewritten, not appended to: only the latest answer is of any use. */
    public function testRememberingAgainReplacesTheAnswer(): void
    {
        $notice = $this->notice();
        $notice->remember(['hasUpdate' => true, 'release' => ['version' => '1.5.0']], 1_700_000_000);
        $notice->remember(['hasUpdate' => false], 1_700_000_100);

        $remembered = $notice->remembered();
        $this->assertFalse($remembered['hasUpdate']);
        $this->assertSame(1_700_000_100, $remembered['checkedAt']);
    }

    /**
     * A half-written or hand-edited file is treated as no answer at all. The
     * caller then refreshes, which is the same thing it would do on a first
     * run — where a fatal here would instead take the admin screen down over a
     * cache.
     */
    public function testAnUnreadableFileIsTreatedAsNothingRemembered(): void
    {
        file_put_contents($this->dir . '/last-check.json', '{not json');

        $this->assertNull($this->notice()->remembered());
    }

    public function testADirectoryThatCannotBeWrittenIsNotFatal(): void
    {
        $notice = new UpdateNotice('/no/such/directory/anywhere');

        $notice->remember(['hasUpdate' => false], 1_700_000_000);

        $this->assertNull($notice->remembered());
    }
}
