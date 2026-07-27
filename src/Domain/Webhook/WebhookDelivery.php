<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * One event on its way to one endpoint.
 *
 * ## Why a delivery is a stored thing rather than an HTTP call
 *
 * The obvious implementation fires the request inside the save that triggered
 * it. That is wrong here for three reasons, in increasing order of severity:
 *
 * - It puts a remote host's latency on the editor's Save button.
 * - A receiver that hangs holds a PHP worker until the timeout, and on shared
 *   hosting there are few workers.
 * - It cannot retry. A receiver that is down during the save simply never hears
 *   about it, which makes the whole mechanism unreliable exactly when
 *   reliability is the point.
 *
 * So a delivery is queued to disk by the hook and sent by a sweep, the same
 * shape scheduled publishing uses and for the same reason: no daemon, no queue
 * service, nothing that does not run on shared hosting.
 *
 * Instances are immutable; each attempt produces a new one.
 */
final class WebhookDelivery
{
    public const PENDING = 'pending';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly string $id,
        public readonly string $endpointId,
        public readonly string $event,
        public readonly array $payload,
        public readonly int $createdAt,
        public readonly int $attempts,
        public readonly int $nextAttemptAt,
        public readonly string $status,
        public readonly ?string $lastError,
        public readonly ?int $lastStatus,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function queued(
        string $id,
        string $endpointId,
        string $event,
        array $payload,
        int $now,
    ): self {
        return new self($id, $endpointId, $event, $payload, $now, 0, $now, self::PENDING, null, null);
    }

    public function isDueAt(int $now): bool
    {
        return $this->status === self::PENDING && $this->nextAttemptAt <= $now;
    }

    public function succeeded(int $now, int $httpStatus): self
    {
        return new self(
            $this->id,
            $this->endpointId,
            $this->event,
            $this->payload,
            $this->createdAt,
            $this->attempts + 1,
            $now,
            self::DELIVERED,
            null,
            $httpStatus,
        );
    }

    /**
     * Record an attempt that did not land, and decide whether to try again.
     *
     * The attempt count is incremented before the policy is consulted, so
     * "exhausted" means attempts actually made rather than attempts scheduled —
     * an off-by-one here is the difference between five tries and six, and only
     * one of those matches what the policy says.
     */
    public function failed(string $reason, RetryPolicy $policy, int $now, ?int $httpStatus): self
    {
        $attempts = $this->attempts + 1;
        $exhausted = $policy->isExhausted($attempts);

        return new self(
            $this->id,
            $this->endpointId,
            $this->event,
            $this->payload,
            $this->createdAt,
            $attempts,
            $now + $policy->delayAfter($attempts),
            $exhausted ? self::FAILED : self::PENDING,
            $reason,
            $httpStatus,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $payload = $row['payload'] ?? [];

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['endpointId'] ?? ''),
            (string) ($row['event'] ?? ''),
            is_array($payload) ? $payload : [],
            (int) ($row['createdAt'] ?? 0),
            (int) ($row['attempts'] ?? 0),
            (int) ($row['nextAttemptAt'] ?? 0),
            self::readStatus($row['status'] ?? null),
            isset($row['lastError']) && is_string($row['lastError']) ? $row['lastError'] : null,
            isset($row['lastStatus']) && is_numeric($row['lastStatus']) ? (int) $row['lastStatus'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'endpointId' => $this->endpointId,
            'event' => $this->event,
            'payload' => $this->payload,
            'createdAt' => $this->createdAt,
            'attempts' => $this->attempts,
            'nextAttemptAt' => $this->nextAttemptAt,
            'status' => $this->status,
            'lastError' => $this->lastError,
            'lastStatus' => $this->lastStatus,
        ];
    }

    /**
     * An unrecognised status reads as failed, not pending.
     *
     * The queue is swept unattended: a corrupt or future-version status that
     * defaulted to pending would be retried on every sweep for ever, whereas one
     * that reads as failed simply sits there until somebody looks.
     */
    private static function readStatus(mixed $value): string
    {
        return in_array($value, [self::PENDING, self::DELIVERED, self::FAILED], true)
            ? $value
            : self::FAILED;
    }
}
