<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * A document and the schedule standing against it.
 *
 * A named pair rather than a tuple, because the sweeper passes it through
 * several hands — store, service, log line — and `$row['key']` read from an
 * array is one typo away from a silent miss in code that runs unattended.
 */
final class ScheduledDocument
{
    public function __construct(
        public readonly ContentKey $key,
        public readonly PublicationSchedule $schedule,
        /**
         * Whoever set this schedule, if it is known.
         *
         * Carried here rather than looked up by the sweeper because a scheduled
         * publish is that person's act, carried out later, and the audit trail
         * should say so. Nullable because a schedule written by a CLI task or a
         * seeder genuinely has no author, and inventing one would be worse than
         * recording none.
         */
        public readonly ?string $scheduledBy = null,
    ) {}
}
