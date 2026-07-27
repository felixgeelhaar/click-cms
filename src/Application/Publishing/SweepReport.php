<?php

declare(strict_types=1);

namespace Click\Cms\Application\Publishing;

use Click\Cms\Domain\Publishing\ScheduledAction;

/**
 * Everything one sweep did, for the cron log and the exit code.
 */
final class SweepReport
{
    /**
     * @param list<SweepOutcome> $outcomes
     */
    public function __construct(public readonly array $outcomes = []) {}

    public function total(): int
    {
        return count($this->outcomes);
    }

    public function publishedCount(): int
    {
        return $this->countWhere(
            static fn (SweepOutcome $o): bool => $o->result === SweepOutcome::DONE
                && $o->action === ScheduledAction::Publish
        );
    }

    public function unpublishedCount(): int
    {
        return $this->countWhere(
            static fn (SweepOutcome $o): bool => $o->result === SweepOutcome::DONE
                && $o->action === ScheduledAction::Unpublish
        );
    }

    public function refusedCount(): int
    {
        return $this->countResult(SweepOutcome::REFUSED);
    }

    public function missingCount(): int
    {
        return $this->countResult(SweepOutcome::MISSING);
    }

    public function failedCount(): int
    {
        return $this->countResult(SweepOutcome::FAILED);
    }

    /**
     * Whether anything went wrong badly enough to be worth waking somebody.
     *
     * A refusal is not trouble — it is a review plugin doing its job, and the
     * schedule will be retried. A storage failure is, and a schedule dropped
     * for having nothing to publish is, because both mean an instruction an
     * editor gave will not be carried out without someone looking at it.
     */
    public function hasTrouble(): bool
    {
        return $this->failedCount() > 0 || $this->missingCount() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'published' => $this->publishedCount(),
            'unpublished' => $this->unpublishedCount(),
            'refused' => $this->refusedCount(),
            'missing' => $this->missingCount(),
            'failed' => $this->failedCount(),
            'outcomes' => array_map(static fn (SweepOutcome $o): array => $o->toArray(), $this->outcomes),
        ];
    }

    /**
     * @param callable(SweepOutcome): bool $predicate
     */
    private function countWhere(callable $predicate): int
    {
        return count(array_filter($this->outcomes, $predicate));
    }

    private function countResult(string $result): int
    {
        return $this->countWhere(static fn (SweepOutcome $o): bool => $o->result === $result);
    }
}
