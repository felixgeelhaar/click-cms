<?php

declare(strict_types=1);

namespace Click\Cms\Application\Publishing;

use Click\Cms\Domain\Publishing\ScheduledAction;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * What became of one scheduled document during a sweep.
 *
 * Every due document produces exactly one of these, including the ones nothing
 * happened to. A sweep that reported only its successes would be a sweep whose
 * failures are invisible, and this runs from cron where invisible means nobody
 * ever finds out — the failure mode `core.md` names under "Failure is visible".
 */
final class SweepOutcome
{
    /** Promoted or taken down as asked. */
    public const DONE = 'done';

    /** A plugin refused the publish; the schedule stands and will be retried. */
    public const REFUSED = 'refused';

    /** Nothing to promote at that key. The schedule was dropped. */
    public const MISSING = 'missing';

    /** Storage threw. The schedule stands and will be retried. */
    public const FAILED = 'failed';

    public function __construct(
        public readonly ContentKey $key,
        public readonly ScheduledAction $action,
        public readonly string $result,
        /** Why, for the results that have a why. Null for {@see DONE}. */
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->toString(),
            'action' => $this->action->value,
            'result' => $this->result,
            'reason' => $this->reason,
        ];
    }

    public function describe(): string
    {
        $line = "{$this->result}: {$this->action->value} {$this->key->toString()}";

        return $this->reason === null ? $line : "{$line} — {$this->reason}";
    }
}
