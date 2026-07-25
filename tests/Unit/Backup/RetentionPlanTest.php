<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Domain\Backup\RetentionPlan;
use PHPUnit\Framework\TestCase;

/**
 * Retention, which is the one calculation in the backup feature that can destroy
 * data without anybody noticing.
 *
 * Deleting a pool entry that a surviving archive still needs does not fail: the
 * archive stays on disk, keeps listing, keeps reporting the right number of
 * media files, and produces a site with every picture missing on the day it is
 * restored. Nothing in between says a word. So the cases below are not a
 * sampling of the behaviour — they are the specific ways that can happen.
 */
final class RetentionPlanTest extends TestCase
{
    /** @param array<string, list<string>|null> $references */
    private function plan(array $archives, int $keep, array $references, array $pool): RetentionPlan
    {
        return RetentionPlan::compute($archives, $keep, $references, $pool);
    }

    /* --------------------------------------------------- which archives go -- */

    public function testTheNewestArchivesAreKeptAndTheRestAreDropped(): void
    {
        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip', '2026-01-03T000000Z.zip'],
            2,
            [
                '2026-01-01T000000Z.zip' => [],
                '2026-01-02T000000Z.zip' => [],
                '2026-01-03T000000Z.zip' => [],
            ],
            []
        );

        $this->assertSame(['2026-01-01T000000Z.zip'], $plan->archivesToDelete());
    }

    /** Names are timestamps, so the plan must not depend on the order it is given. */
    public function testOrderOfTheInputDoesNotChangeWhichArchivesSurvive(): void
    {
        $plan = $this->plan(
            ['2026-01-03T000000Z.zip', '2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            1,
            [],
            []
        );

        $this->assertSame(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            $plan->archivesToDelete()
        );
    }

    public function testNothingIsDroppedWhenThereAreFewerArchivesThanTheLimit(): void
    {
        $plan = $this->plan(['2026-01-01T000000Z.zip'], 7, ['2026-01-01T000000Z.zip' => []], []);

        $this->assertSame([], $plan->archivesToDelete());
    }

    /**
     * A retention setting of zero is somebody typing zero. Honouring it would
     * mean the run deletes the backup it has just taken, so the floor is one.
     */
    public function testKeepingZeroStillKeepsTheNewest(): void
    {
        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            0,
            ['2026-01-01T000000Z.zip' => [], '2026-01-02T000000Z.zip' => []],
            []
        );

        $this->assertSame(['2026-01-01T000000Z.zip'], $plan->archivesToDelete());
    }

    /* ------------------------------------------------ which pool entries go -- */

    /**
     * The case this whole class exists for.
     *
     * Two archives, one shared picture. The older archive is pruned. If the pool
     * entry went with it, the surviving archive would restore a site with the
     * picture gone — and the backup that could have fixed that is the one just
     * deleted.
     */
    public function testAPoolEntryTwoArchivesShareSurvivesWhenOnlyOneIsPruned(): void
    {
        $shared = 'pool/' . str_repeat('a', 64) . '.jpg';

        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            1,
            [
                '2026-01-01T000000Z.zip' => [$shared],
                '2026-01-02T000000Z.zip' => [$shared],
            ],
            [$shared]
        );

        $this->assertSame(['2026-01-01T000000Z.zip'], $plan->archivesToDelete());
        $this->assertSame(
            [],
            $plan->poolEntriesToDelete(),
            'A pool entry a surviving archive still needs must never be deleted.'
        );
    }

    public function testAPoolEntryOnlyThePrunedArchiveNeededIsFreed(): void
    {
        $shared = 'pool/' . str_repeat('a', 64) . '.jpg';
        $gone = 'pool/' . str_repeat('b', 64) . '.png';

        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            1,
            [
                '2026-01-01T000000Z.zip' => [$shared, $gone],
                '2026-01-02T000000Z.zip' => [$shared],
            ],
            [$shared, $gone]
        );

        $this->assertSame([$gone], $plan->poolEntriesToDelete());
    }

    /**
     * A picture that was replaced: the old bytes are referenced only by archives
     * that have aged out, so the pool should stop carrying them.
     */
    public function testPoolEntriesNoSurvivingArchiveNamesAreAllFreed(): void
    {
        $old = 'pool/' . str_repeat('c', 64) . '.jpg';
        $new = 'pool/' . str_repeat('d', 64) . '.jpg';

        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            1,
            [
                '2026-01-01T000000Z.zip' => [$old],
                '2026-01-02T000000Z.zip' => [$new],
            ],
            [$old, $new]
        );

        $this->assertSame([$old], $plan->poolEntriesToDelete());
    }

    /** An orphan nothing at all refers to is exactly what retention is for. */
    public function testAnEntryNoArchiveReferencesIsFreed(): void
    {
        $orphan = 'pool/' . str_repeat('e', 64) . '.png';

        $plan = $this->plan(
            ['2026-01-02T000000Z.zip'],
            7,
            ['2026-01-02T000000Z.zip' => []],
            [$orphan]
        );

        $this->assertSame([], $plan->archivesToDelete());
        $this->assertSame([$orphan], $plan->poolEntriesToDelete());
    }

    /* ----------------------------------------- when the answer is not known -- */

    /**
     * A surviving archive whose manifest will not open has unknown requirements.
     * Treating that as "needs nothing" is the assumption that empties the pool
     * underneath it, so nothing is freed at all and the run says so.
     */
    public function testNothingIsFreedWhenASurvivingArchiveWillNotSayWhatItNeeds(): void
    {
        $entry = 'pool/' . str_repeat('f', 64) . '.jpg';

        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            2,
            [
                '2026-01-01T000000Z.zip' => [],
                '2026-01-02T000000Z.zip' => null, // unreadable manifest
            ],
            [$entry]
        );

        $this->assertTrue($plan->poolPruningRefused());
        $this->assertSame([], $plan->poolEntriesToDelete());
    }

    /**
     * An archive missing from the map entirely — never described at all — is the
     * same unknown, not an empty requirement list.
     */
    public function testAnArchiveWithNoEntryInTheMapIsTreatedAsUnknown(): void
    {
        $entry = 'pool/' . str_repeat('1', 64) . '.jpg';

        $plan = $this->plan(['2026-01-02T000000Z.zip'], 2, [], [$entry]);

        $this->assertTrue($plan->poolPruningRefused());
        $this->assertSame([], $plan->poolEntriesToDelete());
    }

    /**
     * An unreadable archive that is *aging out* tells us nothing we need. Its
     * requirements are irrelevant once it is gone, so retention proceeds and the
     * pool is still pruned against the survivors.
     */
    public function testAnUnreadableArchiveBeingPrunedDoesNotBlockPoolPruning(): void
    {
        $orphan = 'pool/' . str_repeat('2', 64) . '.jpg';

        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip'],
            1,
            [
                '2026-01-01T000000Z.zip' => null, // being deleted anyway
                '2026-01-02T000000Z.zip' => [],
            ],
            [$orphan]
        );

        $this->assertFalse($plan->poolPruningRefused());
        $this->assertSame(['2026-01-01T000000Z.zip'], $plan->archivesToDelete());
        $this->assertSame([$orphan], $plan->poolEntriesToDelete());
    }

    /** Retention by age still runs when the pool cannot be touched. */
    public function testArchivesStillAgeOutWhenPoolPruningIsRefused(): void
    {
        $plan = $this->plan(
            ['2026-01-01T000000Z.zip', '2026-01-02T000000Z.zip', '2026-01-03T000000Z.zip'],
            2,
            ['2026-01-03T000000Z.zip' => null],
            ['pool/' . str_repeat('3', 64) . '.jpg']
        );

        $this->assertSame(['2026-01-01T000000Z.zip'], $plan->archivesToDelete());
        $this->assertTrue($plan->poolPruningRefused());
    }

    /* ----------------------------------------------------------- housekeeping -- */

    public function testAnEmptyStoreProducesAnEmptyPlan(): void
    {
        $plan = $this->plan([], 7, [], []);

        $this->assertTrue($plan->isEmpty());
        $this->assertFalse($plan->poolPruningRefused());
    }

    public function testFreedEntriesAreReportedOnceAndInAStableOrder(): void
    {
        $a = 'pool/' . str_repeat('a', 64) . '.jpg';
        $b = 'pool/' . str_repeat('b', 64) . '.jpg';

        $plan = $this->plan(
            ['2026-01-02T000000Z.zip'],
            1,
            ['2026-01-02T000000Z.zip' => []],
            [$b, $a]
        );

        $this->assertSame([$a, $b], $plan->poolEntriesToDelete());
    }

    /**
     * A manifest naming the same pool entry twice — two documents referencing
     * one picture — must not make it look like two different live entries, and
     * must certainly not make it deletable.
     */
    public function testARepeatedReferenceIsStillOneLiveEntry(): void
    {
        $shared = 'pool/' . str_repeat('9', 64) . '.jpg';

        $plan = $this->plan(
            ['2026-01-02T000000Z.zip'],
            1,
            ['2026-01-02T000000Z.zip' => [$shared, $shared]],
            [$shared]
        );

        $this->assertSame([], $plan->poolEntriesToDelete());
    }
}
