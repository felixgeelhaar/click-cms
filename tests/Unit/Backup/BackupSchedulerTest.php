<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Backup\BackupScheduler;

/**
 * When an unattended backup may run, and that only one runs at a time.
 *
 * Both hazards are ones a cron line makes real: an entry every five minutes must
 * not mean an archive every five minutes, and two runs overlapping must not both
 * write into the pool and both prune it.
 */
final class BackupSchedulerTest extends BackupTestCase
{
    private function scheduler(int $intervalSeconds = 86400): BackupScheduler
    {
        return new BackupScheduler($this->base . '/data/backups', $intervalSeconds);
    }

    /**
     * A site that has never backed up is due now. The first run after switching
     * this on should produce an archive rather than wait a day for one.
     */
    public function testASiteThatHasNeverBackedUpIsDueImmediately(): void
    {
        $this->assertTrue($this->scheduler()->isDue(1784948400));
        $this->assertNull($this->scheduler()->lastRunAt());
    }

    public function testNotDueUntilTheIntervalHasPassed(): void
    {
        $scheduler = $this->scheduler(3600);
        $scheduler->markRun(1784948400);

        $this->assertFalse($scheduler->isDue(1784948400 + 3599));
        $this->assertTrue($scheduler->isDue(1784948400 + 3600));
    }

    public function testTheLastRunSurvivesANewInstance(): void
    {
        $this->scheduler()->markRun(1784948400);

        $this->assertSame(1784948400, $this->scheduler()->lastRunAt());
    }

    /**
     * A dry run must never reach markRun(). Previewing what would happen would
     * otherwise consume the interval and leave tonight's real backup silently
     * skipping — the exact bug that was found and fixed in the updater. The CLI
     * enforces it; this pins the primitive it depends on.
     */
    public function testTheScheduleOnlyAdvancesWhenSomethingSaysItDid(): void
    {
        $scheduler = $this->scheduler(3600);

        $this->assertTrue($scheduler->isDue(1784948400));
        $this->assertTrue($scheduler->isDue(1784948400), 'Merely asking must not advance the schedule.');
        $this->assertNull($scheduler->lastRunAt());
    }

    /* --------------------------------------------------------------- locking -- */

    public function testWorkRunsUnderTheLockAndReturnsItsResult(): void
    {
        $this->assertSame('done', $this->scheduler()->withLock(static fn (): string => 'done'));
    }

    /**
     * Non-blocking on purpose: a cron run that finds a backup in progress should
     * end, not queue up behind it and then start a second one the moment the
     * first finishes.
     */
    public function testASecondRunDeclinesWhileTheFirstHoldsTheLock(): void
    {
        $outer = $this->scheduler();
        $inner = $this->scheduler();

        $result = $outer->withLock(static fn (): mixed => $inner->withLock(static fn (): string => 'should not run'));

        $this->assertNull($result, 'A second run must decline rather than proceed.');
    }

    /**
     * A lock file left held would stop every future backup until someone
     * noticed, which is worse than the failure that caused it.
     */
    public function testTheLockIsReleasedEvenWhenTheWorkThrows(): void
    {
        $scheduler = $this->scheduler();

        try {
            $scheduler->withLock(static function (): void {
                throw new \RuntimeException('the backup exploded');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('taken', $scheduler->withLock(static fn (): string => 'taken'));
    }

    public function testTheStateDirectoryIsCreatedOnDemand(): void
    {
        $this->assertDirectoryDoesNotExist($this->base . '/data/backups');

        $this->scheduler()->markRun(1784948400);

        $this->assertDirectoryExists($this->base . '/data/backups');
    }
}
