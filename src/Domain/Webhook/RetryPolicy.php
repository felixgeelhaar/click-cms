<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

use InvalidArgumentException;

/**
 * How hard to try, and how long to wait between tries.
 *
 * Exponential rather than fixed, because a fixed interval turns every receiver
 * outage into a self-inflicted flood: an endpoint down for an hour is knocked on
 * sixty times at one-minute spacing, all of it arriving precisely when the
 * receiver is least able to cope, and none of it any more likely to succeed than
 * the first attempt was.
 *
 * Capped, because unbounded doubling means a receiver fixed on Monday morning
 * waits until Wednesday for its next delivery.
 *
 * Bounded in count, because a queue that retries for ever grows without limit
 * against an endpoint whose owner has forgotten it exists, and every sweep from
 * then on spends its time on deliveries that will never land.
 *
 * There is deliberately **no jitter**. It is the right answer for a fleet of
 * senders retrying against one receiver, and irrelevant here: there is one
 * sender, running from one cron entry, and its sweep interval is already the
 * dominant source of spread. Adding randomness would only make the delay
 * untestable in exchange for solving a thundering herd of one.
 */
final class RetryPolicy
{
    private function __construct(
        public readonly int $maxAttempts,
        public readonly int $baseSeconds,
        public readonly int $capSeconds,
    ) {}

    public static function of(int $maxAttempts, int $baseSeconds, int $capSeconds): self
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('A retry policy must allow at least one attempt.');
        }

        if ($baseSeconds < 1 || $capSeconds < $baseSeconds) {
            throw new InvalidArgumentException('A retry delay must be positive, and the cap at least the base.');
        }

        return new self($maxAttempts, $baseSeconds, $capSeconds);
    }

    /**
     * Six attempts over roughly two hours: a minute, two, four, eight, sixteen.
     * Long enough to ride out a deploy or a restart, short enough that a
     * permanently broken endpoint stops costing anything by lunchtime.
     */
    public static function standard(): self
    {
        return new self(6, 60, 3600);
    }

    /**
     * How long to wait after the given attempt number, in seconds.
     *
     * The shift is bounded before it happens rather than after. `1 << 40` is not
     * a large number in PHP, it is an overflowed one, and a negative delay would
     * make a failed delivery due in the past — retried on every sweep, for ever,
     * which is the exact behaviour the cap exists to prevent.
     */
    public function delayAfter(int $attempt): int
    {
        $steps = max(0, $attempt - 1);

        // 30 doublings is already far past any sane cap; stopping here keeps the
        // arithmetic inside the range where it means what it looks like.
        if ($steps > 30) {
            return $this->capSeconds;
        }

        return min($this->capSeconds, $this->baseSeconds * (1 << $steps));
    }

    public function isExhausted(int $attempts): bool
    {
        return $attempts >= $this->maxAttempts;
    }
}
