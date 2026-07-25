<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\UpdateScheduler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * When an unattended update is allowed to run, and the guarantee that only one
 * does. The failure this prevents is an installation left half of one release
 * and half of another, which no amount of careful installing can undo.
 */
final class UpdateSchedulerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-sched-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function scheduler(int $interval = 3600): UpdateScheduler
    {
        return new UpdateScheduler($this->dir, $interval);
    }

    /* --------------------------------------------------------------- timing -- */

    /**
     * A site that has never checked should find out where it stands on the first
     * run rather than wait out a full interval — that first interval is exactly
     * when a freshly installed site is most likely to be behind.
     */
    public function testASiteThatHasNeverCheckedIsDueImmediately(): void
    {
        $this->assertTrue($this->scheduler()->isDue(1_000_000));
    }

    public function testNotDueAgainUntilTheIntervalHasPassed(): void
    {
        $scheduler = $this->scheduler(3600);
        $scheduler->markChecked(1_000_000);

        $this->assertFalse($scheduler->isDue(1_000_000), 'immediately after');
        $this->assertFalse($scheduler->isDue(1_003_599), 'one second short');
        $this->assertTrue($scheduler->isDue(1_003_600), 'exactly on the interval');
        $this->assertTrue($scheduler->isDue(1_010_000), 'well past');
    }

    /** A cron line every minute must not become a feed fetched every minute. */
    public function testAFrequentCronDoesNotBecomeAFrequentPoll(): void
    {
        $scheduler = $this->scheduler(86400);
        $now = 1_000_000;
        $scheduler->markChecked($now);

        $checks = 0;
        for ($i = 1; $i <= 60; $i++) {
            if ($scheduler->isDue($now + ($i * 60))) {
                $checks++;
                $scheduler->markChecked($now + ($i * 60));
            }
        }

        $this->assertSame(0, $checks, 'an hour of minute-by-minute cron runs must trigger no check');
    }

    public function testTheLastCheckSurvivesAFreshScheduler(): void
    {
        $this->scheduler()->markChecked(1_234_567);

        $this->assertSame(1_234_567, $this->scheduler()->lastCheckedAt());
    }

    public function testAnUnreadableStateFileReadsAsNeverChecked(): void
    {
        @mkdir($this->dir, 0o775, true);
        file_put_contents($this->dir . '/schedule.json', 'not json at all');

        $this->assertNull($this->scheduler()->lastCheckedAt());
        $this->assertTrue($this->scheduler()->isDue(1_000_000), 'a corrupt marker must not wedge updates off');
    }

    /* --------------------------------------------------------------- locking -- */

    public function testWorkRunsWhileHoldingTheLock(): void
    {
        $ran = false;
        $result = $this->scheduler()->withLock(function () use (&$ran) {
            $ran = true;
            return 'done';
        });

        $this->assertTrue($ran);
        $this->assertSame('done', $result);
    }

    /**
     * The one that matters: a second run while the first is still going must
     * decline rather than start a parallel update of the same directories.
     */
    public function testASecondRunDeclinesWhileTheFirstHoldsTheLock(): void
    {
        $scheduler = $this->scheduler();
        $inner = 'not attempted';

        $scheduler->withLock(function () use ($scheduler, &$inner) {
            // A second scheduler over the same directory — as a concurrent cron
            // run would be.
            $inner = (new UpdateScheduler($this->dir))->withLock(fn (): string => 'ran anyway');
        });

        $this->assertNull($inner, 'the nested run must be refused, not queued');
    }

    public function testTheLockIsFreedAfterAnOrdinaryRun(): void
    {
        $scheduler = $this->scheduler();
        $scheduler->withLock(fn (): string => 'first');

        $this->assertSame('second', $scheduler->withLock(fn (): string => 'second'));
    }

    /**
     * A lock left held by a failed update would silently stop every future one,
     * which is a worse outcome than the failure that caused it.
     */
    public function testTheLockIsFreedEvenWhenTheWorkThrows(): void
    {
        $scheduler = $this->scheduler();

        try {
            $scheduler->withLock(function (): void {
                throw new RuntimeException('the update blew up');
            });
            $this->fail('the exception should propagate');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('afterwards', $scheduler->withLock(fn (): string => 'afterwards'));
    }
}
