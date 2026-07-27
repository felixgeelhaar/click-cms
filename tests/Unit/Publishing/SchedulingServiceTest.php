<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Publishing;

use Click\Cms\Application\Publishing\SchedulingService;
use Click\Cms\Application\Publishing\SweepOutcome;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\PublicationSchedule;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\Publishing\ScheduledAction;
use Click\Cms\Domain\Publishing\ScheduledDocument;
use Click\Cms\Domain\Publishing\ScheduleStore;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SchedulingServiceTest extends TestCase
{
    private InMemoryScheduleStore $schedules;
    private SweepableStorage $content;

    protected function setUp(): void
    {
        $this->schedules = new InMemoryScheduleStore();
        $this->content = new SweepableStorage();
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private function service(?callable $refusal = null, ?callable $runAs = null): SchedulingService
    {
        return new SchedulingService($this->schedules, $this->content, $refusal, $runAs);
    }

    /* ------------------------------------------------------- publishing -- */

    public function testADuePublicationIsPromoted(): void
    {
        $key = ContentKey::page('home');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Home']);
        $this->schedules->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $report = $this->service()->sweep($this->at('2026-08-01 09:05'));

        $this->assertSame([$key->toString()], $this->content->published);
        $this->assertSame(1, $report->publishedCount());
    }

    public function testAPublicationNotYetDueIsLeftAlone(): void
    {
        $key = ContentKey::page('home');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Home']);
        $this->schedules->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $report = $this->service()->sweep($this->at('2026-07-31 09:00'));

        $this->assertSame([], $this->content->published);
        $this->assertSame(0, $report->publishedCount());
    }

    /**
     * A fired schedule must not fire again. Without this the page is republished
     * on every sweep for ever, which floods the audit trail and — worse — undoes
     * an editor who takes the page down again by hand.
     */
    public function testAFiredPublicationIsConsumed(): void
    {
        $key = ContentKey::page('home');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Home']);
        $this->schedules->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $service = $this->service();
        $service->sweep($this->at('2026-08-01 09:05'));
        $service->sweep($this->at('2026-08-01 10:00'));

        $this->assertCount(1, $this->content->published);
        $this->assertTrue($this->schedules->find($key)->isEmpty());
    }

    /* ----------------------------------------------------- unpublishing -- */

    public function testADueTakedownRemovesTheLiveDocument(): void
    {
        $key = ContentKey::page('notice');
        $this->schedules->save($key, PublicationSchedule::of(null, $this->at('2026-08-01 09:00')));

        $this->service()->sweep($this->at('2026-08-01 09:05'));

        $this->assertSame([$key->toString()], $this->content->unpublished);
        $this->assertTrue($this->schedules->find($key)->isEmpty());
    }

    /**
     * The late-sweep case, end to end. A window that opened and closed while
     * nothing was running must leave the page down and must never briefly
     * publish it on the way.
     */
    public function testAWindowSweptAfterItClosedOnlyTakesThePageDown(): void
    {
        $key = ContentKey::page('offer');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Offer']);
        $this->schedules->save($key, PublicationSchedule::of(
            $this->at('2026-08-01 09:00'),
            $this->at('2026-08-01 11:00')
        ));

        $this->service()->sweep($this->at('2026-08-01 11:30'));

        $this->assertSame([], $this->content->published);
        $this->assertSame([$key->toString()], $this->content->unpublished);
        $this->assertTrue($this->schedules->find($key)->isEmpty());
    }

    public function testAnOpenWindowPublishesAndKeepsItsPendingTakedown(): void
    {
        $key = ContentKey::page('offer');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Offer']);
        $this->schedules->save($key, PublicationSchedule::of(
            $this->at('2026-08-01 09:00'),
            $this->at('2026-08-01 11:00')
        ));

        $this->service()->sweep($this->at('2026-08-01 10:00'));

        $this->assertSame([$key->toString()], $this->content->published);
        $this->assertEquals($this->at('2026-08-01 11:00'), $this->schedules->find($key)->unpublishAt);
    }

    /* ------------------------------------------------------------ gate -- */

    /**
     * A plugin veto has to survive the fact that nobody is watching. A refused
     * scheduled publish keeps its schedule, so it is retried on the next sweep
     * once the review it is waiting for has been granted — rather than being
     * silently dropped and never happening at all.
     */
    public function testARefusedPublicationKeepsItsSchedule(): void
    {
        $key = ContentKey::page('home');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Home']);
        $this->schedules->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $report = $this->service(static fn (): ?string => 'Awaiting review')
            ->sweep($this->at('2026-08-01 09:05'));

        $this->assertSame([], $this->content->published);
        $this->assertEquals($this->at('2026-08-01 09:00'), $this->schedules->find($key)->publishAt);
        $this->assertSame(1, $report->refusedCount());
    }

    /**
     * A takedown is not gated. The publish gate exists so a review can stop
     * something reaching the public; a takedown is the direction that gate is
     * protecting, so asking permission to remove a page would let a broken
     * review plugin pin a page live past the date its editor set — which for a
     * legal notice or an expiring offer is the failure that actually costs.
     */
    public function testATakedownIsNotSubjectToThePublishGate(): void
    {
        $key = ContentKey::page('offer');
        $this->schedules->save($key, PublicationSchedule::of(null, $this->at('2026-08-01 09:00')));

        $this->service(static fn (): ?string => 'Awaiting review')->sweep($this->at('2026-08-01 09:05'));

        $this->assertSame([$key->toString()], $this->content->unpublished);
    }

    /* --------------------------------------------------------- missing -- */

    /**
     * A schedule pointing at a page that no longer has a working copy can never
     * succeed. Retrying it every sweep for ever is worse than dropping it, so it
     * is dropped — and reported, because silently forgetting an instruction is
     * exactly the quiet failure core.md exists to prevent.
     */
    public function testAScheduleForSomethingWithNothingToPromoteIsDropped(): void
    {
        $key = ContentKey::page('deleted');
        $this->schedules->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $report = $this->service()->sweep($this->at('2026-08-01 09:05'));

        $this->assertTrue($this->schedules->find($key)->isEmpty());
        $this->assertSame(1, $report->missingCount());
    }

    /* ------------------------------------------------------ attribution -- */

    /**
     * A scheduled publish is somebody's act, carried out later. Recording it
     * against nobody would make the audit trail read as though the site
     * published itself, so the sweeper acts as whoever set the schedule.
     */
    public function testTheSweepActsAsWhoeverScheduledIt(): void
    {
        $key = ContentKey::page('home');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Home']);
        $this->schedules->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null), 'editor-jo');

        $seen = [];
        $runAs = function (?string $user, callable $work) use (&$seen) {
            $seen[] = $user;

            return $work();
        };

        $this->service(null, $runAs)->sweep($this->at('2026-08-01 09:05'));

        $this->assertSame(['editor-jo'], $seen);
    }

    /* ------------------------------------------------------ resilience -- */

    /**
     * One document that throws must not strand every later one in the sweep.
     * Unattended work that stops halfway is indistinguishable from unattended
     * work that never ran.
     */
    public function testOneFailingDocumentDoesNotStopTheRest(): void
    {
        $bad = ContentKey::page('explodes');
        $good = ContentKey::page('fine');
        $this->content->drafts[$bad->toString()] = Content::create($bad, []);
        $this->content->drafts[$good->toString()] = Content::create($good, []);
        $this->content->throwFor = $bad->toString();

        $this->schedules->save($bad, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));
        $this->schedules->save($good, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $report = $this->service()->sweep($this->at('2026-08-01 09:05'));

        $this->assertSame([$good->toString()], $this->content->published);
        $this->assertSame(1, $report->failedCount());
        // The failure keeps its schedule: a storage error is transient in a way
        // a missing working copy is not, so the next sweep tries again.
        $this->assertFalse($this->schedules->find($bad)->isEmpty());
    }

    public function testSweepingNothingIsNotAnError(): void
    {
        $report = $this->service()->sweep($this->at('2026-08-01 09:05'));

        $this->assertSame(0, $report->total());
        $this->assertSame([], $report->outcomes);
    }

    public function testTheReportNamesWhatItDid(): void
    {
        $key = ContentKey::page('home');
        $this->content->drafts[$key->toString()] = Content::create($key, ['title' => 'Home']);
        $this->schedules->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $report = $this->service()->sweep($this->at('2026-08-01 09:05'));

        $this->assertCount(1, $report->outcomes);
        $this->assertSame('page:en:home', $report->outcomes[0]->key->toString());
        $this->assertSame(ScheduledAction::Publish, $report->outcomes[0]->action);
        $this->assertSame(SweepOutcome::DONE, $report->outcomes[0]->result);
    }
}

/** A schedule store held in memory, with the `scheduledBy` the port records. */
final class InMemoryScheduleStore implements ScheduleStore
{
    /** @var array<string, array{schedule: PublicationSchedule, by: ?string, key: ContentKey}> */
    private array $rows = [];

    public function find(ContentKey $key): PublicationSchedule
    {
        return $this->rows[$key->toString()]['schedule'] ?? PublicationSchedule::none();
    }

    public function save(ContentKey $key, PublicationSchedule $schedule, ?string $scheduledBy = null): void
    {
        if ($schedule->isEmpty()) {
            unset($this->rows[$key->toString()]);

            return;
        }

        $this->rows[$key->toString()] = [
            'schedule' => $schedule,
            'by' => $scheduledBy ?? ($this->rows[$key->toString()]['by'] ?? null),
            'key' => $key,
        ];
    }

    public function clear(ContentKey $key): void
    {
        unset($this->rows[$key->toString()]);
    }

    public function scheduledBy(ContentKey $key): ?string
    {
        return $this->rows[$key->toString()]['by'] ?? null;
    }

    public function due(DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ScheduledDocument $d): bool => $d->schedule->actionDueAt($now) !== null
        ));
    }

    public function all(): array
    {
        return array_values(array_map(
            static fn (array $row): ScheduledDocument => new ScheduledDocument($row['key'], $row['schedule'], $row['by']),
            $this->rows
        ));
    }
}

/** Storage that publishes only what has a working copy, and can be made to fail. */
final class SweepableStorage implements PublishingStorage
{
    /** @var array<string, Content> */
    public array $drafts = [];
    /** @var list<string> */
    public array $published = [];
    /** @var list<string> */
    public array $unpublished = [];
    public ?string $throwFor = null;

    public function find(ContentKey $key): ?Content
    {
        return null;
    }

    public function findByType(string $type, ?Locale $locale = null): array
    {
        return [];
    }

    public function types(): array
    {
        return [];
    }

    public function save(Content $content): void {}

    public function saveWithReason(Content $content, string $reason): void {}

    public function delete(ContentKey $key): bool
    {
        return true;
    }

    public function exists(ContentKey $key): bool
    {
        return isset($this->drafts[$key->toString()]);
    }

    public function draft(ContentKey $key): ?Content
    {
        return $this->drafts[$key->toString()] ?? null;
    }

    public function workingCopies(string $type, ?Locale $locale = null): array
    {
        return array_values($this->drafts);
    }

    public function publish(ContentKey $key): ?Content
    {
        if ($this->throwFor === $key->toString()) {
            throw new \RuntimeException('storage is unwell');
        }

        $draft = $this->drafts[$key->toString()] ?? null;
        if ($draft === null) {
            return null;
        }

        $this->published[] = $key->toString();

        return $draft;
    }

    public function unpublish(ContentKey $key): bool
    {
        if ($this->throwFor === $key->toString()) {
            throw new \RuntimeException('storage is unwell');
        }

        $this->unpublished[] = $key->toString();

        return true;
    }

    public function publicationOf(ContentKey $key): PublicationState
    {
        return PublicationState::of(null, null, null);
    }
}
