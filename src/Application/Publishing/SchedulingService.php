<?php

declare(strict_types=1);

namespace Click\Cms\Application\Publishing;

use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\Publishing\ScheduledAction;
use Click\Cms\Domain\Publishing\ScheduledDocument;
use Click\Cms\Domain\Publishing\ScheduleStore;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Carries out the publications and takedowns that have come due.
 *
 * The whole of scheduled publishing that is not the schedule itself: read what
 * is due, do it, record what happened, and consume the instruction so it cannot
 * happen twice.
 *
 * ## Why this is swept rather than triggered
 *
 * The obvious alternative is to check the schedule on each page request and
 * publish opportunistically. It is wrong for this CMS, for two reasons that
 * matter more than the convenience of not needing cron:
 *
 * - **A page nobody visits never publishes.** The pages most likely to be
 *   scheduled — an announcement, an offer opening at nine — are exactly the ones
 *   with no traffic until they are live, so the trigger would fire on the first
 *   visitor rather than at the appointed time.
 * - **It would put a write on the read path.** The public read path is one file
 *   read on shared hosting, deliberately, and turning it into a possible
 *   publish makes every visitor's latency depend on what the schedule happens to
 *   hold.
 *
 * So it is a sweep, from `bin/click-schedule.php`, and a site that never sets up
 * the cron entry gets a scheduling feature that visibly does nothing rather than
 * one that half works. `UpdateScheduler` and `BackupScheduler` already
 * established that shape.
 *
 * ## What it refuses to guess
 *
 * A publish still goes through the publish gate, so a review workflow that
 * refuses a manual publish refuses a scheduled one too — an unattended path past
 * a gate is not a gate. A takedown does not, and {@see sweep()} says why.
 */
final class SchedulingService
{
    /** @var (Closure(ContentKey, array<string, mixed>): ?string)|null */
    private readonly ?Closure $refusal;

    /** @var (Closure(?string, callable): mixed)|null */
    private readonly ?Closure $runAs;

    /**
     * @param ?callable(ContentKey, array<string, mixed>): ?string $refusal
     *        Asks whether a publish may happen, answering with the reason it may
     *        not, or null to permit. A callable rather than the {@see
     *        \Click\Cms\Application\Plugin\PublishGate} itself so the sweeper can
     *        be tested without a plugin manager, and so a site with no plugin
     *        system gets a sweeper that simply publishes.
     * @param ?callable(?string, callable): mixed $runAs
     *        Runs a piece of work attributed to a named account, so the audit
     *        trail records a scheduled publish against whoever scheduled it
     *        rather than against nobody. Null means no attribution is available
     *        — the work still runs.
     */
    public function __construct(
        private readonly ScheduleStore $schedules,
        private readonly PublishingStorage $content,
        ?callable $refusal = null,
        ?callable $runAs = null,
    ) {
        $this->refusal = $refusal === null ? null : Closure::fromCallable($refusal);
        $this->runAs = $runAs === null ? null : Closure::fromCallable($runAs);
    }

    /**
     * Do everything due at `$now`, and report on all of it.
     *
     * @param ?DateTimeImmutable $now Defaults to the present. Injected by tests,
     *        and by nothing else: a sweep that could be told it was yesterday
     *        would be a way to unpublish the site.
     */
    public function sweep(?DateTimeImmutable $now = null): SweepReport
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $outcomes = [];

        foreach ($this->schedules->due($now) as $document) {
            $action = $document->schedule->actionDueAt($now);

            // Nothing due. Unreachable through `due()`, which filters on exactly
            // this, but a store is an interface somebody else may implement.
            if ($action === null) {
                continue;
            }

            $outcomes[] = $this->carryOut($document, $action, $now);
        }

        return new SweepReport($outcomes);
    }

    private function carryOut(ScheduledDocument $document, ScheduledAction $action, DateTimeImmutable $now): SweepOutcome
    {
        $key = $document->key;

        try {
            return $action === ScheduledAction::Publish
                ? $this->publish($document, $now)
                : $this->unpublish($document, $now);
        } catch (Throwable $e) {
            // The schedule is deliberately left standing. A storage error is
            // usually transient — a full disk, a database that went away — and
            // the instruction is still what the editor wants, so the next sweep
            // tries again. Dropping it here would turn a five-minute outage into
            // a page that never publishes and never says why.
            error_log("click-cms: scheduled {$action->value} of {$key->toString()} failed: {$e->getMessage()}");

            return new SweepOutcome($key, $action, SweepOutcome::FAILED, $e->getMessage());
        }
    }

    private function publish(ScheduledDocument $document, DateTimeImmutable $now): SweepOutcome
    {
        $key = $document->key;
        $user = $this->actor($document);

        $refusal = $this->refusal === null ? null : ($this->refusal)($key, $user);
        if ($refusal !== null) {
            // Kept, not dropped. The review this is waiting for may be granted
            // before the next sweep, and a schedule discarded on the first
            // refusal would mean the page never goes live even once it is
            // approved.
            return new SweepOutcome($key, ScheduledAction::Publish, SweepOutcome::REFUSED, $refusal);
        }

        $published = $this->as($document->scheduledBy, fn () => $this->content->publish($key));

        if ($published === null) {
            // There is nothing at that key to promote — the page was deleted
            // after being scheduled, most likely. This can never succeed, so
            // retrying it every sweep for ever is worse than dropping it. It is
            // reported as trouble so that dropping it is not silent.
            $this->consume($document, $now);

            return new SweepOutcome(
                $key,
                ScheduledAction::Publish,
                SweepOutcome::MISSING,
                'Nothing to publish at this address; the schedule was dropped.'
            );
        }

        $this->consume($document, $now);

        return new SweepOutcome($key, ScheduledAction::Publish, SweepOutcome::DONE);
    }

    /**
     * Take a page down.
     *
     * Deliberately not gated. The publish gate exists so a review can stop
     * something reaching the public; a takedown moves in the direction the gate
     * is protecting, so asking its permission would let a misconfigured or
     * broken review plugin hold a page live past the date its editor set. For an
     * expiring offer that is untidy; for a legal notice or a takedown request it
     * is the failure that actually costs something.
     *
     * It is also not conditional on the page being live. `unpublish()` answering
     * false means there was nothing to remove, which is the state the schedule
     * asked for — so the instruction is satisfied either way and consumed.
     */
    private function unpublish(ScheduledDocument $document, DateTimeImmutable $now): SweepOutcome
    {
        $key = $document->key;

        $this->as($document->scheduledBy, fn (): bool => $this->content->unpublish($key));
        $this->consume($document, $now);

        return new SweepOutcome($key, ScheduledAction::Unpublish, SweepOutcome::DONE);
    }

    /**
     * Write back what is left of the schedule once the due part has been done.
     *
     * An empty remainder makes the store forget the document, so a fired
     * one-off schedule leaves nothing behind for later sweeps to open.
     */
    private function consume(ScheduledDocument $document, DateTimeImmutable $now): void
    {
        $this->schedules->save(
            $document->key,
            $document->schedule->withoutActionsDueAt($now),
            $document->scheduledBy,
        );
    }

    /**
     * Run the write attributed to whoever scheduled it, when that is possible.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function as(?string $username, callable $work): mixed
    {
        if ($this->runAs === null) {
            return $work();
        }

        return ($this->runAs)($username, $work);
    }

    /**
     * The user record the publish gate is asked about.
     *
     * A sweep has no session, so this is the little that is actually known:
     * the name on the schedule. A gate that needs more than a username is
     * asking about a request that is not happening.
     *
     * @return array<string, mixed>
     */
    private function actor(ScheduledDocument $document): array
    {
        return [
            'username' => $document->scheduledBy,
            'scheduled' => true,
        ];
    }
}
