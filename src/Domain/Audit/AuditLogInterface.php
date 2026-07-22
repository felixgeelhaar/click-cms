<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Audit;

use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Persistence port for the audit trail.
 *
 * Declared in the domain, like every other port, so the layer that decides
 * *what* to record depends on the recording of it and not the reverse — the
 * storage decorator that writes entries talks to this, never to a file.
 *
 * The trail is append-only by contract, not merely by convention: {@see append}
 * adds, and nothing here can change or remove what is already written. An audit
 * log with an edit or a delete on its port is one an implementation is invited
 * to make revisable, and a revisable audit log is not one. The two reads exist
 * because that is all an operator asks of it — the recent history of the whole
 * system, and the history of one document — and offering more surface than that
 * would be surface to keep append-only for no caller's sake.
 */
interface AuditLogInterface
{
    /**
     * Add one entry to the trail.
     *
     * Appends only: it must never rewrite, reorder or drop what is already
     * recorded. A failure to append is expected to surface rather than be
     * swallowed — a trail that has quietly stopped recording is the silent
     * degradation this codebase exists to refuse, and an operator trusting a
     * safety net that is no longer there is worse off than one shown an error.
     */
    public function append(AuditEntry $entry): void;

    /**
     * The most recent entries across every document, newest first.
     *
     * @return list<AuditEntry>
     */
    public function recent(int $limit): array;

    /**
     * The most recent entries for one document, newest first.
     *
     * Per document rather than per slug: `page:en:home` and `page:de:home` are
     * two documents everywhere else in the system, and their histories do not
     * merge here either.
     *
     * @return list<AuditEntry>
     */
    public function forDocument(ContentKey $key, int $limit): array;
}
