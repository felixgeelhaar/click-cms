<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * When a document should become public, and when it should stop being public.
 *
 * ## Why this is not a field on the document
 *
 * {@see \Click\Cms\Domain\Content\Content} carries no publication field on
 * purpose, and {@see PublicationState} explains at length why: publication is
 * presence in `content/`, and a stored field claiming otherwise could only ever
 * be the wrong one of two answers.
 *
 * A schedule does not break that rule, because it is not a claim about the
 * present. `publishAt` does not say "this is published"; it says "somebody
 * intends this to be published then". Intent and state can disagree without
 * either being wrong — that is what makes it an intent — so it is safe to store,
 * as long as it is stored somewhere that cannot be mistaken for the state. It
 * lives beside the document rather than inside it, in its own store, and the
 * only thing that ever reconciles the two is the sweeper.
 *
 * The second reason it lives outside the payload is mundane and decisive: the
 * schema validator discards every field a section type does not declare, so a
 * `publishAt` written into `data` would be dropped on the next save.
 *
 * ## Why a state, not a queue
 *
 * The obvious design is a queue of instructions replayed in order. It is wrong
 * here, because cron does not always run — a laptop asleep, a host that skipped
 * an hour, a site restored from backup a day later. Replaying a window that
 * opened at 09:00 and closed at 11:00, first swept at 11:30, publishes the page
 * and waits for the next sweep to take it down. For however long that is, a
 * page is public that its editor said should be gone.
 *
 * So {@see actionDueAt()} answers a different question: not "what instructions
 * have I not run yet" but "given everything that should have happened by now,
 * what state should this document be in". Late sweeps converge on the right
 * answer instead of walking through stale ones.
 *
 * Instances are immutable and always normalised to UTC.
 */
final class PublicationSchedule
{
    private function __construct(
        public readonly ?DateTimeImmutable $publishAt,
        public readonly ?DateTimeImmutable $unpublishAt,
    ) {}

    /**
     * Build a schedule, refusing a window that closes before it opens.
     *
     * @throws InvalidArgumentException When the takedown is not strictly after
     *         the publish. Equal instants are refused too: a window of zero
     *         length describes a page that is never actually up, which is
     *         almost certainly not what the editor meant, and saying so beats
     *         silently accepting it.
     */
    public static function of(?DateTimeImmutable $publishAt, ?DateTimeImmutable $unpublishAt): self
    {
        $publishAt = self::utc($publishAt);
        $unpublishAt = self::utc($unpublishAt);

        if ($publishAt !== null && $unpublishAt !== null && $unpublishAt <= $publishAt) {
            throw new InvalidArgumentException(
                'A scheduled takedown must be later than the scheduled publication it ends.'
            );
        }

        return new self($publishAt, $unpublishAt);
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * Rebuild from stored form, dropping anything unusable rather than throwing.
     *
     * The sweeper runs unattended across every schedule on the site. One file
     * holding a time nothing can parse must cost that one schedule, not the
     * run — the same leniency {@see \Click\Cms\Domain\Media\CropBox} applies for
     * the same reason.
     *
     * An impossible window that somehow reached disk keeps its publish and
     * drops its takedown. That is the reading that loses least: the editor
     * asked for the page to go up, and the instruction to take it down again
     * was never coherent.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $publishAt = self::parse($row['publishAt'] ?? null);
        $unpublishAt = self::parse($row['unpublishAt'] ?? null);

        if ($publishAt !== null && $unpublishAt !== null && $unpublishAt <= $publishAt) {
            $unpublishAt = null;
        }

        return new self($publishAt, $unpublishAt);
    }

    public function isEmpty(): bool
    {
        return $this->publishAt === null && $this->unpublishAt === null;
    }

    /**
     * The state this document should be in as of `$now`, expressed as the act
     * that would get it there — or null when nothing is due yet.
     *
     * The takedown is tested first because it is the later of the two whenever
     * both exist ({@see of()} guarantees that), so once it has passed it is the
     * standing instruction regardless of what came before it.
     */
    public function actionDueAt(DateTimeImmutable $now): ?ScheduledAction
    {
        if ($this->unpublishAt !== null && $this->unpublishAt <= $now) {
            return ScheduledAction::Unpublish;
        }

        if ($this->publishAt !== null && $this->publishAt <= $now) {
            return ScheduledAction::Publish;
        }

        return null;
    }

    /**
     * What is left once everything due at `$now` has been carried out.
     *
     * Both ends are consumed independently, so a swept window keeps its pending
     * takedown after its publish has fired, and a fully elapsed one comes back
     * empty — which is the signal to the store to forget it entirely.
     */
    public function withoutActionsDueAt(DateTimeImmutable $now): self
    {
        return new self(
            $this->publishAt !== null && $this->publishAt <= $now ? null : $this->publishAt,
            $this->unpublishAt !== null && $this->unpublishAt <= $now ? null : $this->unpublishAt,
        );
    }

    /**
     * The earliest instant at which this schedule has anything to do, or null
     * when it has nothing left. Lets a store skip a document without reasoning
     * about both ends itself.
     */
    public function nextDueAt(): ?DateTimeImmutable
    {
        if ($this->publishAt === null) {
            return $this->unpublishAt;
        }

        if ($this->unpublishAt === null) {
            return $this->publishAt;
        }

        return min($this->publishAt, $this->unpublishAt);
    }

    /**
     * @return array{publishAt: ?string, unpublishAt: ?string}
     */
    public function toArray(): array
    {
        return [
            'publishAt' => $this->publishAt?->format(DATE_ATOM),
            'unpublishAt' => $this->unpublishAt?->format(DATE_ATOM),
        ];
    }

    private static function parse(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return self::utc($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // A bare `new DateTimeImmutable` accepts relative expressions —
            // "tomorrow", "+1 week" — which would make a stored schedule mean
            // something different on every sweep. Only absolute instants are
            // schedules, so anything without a full date is refused.
            $parsed = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['error_count'] ?? 0) > 0 || ($errors['warning_count'] ?? 0) > 0)) {
            return null;
        }

        return self::utc($parsed);
    }

    private static function utc(?DateTimeImmutable $time): ?DateTimeImmutable
    {
        return $time?->setTimezone(new DateTimeZone('UTC'));
    }
}
