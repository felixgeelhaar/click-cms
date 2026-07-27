<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

use Click\Cms\Domain\ValueObjects\ContentKey;
use DateTimeImmutable;

/**
 * Where the intent to publish or unpublish later is kept.
 *
 * A port in the domain, implementations outside it — the same arrangement
 * {@see \Click\Cms\Domain\Storage\StorageInterface} and
 * {@see \Click\Cms\Domain\History\VersionStoreInterface} already use, and for
 * the same reason: the sweeper's rules are worth testing without a filesystem.
 *
 * Schedules deliberately do not live in content storage. They are not content —
 * they carry no editorial payload, they are not versioned, they are not
 * translated, and a site switching storage backend has no reason to migrate
 * them. Putting them in `content/` would also make every schedule a document
 * the delivery API had to learn to hide.
 */
interface ScheduleStore
{
    /** Never null: an unscheduled document has an empty schedule. */
    public function find(ContentKey $key): PublicationSchedule;

    /**
     * Record a schedule, or forget the document when the schedule is empty.
     *
     * Saving empty rather than requiring a separate call means a caller that
     * clears both fields in a form does the right thing without knowing it had
     * to.
     *
     * @param ?string $scheduledBy Whoever asked for this, for the audit trail
     *        the sweep will write later. Null leaves an existing attribution
     *        alone rather than erasing it, so a caller that only adjusts a time
     *        does not have to know who set it first.
     */
    public function save(ContentKey $key, PublicationSchedule $schedule, ?string $scheduledBy = null): void;

    /** Whoever set the schedule at this key, if anyone is recorded. */
    public function scheduledBy(ContentKey $key): ?string;

    public function clear(ContentKey $key): void;

    /**
     * Everything with an action due at `$now`, in no guaranteed order.
     *
     * @return list<ScheduledDocument>
     */
    public function due(DateTimeImmutable $now): array;

    /**
     * Everything scheduled, due or not — what an admin listing of pending
     * publications reads.
     *
     * @return list<ScheduledDocument>
     */
    public function all(): array;
}
