<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Storage\StorageInterface;

/**
 * Copies content from one storage backend to another.
 *
 * The reason this exists: moving a site from flat files to SQLite (or back)
 * must not mean losing what it has written, and doing it by hand — reading one
 * backend's files and writing another's rows — is exactly the error-prone step
 * that leaves a site apparently emptied. Working through the storage port means
 * one mover serves every pair of backends, present and future, with no
 * knowledge of either's on-disk shape.
 *
 * ## Which documents
 *
 * Every live document of every type it is told about, in every language.
 * `findByType(type, null)` returns all locales, so a page that exists in three
 * languages is three documents and all three come across.
 *
 * The set of types is supplied at construction rather than discovered, because
 * {@see StorageInterface} has no "list every type" — a backend is addressed by
 * key, and a key names a type the caller already knows. The alternative,
 * reaching past the port into a backend's directories or tables, would tie this
 * mover to one backend and defeat the point of having a port. So the integrator
 * that knows the site's types (the CLI, from the content directory or an
 * explicit list) names them.
 *
 * Version history is deliberately out of scope. The port exposes no way to
 * enumerate the versions of a document — that lives behind
 * {@see \Click\Cms\Domain\History\VersionStoreInterface}, a separate port — so a
 * mover written against {@see StorageInterface} can only see live documents.
 * Migrating history is a job for a tool given the version store, not this one;
 * claiming to move it from here would be a quiet half-migration.
 *
 * ## Safe to re-run
 *
 * A document already present in the target *identically* is skipped, not
 * rewritten. That makes a second run a no-op rather than a source of churn, and
 * means a run interrupted halfway can simply be started again: what already
 * arrived is left alone and only the remainder is copied. Identity is the whole
 * stored form — key, payload and both timestamps — because a target document
 * that differs is a real difference the operator should see reported, not one
 * this mover should paper over by skipping or silently clobber without a count.
 */
final class StorageMigrator
{
    /** @var list<string> */
    private readonly array $types;

    /**
     * @param iterable<string> $types The content types to move. Duplicates and
     *        blanks are dropped so the caller can pass a discovered list without
     *        having to clean it first.
     */
    public function __construct(iterable $types)
    {
        $seen = [];
        foreach ($types as $type) {
            $type = trim($type);
            if ($type !== '' && !isset($seen[$type])) {
                $seen[$type] = true;
            }
        }

        $this->types = array_keys($seen);
    }

    /**
     * Copy every live document of the configured types from one backend to
     * another, returning what happened.
     *
     * The summary is:
     *   - `copied`  int   documents written to the target
     *   - `skipped` int   documents already present identically
     *   - `types`   array<string, array{copied:int, skipped:int}> per-type tally
     *   - `skippedDetails` list<array{key:string, reason:string}> what was skipped and why
     *
     * @return array{
     *     copied:int,
     *     skipped:int,
     *     types:array<string, array{copied:int, skipped:int}>,
     *     skippedDetails:list<array{key:string, reason:string}>
     * }
     */
    public function migrate(StorageInterface $from, StorageInterface $to): array
    {
        $copied = 0;
        $skipped = 0;
        $perType = [];
        $skippedDetails = [];

        foreach ($this->types as $type) {
            $typeCopied = 0;
            $typeSkipped = 0;

            foreach ($from->findByType($type, null) as $document) {
                $existing = $to->find($document->key);

                // Already there and identical: leave it. This is what makes a
                // re-run a no-op instead of a rewrite, and what lets an
                // interrupted run resume without redoing its finished work.
                if ($existing !== null && $existing->toArray() === $document->toArray()) {
                    $typeSkipped++;
                    $skipped++;
                    $skippedDetails[] = [
                        'key' => $document->key->toString(),
                        'reason' => 'unchanged',
                    ];
                    continue;
                }

                $to->save($document);
                $typeCopied++;
                $copied++;
            }

            $perType[$type] = ['copied' => $typeCopied, 'skipped' => $typeSkipped];
        }

        return [
            'copied' => $copied,
            'skipped' => $skipped,
            'types' => $perType,
            'skippedDetails' => $skippedDetails,
        ];
    }
}
