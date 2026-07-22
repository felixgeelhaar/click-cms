<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Audit;

use Click\Cms\Domain\ValueObjects\ContentKey;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One recorded fact: who did what, to which document, and when.
 *
 * The record exists because a restore can replace a working copy, a publish
 * changes what the public sees, and a preview link can be handed to somebody
 * with no account — actions whose consequences outlive the request that caused
 * them and whose author would otherwise be unknowable the moment it returns. An
 * audit entry is the answer to "who changed this, and when", asked after the
 * fact when it is too late to watch.
 *
 * Immutable, and deliberately so: an entry that could be edited after the fact
 * is not evidence of anything. It is a value, not an aggregate — there is no
 * behaviour to change here, only a fact to carry.
 *
 * Like every other domain type this reads no clock. The moment is supplied by
 * the caller, because the layer that may touch `time()` is the one that writes
 * the entry, not the one that models it — and because a fact whose timestamp is
 * read at construction cannot be tested without freezing time.
 */
final class AuditEntry
{
    private function __construct(
        /** The account that performed the write, or null for a write no session owned. */
        public readonly ?string $actor,
        public readonly AuditAction $action,
        /** The document's key in string form — `type:locale:slug`. */
        public readonly string $document,
        public readonly DateTimeImmutable $recordedAt,
        /** A short, human-facing note, when the bare action does not say enough. */
        public readonly ?string $detail,
    ) {}

    public static function of(
        ?string $actor,
        AuditAction $action,
        ContentKey $document,
        DateTimeImmutable $recordedAt,
        ?string $detail = null,
    ): self {
        return new self(
            self::normalise($actor),
            $action,
            $document->toString(),
            $recordedAt,
            self::normalise($detail),
        );
    }

    /**
     * Rebuild a stored record.
     *
     * The document is kept as a plain string rather than parsed back into a
     * {@see ContentKey}: an audit entry is a historical fact, and a key shape
     * that was legal when it was written but is not now must still be readable —
     * refusing to load the record would lose the very evidence it exists to be.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        foreach (['action', 'document', 'recordedAt'] as $required) {
            if (!isset($row[$required]) || !is_string($row[$required])) {
                throw new InvalidArgumentException("Stored audit entry is missing its \"{$required}\".");
            }
        }

        $action = AuditAction::tryFrom($row['action']);
        if ($action === null) {
            // Unlike a version's reason, an unrecognised action is not coerced
            // to a default. A version normalises so it can still recover the
            // document it protects; an audit entry *is* the fact, and inventing
            // an action for it would fabricate evidence rather than preserve it.
            throw new InvalidArgumentException("Unknown audit action: {$row['action']}");
        }

        $actor = $row['actor'] ?? null;
        $detail = $row['detail'] ?? null;

        return new self(
            self::normalise(is_string($actor) ? $actor : null),
            $action,
            $row['document'],
            new DateTimeImmutable($row['recordedAt']),
            self::normalise(is_string($detail) ? $detail : null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actor' => $this->actor,
            'action' => $this->action->value,
            'document' => $this->document,
            'recordedAt' => $this->recordedAt->format(DATE_ATOM),
            'detail' => $this->detail,
        ];
    }

    /**
     * An empty string and an absent value are the same fact — nobody
     * identifiable, or nothing to note — and keeping both would invite a caller
     * to test for one and miss the other.
     */
    private static function normalise(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
