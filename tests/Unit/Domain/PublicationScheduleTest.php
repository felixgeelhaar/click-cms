<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Publishing\PublicationSchedule;
use Click\Cms\Domain\Publishing\ScheduledAction;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublicationScheduleTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    /* ------------------------------------------------------ construction -- */

    public function testAScheduleWithNeitherEndIsEmpty(): void
    {
        $this->assertTrue(PublicationSchedule::none()->isEmpty());
        $this->assertTrue(PublicationSchedule::of(null, null)->isEmpty());
    }

    public function testEitherEndAloneIsASchedule(): void
    {
        $this->assertFalse(PublicationSchedule::of($this->at('2026-08-01 09:00'), null)->isEmpty());
        $this->assertFalse(PublicationSchedule::of(null, $this->at('2026-08-01 09:00'))->isEmpty());
    }

    /**
     * A window that closes before it opens describes nothing that can happen.
     * Refused at construction rather than ignored at sweep time, so the editor
     * hears about it while they are still looking at the form.
     */
    public function testATakedownBeforeItsPublishIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PublicationSchedule::of($this->at('2026-08-02 09:00'), $this->at('2026-08-01 09:00'));
    }

    public function testATakedownAtTheSameInstantAsItsPublishIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PublicationSchedule::of($this->at('2026-08-01 09:00'), $this->at('2026-08-01 09:00'));
    }

    /* ------------------------------------------------------------- due -- */

    public function testNothingIsDueBeforeEitherTime(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), $this->at('2026-08-05 09:00'));

        $this->assertNull($schedule->actionDueAt($this->at('2026-07-31 23:59')));
    }

    public function testAPublishIsDueOnceItsTimeHasPassed(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), null);

        $this->assertSame(ScheduledAction::Publish, $schedule->actionDueAt($this->at('2026-08-01 09:00')));
        $this->assertSame(ScheduledAction::Publish, $schedule->actionDueAt($this->at('2026-08-01 09:01')));
    }

    public function testATakedownIsDueOnceItsTimeHasPassed(): void
    {
        $schedule = PublicationSchedule::of(null, $this->at('2026-08-01 09:00'));

        $this->assertSame(ScheduledAction::Unpublish, $schedule->actionDueAt($this->at('2026-08-01 09:00')));
    }

    /**
     * The reason this class computes a state rather than replaying a queue.
     *
     * Cron does not always run. A window that opened at 09:00 and closed at
     * 11:00, swept for the first time at 11:30, must leave the page down — not
     * publish it because that instruction came first and then wait a further
     * sweep to take it down again. Anything else puts a page live that the
     * editor said should be gone, for however long the next sweep is away.
     */
    public function testAWindowSweptAfterItClosedLeavesThePageDown(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), $this->at('2026-08-01 11:00'));

        $this->assertSame(ScheduledAction::Unpublish, $schedule->actionDueAt($this->at('2026-08-01 11:30')));
    }

    public function testAWindowSweptWhileOpenPublishes(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), $this->at('2026-08-01 11:00'));

        $this->assertSame(ScheduledAction::Publish, $schedule->actionDueAt($this->at('2026-08-01 10:00')));
    }

    public function testAnEmptyScheduleIsNeverDue(): void
    {
        $this->assertNull(PublicationSchedule::none()->actionDueAt($this->at('2030-01-01 00:00')));
    }

    /* -------------------------------------------------------- consuming -- */

    /**
     * What has already happened must not happen again. A publish that fired at
     * 09:00 leaves only the takedown behind, so the next sweep considers that
     * and nothing else.
     */
    public function testFiringConsumesOnlyTheTimesThatHavePassed(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), $this->at('2026-08-01 11:00'));

        $remaining = $schedule->withoutActionsDueAt($this->at('2026-08-01 10:00'));

        $this->assertNull($remaining->publishAt);
        $this->assertEquals($this->at('2026-08-01 11:00'), $remaining->unpublishAt);
        $this->assertFalse($remaining->isEmpty());
    }

    public function testAFullyElapsedScheduleIsConsumedEntirely(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), $this->at('2026-08-01 11:00'));

        $this->assertTrue($schedule->withoutActionsDueAt($this->at('2026-08-01 11:30'))->isEmpty());
    }

    public function testAScheduleNotYetDueIsUnchangedByFiring(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), null);

        $remaining = $schedule->withoutActionsDueAt($this->at('2026-07-01 00:00'));

        $this->assertEquals($schedule->publishAt, $remaining->publishAt);
    }

    /* ----------------------------------------------------------- shape -- */

    public function testItRoundTripsThroughItsArrayForm(): void
    {
        $schedule = PublicationSchedule::of($this->at('2026-08-01 09:00'), $this->at('2026-08-05 17:30'));

        $restored = PublicationSchedule::fromArray($schedule->toArray());

        $this->assertEquals($schedule->publishAt, $restored->publishAt);
        $this->assertEquals($schedule->unpublishAt, $restored->unpublishAt);
    }

    public function testAnEmptyScheduleRoundTrips(): void
    {
        $this->assertTrue(PublicationSchedule::fromArray(PublicationSchedule::none()->toArray())->isEmpty());
    }

    /**
     * Times are stored and compared in UTC whatever the editor's browser sent,
     * so a site whose server moves timezone does not shift every pending
     * publication with it.
     */
    public function testAnOffsetTimeIsNormalisedToUtc(): void
    {
        $schedule = PublicationSchedule::fromArray([
            'publishAt' => '2026-08-01T11:00:00+02:00',
            'unpublishAt' => null,
        ]);

        $this->assertSame('2026-08-01T09:00:00+00:00', $schedule->publishAt?->format(DATE_ATOM));
    }

    /**
     * A stored value that is not a time at all is dropped rather than thrown
     * over: the sweeper runs unattended over every schedule on the site, and
     * one corrupt file must not stop the rest from being swept.
     */
    public function testAnUnparseableStoredTimeIsDropped(): void
    {
        $schedule = PublicationSchedule::fromArray(['publishAt' => 'whenever', 'unpublishAt' => null]);

        $this->assertTrue($schedule->isEmpty());
    }

    /**
     * Refused at the boundary, dropped when already stored: the same bad pair
     * cannot be created through the API, but if one reaches disk the sweeper
     * still has to cope. Keeping the publish and dropping the impossible
     * takedown is the reading that loses least.
     */
    public function testAStoredImpossibleWindowKeepsThePublishAndDropsTheTakedown(): void
    {
        $schedule = PublicationSchedule::fromArray([
            'publishAt' => '2026-08-02T09:00:00+00:00',
            'unpublishAt' => '2026-08-01T09:00:00+00:00',
        ]);

        $this->assertEquals($this->at('2026-08-02 09:00'), $schedule->publishAt);
        $this->assertNull($schedule->unpublishAt);
    }
}
