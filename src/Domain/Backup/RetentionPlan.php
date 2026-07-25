<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Backup;

/**
 * Which archives retention may drop, and which pool entries may go with them.
 *
 * This is the one calculation in the backup feature that can destroy data
 * silently, so it is a pure function with no I/O at all: given the archives that
 * exist, what each one needs from the pool, and what the pool holds, it says
 * what may be deleted. Nothing here opens a file, which is what lets the nasty
 * cases — a shared pool entry, an unreadable manifest — be tested exactly rather
 * than approximately.
 *
 * ## The failure this exists to prevent
 *
 * Media is shared. Seven nightly archives of an unchanged library all point at
 * the same `pool/<sha>.jpg`, which is the entire reason the pool is worth
 * having. Pruning the oldest archive and deleting "its" media would therefore
 * take the pictures out of the six that remain — and nothing would report it.
 * The next restore would produce a site with every page intact and every image
 * gone, and the backup that could have fixed it was the one just deleted.
 *
 * So the rule is not "delete what the pruned archive referenced". It is: compute
 * the live set from every *surviving* manifest, and delete only what nothing in
 * that set names. An entry the pruned archive referenced and a survivor also
 * references stays, because it is live; an entry no survivor names goes, because
 * it is not.
 *
 * ## When the answer is not known
 *
 * A surviving archive whose manifest could not be read has unknown
 * requirements — a corrupt ZIP, a manifest that will not parse, a file being
 * written by another process. There is no safe way to guess: treating it as
 * needing nothing is precisely the assumption that empties the pool underneath
 * it. So the pool is not pruned at all on that run, {@see poolPruningRefused()}
 * says so, and the operator gets a slightly larger pool instead of a slightly
 * broken backup. Retention by age still proceeds — an unreadable archive is not
 * a reason to stop retaining, only a reason not to delete bytes on its behalf.
 */
final class RetentionPlan
{
    /**
     * @param list<string> $archivesToDelete
     * @param list<string> $poolEntriesToDelete
     */
    private function __construct(
        private readonly array $archivesToDelete,
        private readonly array $poolEntriesToDelete,
        private readonly bool $poolPruningRefused,
    ) {}

    /**
     * @param list<string> $archives Every archive that exists, by name. Names are
     *        timestamps, so sorting them ascending sorts them chronologically.
     * @param int $keep How many of the newest to retain. Never fewer than one:
     *        a retention setting of zero means somebody typed zero, and deleting
     *        every backup a site has because of a config value is not a service
     *        anyone asked for.
     * @param array<string, list<string>|null> $referencesByArchive What each
     *        archive needs from the pool. `null` means its manifest could not be
     *        read, which is not the same as "needs nothing".
     * @param list<string> $poolEntries Everything currently in the pool.
     */
    public static function compute(
        array $archives,
        int $keep,
        array $referencesByArchive,
        array $poolEntries,
    ): self {
        $keep = max(1, $keep);

        $ordered = array_values($archives);
        sort($ordered, SORT_STRING);

        $survivors = $keep >= count($ordered) ? $ordered : array_slice($ordered, -$keep);
        $doomed = array_values(array_diff($ordered, $survivors));

        $refused = false;
        $live = [];

        foreach ($survivors as $archive) {
            $refs = $referencesByArchive[$archive] ?? null;

            if (!is_array($refs)) {
                $refused = true;
                continue;
            }

            foreach ($refs as $ref) {
                $live[$ref] = true;
            }
        }

        $unreferenced = [];
        if (!$refused) {
            foreach ($poolEntries as $entry) {
                if (!isset($live[$entry])) {
                    $unreferenced[] = $entry;
                }
            }
            sort($unreferenced, SORT_STRING);
        }

        return new self($doomed, $unreferenced, $refused);
    }

    /** @return list<string> */
    public function archivesToDelete(): array
    {
        return $this->archivesToDelete;
    }

    /** @return list<string> */
    public function poolEntriesToDelete(): array
    {
        return $this->poolEntriesToDelete;
    }

    /**
     * True when a surviving archive would not say what it needs, so no pool
     * entry was condemned on this run.
     */
    public function poolPruningRefused(): bool
    {
        return $this->poolPruningRefused;
    }

    public function isEmpty(): bool
    {
        return $this->archivesToDelete === [] && $this->poolEntriesToDelete === [];
    }
}
