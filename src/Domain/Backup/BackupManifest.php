<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Backup;

use InvalidArgumentException;

/**
 * What an archive says it contains — the only thing a restore ever trusts, and
 * only after checking it.
 *
 * A backup that reports success while containing nothing is the bug this whole
 * feature was rebuilt around, so the manifest is not a courtesy note. It is the
 * index a restore iterates: every document and every media file is written
 * because the manifest named it, never because it happened to be in the ZIP.
 * That inversion is what makes a truncated or doctored archive detectable rather
 * than a partially applied restore, and it means a stray entry somebody appended
 * to the archive is simply never looked at.
 *
 * Three things are therefore recorded per item and all three are checked before
 * a byte is written: where it is (the entry name, or the pool reference), how
 * big it is, and its SHA-256.
 *
 * ## Where the media went
 *
 * `mediaStorage` says which of the two shapes this archive is:
 *
 *  - `embedded` — the media bytes are inside the ZIP. This is what a download
 *    is, because an archive that referred to a pool the person holding it does
 *    not have would be an archive that cannot be restored.
 *  - `pool` — the media lives once in `data/backups/pool`, shared with every
 *    other retained archive, and the manifest names the entries it needs. This
 *    is what a scheduled backup is, and it is why seven nightly runs of an
 *    unchanged library cost one copy rather than seven.
 *
 * ## Media that was deliberately left out
 *
 * `skippedMedia` exists so that a file above the configured size ceiling is a
 * *recorded* omission. Quietly dropping the 2 GB video and reporting success is
 * the same failure as the original bug wearing different clothes: the operator
 * finds out when they restore, which is the one moment they cannot afford to.
 */
final class BackupManifest
{
    public const GENERATOR = 'click-cms';

    /**
     * Bumped when the archive layout changes in a way an older reader would get
     * wrong. Version 1 was the directory walk of `content/` this replaced; it is
     * refused rather than half-understood, because its "backup" of a site on a
     * database backend contained no documents at all and restoring one would
     * silently produce an empty site.
     */
    public const FORMAT_VERSION = 2;

    public const MEDIA_EMBEDDED = 'embedded';
    public const MEDIA_POOLED = 'pool';

    /**
     * @param list<array{entry: string, key: string, type: string, slug: string, locale: string, sha256: string, bytes: int}> $documents
     * @param list<array{path: string, sha256: string, bytes: int, entry: ?string, pool: ?string}> $media
     * @param list<array{path: string, bytes: int, reason: string}> $skippedMedia
     */
    private function __construct(
        public readonly int $formatVersion,
        public readonly string $createdAt,
        public readonly string $sourceBackend,
        public readonly string $mediaStorage,
        public readonly array $documents,
        public readonly array $media,
        public readonly array $skippedMedia,
    ) {}

    /**
     * @param list<array{entry: string, key: string, type: string, slug: string, locale: string, sha256: string, bytes: int}> $documents
     * @param list<array{path: string, sha256: string, bytes: int, entry: ?string, pool: ?string}> $media
     * @param list<array{path: string, bytes: int, reason: string}> $skippedMedia
     */
    public static function create(
        string $createdAt,
        string $sourceBackend,
        string $mediaStorage,
        array $documents,
        array $media,
        array $skippedMedia = [],
    ): self {
        if ($mediaStorage !== self::MEDIA_EMBEDDED && $mediaStorage !== self::MEDIA_POOLED) {
            throw new InvalidArgumentException('A backup stores its media either embedded or in the pool.');
        }

        return new self(
            self::FORMAT_VERSION,
            $createdAt,
            $sourceBackend,
            $mediaStorage,
            array_values($documents),
            array_values($media),
            array_values($skippedMedia),
        );
    }

    public function isPooled(): bool
    {
        return $this->mediaStorage === self::MEDIA_POOLED;
    }

    public function documentCount(): int
    {
        return count($this->documents);
    }

    public function mediaCount(): int
    {
        return count($this->media);
    }

    /**
     * Every pool entry this archive cannot be restored without.
     *
     * Retention reads exactly this from every surviving archive to work out what
     * the pool may lose. Getting it from the manifest rather than from a
     * side-table is what makes the answer impossible to get stale: the archive
     * carries its own requirements.
     *
     * @return list<string>
     */
    public function poolReferences(): array
    {
        $refs = [];
        foreach ($this->media as $item) {
            if (is_string($item['pool'] ?? null) && $item['pool'] !== '') {
                $refs[$item['pool']] = true;
            }
        }

        return array_keys($refs);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generator' => self::GENERATOR,
            'formatVersion' => $this->formatVersion,
            'createdAt' => $this->createdAt,
            // Recorded so a restore report can say "this came off SQLite and went
            // onto files", which is the claim the whole feature rests on and the
            // one an operator will want evidence of.
            'sourceBackend' => $this->sourceBackend,
            'mediaStorage' => $this->mediaStorage,
            'counts' => [
                'documents' => count($this->documents),
                'media' => count($this->media),
                'skippedMedia' => count($this->skippedMedia),
            ],
            'documents' => $this->documents,
            'media' => $this->media,
            'skippedMedia' => $this->skippedMedia,
        ];
    }

    /**
     * Rebuild from a manifest read off disk, refusing anything malformed.
     *
     * Strict on purpose, and strict about shape rather than about plausibility:
     * this is the parse step that lets everything downstream assume its inputs.
     * A manifest that has been edited — a digest changed to match doctored bytes,
     * an entry name turned into `../../public/shell.php` — must be rejected here,
     * because after this point the entries are treated as authoritative.
     *
     * @param array<string, mixed> $row
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $row): self
    {
        $version = $row['formatVersion'] ?? null;
        if (!is_int($version)) {
            throw new InvalidArgumentException('The manifest does not say which format it is in.');
        }
        if ($version !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'This archive is in backup format %d; this version of the CMS reads format %d.',
                $version,
                self::FORMAT_VERSION
            ));
        }

        $mediaStorage = $row['mediaStorage'] ?? null;
        if ($mediaStorage !== self::MEDIA_EMBEDDED && $mediaStorage !== self::MEDIA_POOLED) {
            throw new InvalidArgumentException('The manifest does not say where its media is kept.');
        }

        $documents = [];
        foreach (self::listOf($row, 'documents') as $item) {
            $documents[] = self::readDocument($item);
        }

        $media = [];
        foreach (self::listOf($row, 'media') as $item) {
            $media[] = self::readMedia($item, $mediaStorage);
        }

        $skipped = [];
        foreach (self::listOf($row, 'skippedMedia') as $item) {
            $skipped[] = self::readSkipped($item);
        }

        // The counts are redundant with the lists, which is exactly why they are
        // checked: a truncated manifest that lost half its entries would
        // otherwise describe a smaller backup perfectly consistently.
        $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
        self::assertCount($counts, 'documents', count($documents));
        self::assertCount($counts, 'media', count($media));
        self::assertCount($counts, 'skippedMedia', count($skipped));

        return new self(
            $version,
            is_string($row['createdAt'] ?? null) ? $row['createdAt'] : '',
            is_string($row['sourceBackend'] ?? null) ? $row['sourceBackend'] : 'unknown',
            $mediaStorage,
            $documents,
            $media,
            $skipped,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private static function listOf(array $row, string $field): array
    {
        $value = $row[$field] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException("The manifest's \"{$field}\" is not a list.");
        }

        return array_values($value);
    }

    /**
     * @return array{entry: string, key: string, type: string, slug: string, locale: string, sha256: string, bytes: int}
     */
    private static function readDocument(mixed $item): array
    {
        if (!is_array($item)) {
            throw new InvalidArgumentException('The manifest has a document entry that is not a record.');
        }

        $entry = self::requireString($item, 'entry', 'document');
        if (!ArchivePath::isSafe($entry)) {
            throw new InvalidArgumentException("The manifest names an unsafe archive entry: \"{$entry}\".");
        }

        return [
            'entry' => $entry,
            'key' => self::requireString($item, 'key', 'document'),
            'type' => self::requireString($item, 'type', 'document'),
            'slug' => self::requireString($item, 'slug', 'document'),
            'locale' => self::requireString($item, 'locale', 'document'),
            'sha256' => self::requireDigest($item),
            'bytes' => self::requireBytes($item),
        ];
    }

    /**
     * @return array{path: string, sha256: string, bytes: int, entry: ?string, pool: ?string}
     */
    private static function readMedia(mixed $item, string $mediaStorage): array
    {
        if (!is_array($item)) {
            throw new InvalidArgumentException('The manifest has a media entry that is not a record.');
        }

        $path = self::requireString($item, 'path', 'media');
        if (!ArchivePath::isSafe($path)) {
            throw new InvalidArgumentException("The manifest names an unsafe media path: \"{$path}\".");
        }

        $entry = null;
        $pool = null;

        if ($mediaStorage === self::MEDIA_EMBEDDED) {
            $entry = self::requireString($item, 'entry', 'media');
            if (!ArchivePath::isSafe($entry)) {
                throw new InvalidArgumentException("The manifest names an unsafe archive entry: \"{$entry}\".");
            }
        } else {
            $pool = self::requireString($item, 'pool', 'media');
            if (!PoolReference::isValid($pool)) {
                throw new InvalidArgumentException("The manifest names an unusable pool entry: \"{$pool}\".");
            }
        }

        return [
            'path' => $path,
            'sha256' => self::requireDigest($item),
            'bytes' => self::requireBytes($item),
            'entry' => $entry,
            'pool' => $pool,
        ];
    }

    /**
     * @return array{path: string, bytes: int, reason: string}
     */
    private static function readSkipped(mixed $item): array
    {
        if (!is_array($item)) {
            throw new InvalidArgumentException('The manifest has a skipped-media entry that is not a record.');
        }

        return [
            'path' => self::requireString($item, 'path', 'skipped media'),
            'bytes' => self::requireBytes($item),
            'reason' => self::requireString($item, 'reason', 'skipped media'),
        ];
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function requireString(array $item, string $field, string $what): string
    {
        $value = $item[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("A {$what} entry in the manifest has no \"{$field}\".");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function requireDigest(array $item): string
    {
        $value = $item['sha256'] ?? null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('An entry in the manifest has no usable SHA-256.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function requireBytes(array $item): int
    {
        $value = $item['bytes'] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('An entry in the manifest has no usable size.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $counts
     */
    private static function assertCount(array $counts, string $field, int $actual): void
    {
        $stated = $counts[$field] ?? null;
        if ($stated !== null && $stated !== $actual) {
            throw new InvalidArgumentException(sprintf(
                'The manifest claims %s %s but lists %d.',
                is_scalar($stated) ? (string) $stated : '?',
                $field,
                $actual
            ));
        }
    }
}
