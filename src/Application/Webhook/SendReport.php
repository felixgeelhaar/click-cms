<?php

declare(strict_types=1);

namespace Click\Cms\Application\Webhook;

/**
 * What one delivery sweep did, for the cron log and the exit code.
 */
final class SendReport
{
    /**
     * @param list<array<string, mixed>> $outcomes
     */
    public function __construct(public readonly array $outcomes = []) {}

    public function total(): int
    {
        return count($this->outcomes);
    }

    public function deliveredCount(): int
    {
        return $this->countResult('delivered');
    }

    public function failedCount(): int
    {
        return $this->countResult('failed');
    }

    public function orphanedCount(): int
    {
        return $this->countResult('orphaned');
    }

    /**
     * Whether the run is worth somebody's attention.
     *
     * A single failed attempt is not: it will be retried, and a receiver
     * restarting is ordinary. An orphaned delivery is, because it means work was
     * discarded — an endpoint was deleted or switched off with events still
     * queued for it, and nobody would otherwise find out.
     */
    public function hasTrouble(): bool
    {
        return $this->orphanedCount() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'delivered' => $this->deliveredCount(),
            'failed' => $this->failedCount(),
            'orphaned' => $this->orphanedCount(),
            'outcomes' => $this->outcomes,
        ];
    }

    private function countResult(string $result): int
    {
        return count(array_filter(
            $this->outcomes,
            static fn (array $o): bool => ($o['result'] ?? null) === $result
        ));
    }
}
