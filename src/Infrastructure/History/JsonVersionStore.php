<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\History;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\History\VersionStoreInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Flat-file version history: one JSON file per retained version.
 *
 *   {versionsDir}/{key path}/{version id}.json
 *
 * Versions live outside the content directory entirely — under `data/`, beside
 * sessions — rather than beside the documents they belong to. Two reasons, both
 * about not serving history as content. `JsonStorage::findByType()` globs
 * `{contentDir}/{type}/*.json`, so anything under the content root is one
 * plausible directory name away from being listed as a page; and the content
 * root is what a misconfigured document root is most likely to expose. History
 * contains unpublished drafts, so it must not be reachable by either accident.
 *
 * The directory for a document is derived from the key's own string form, one
 * directory per colon-separated part, rather than from `type` and `slug`
 * directly. When a key gains a dimension — a locale, say — it becomes a
 * directory level here without this file changing.
 *
 * Writes go through a temporary file and an atomic rename for the same reason
 * the content store does: a half-written version is worse than no version,
 * because it looks like a recovery point and is not one.
 */
final class JsonVersionStore implements VersionStoreInterface
{
    /** Key parts become path segments, so they must not be able to escape. */
    private const SAFE_SEGMENT = '/^[A-Za-z0-9._-]+$/';

    /** Two writes in the same microsecond are vanishingly rare but not impossible. */
    private const MINT_ATTEMPTS = 5;

    private readonly RetentionPolicy $retention;

    public function __construct(
        private readonly string $versionsDir,
        ?RetentionPolicy $retention = null,
    ) {
        $this->retention = $retention ?? RetentionPolicy::default();
    }

    public function record(
        Content $content,
        ?string $author = null,
        string $reason = Version::REASON_SAVE,
    ): Version {
        $dir = $this->dirFor($content->key);

        if ($dir === null) {
            // Loud, like the content store's write path: retaining a version
            // under an impossible key is a bug or an attack, never a miss.
            throw new InvalidArgumentException(
                'Content key parts may only contain letters, digits, dot, dash and underscore.'
            );
        }

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create version directory: {$dir}");
        }

        $version = $this->mint($dir, $content, $author, $reason);

        $this->write($dir . '/' . $version->id . '.json', $version);
        $this->prune($content->key);

        return $version;
    }

    public function all(ContentKey $key): array
    {
        $dir = $this->dirFor($key);

        if ($dir === null) {
            return [];
        }

        $out = [];
        foreach ($this->idsFor($key, newestFirst: true) as $id) {
            $version = $this->read($dir . '/' . $id . '.json');
            if ($version !== null) {
                $out[] = $version;
            }
        }

        return $out;
    }

    /**
     * Retained identifiers for a document, in the order asked for.
     *
     * Identifiers are fixed-width timestamps, so a string sort is a
     * chronological one — which is what lets listing, retention and "the newest
     * one" all be answered without opening a single file.
     *
     * @return list<string>
     */
    private function idsFor(ContentKey $key, bool $newestFirst): array
    {
        $dir = $this->dirFor($key);

        if ($dir === null || !is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        $newestFirst ? rsort($files, SORT_STRING) : sort($files, SORT_STRING);

        return array_map(
            static fn (string $file): string => basename($file, '.json'),
            $files
        );
    }

    public function newest(ContentKey $key): ?Version
    {
        // One directory listing and, in the ordinary case, one file read —
        // rather than reading the whole chain to look at one end of it.
        foreach ($this->idsFor($key, newestFirst: true) as $id) {
            $version = $this->read($this->dirFor($key) . '/' . $id . '.json');

            // A damaged newest version falls through to the one behind it, for
            // the same reason `all()` skips unreadable entries: nineteen
            // recoverable states beat an error because the twentieth is broken.
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    public function lastPublished(ContentKey $key): ?Version
    {
        $dir = $this->dirFor($key);

        foreach ($this->idsFor($key, newestFirst: true) as $id) {
            $version = $this->read($dir . '/' . $id . '.json');

            if ($version !== null && $version->reason === Version::REASON_PUBLISH) {
                return $version;
            }
        }

        return null;
    }

    public function keysOfType(string $type, ?Locale $locale = null): array
    {
        if (!$this->isSafeSegment($type)) {
            return [];
        }

        // The layout mirrors the key's own string form — {type}/{locale}/{slug}
        // — so listing a type is a glob rather than an index that could drift
        // out of step with what is on disk.
        $pattern = $this->versionsDir . '/' . $type . '/'
            . ($locale === null ? '*' : $locale->code) . '/*';

        $keys = [];
        foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            $code = basename(dirname($dir));

            $parsed = Locale::tryFromString($code);
            if ($parsed === null) {
                // A directory that is not a language tag was not written by
                // this store. Skipping it beats inventing a key for it.
                continue;
            }

            $keys[] = ContentKey::fromString($type . ':' . $parsed->code . ':' . $slug);
        }

        return $keys;
    }

    public function find(ContentKey $key, string $versionId): ?Version
    {
        // The identifier arrives from a URL and is about to name a file.
        // Anything that is not one this store could have written is a miss.
        if (!Version::isValidId($versionId)) {
            return null;
        }

        $dir = $this->dirFor($key);
        if ($dir === null) {
            return null;
        }

        return $this->read($dir . '/' . $versionId . '.json');
    }

    /**
     * Reserve an unused identifier for this write.
     */
    private function mint(string $dir, Content $content, ?string $author, string $reason): Version
    {
        for ($attempt = 0; $attempt < self::MINT_ATTEMPTS; $attempt++) {
            $at = new DateTimeImmutable('now');
            $id = Version::mintId($at, bin2hex(random_bytes(3)));

            if (!is_file($dir . '/' . $id . '.json')) {
                return Version::of($id, $content, $at, $author, $reason);
            }
        }

        // Refusing beats overwriting: the file already there is somebody's
        // recovery point.
        throw new RuntimeException('Unable to allocate a version identifier.');
    }

    private function write(string $path, Version $version): void
    {
        $json = json_encode(
            $version->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write version file: {$path}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to commit version file: {$path}");
        }
    }

    private function read(string $path): ?Version
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $row = json_decode($raw, true);
        if (!is_array($row)) {
            return null;
        }

        try {
            return Version::fromArray($row);
        } catch (\Exception) {
            // One unreadable version must not hide the others; a history that
            // shows nineteen recoverable states is far better than one that
            // shows an error because the twentieth is damaged.
            return null;
        }
    }

    private function prune(ContentKey $key): void
    {
        $ids = $this->idsFor($key, newestFirst: false);

        // Under the limit there is nothing to discard, and working out what is
        // exempt would mean opening files to no purpose — which is every save
        // on a document with a short history, so it is worth the early exit.
        if (count($ids) <= $this->retention->limit) {
            return;
        }

        // The working copy is the last entry, the chain being oldest-first.
        $spare = [$ids[count($ids) - 1]];

        $published = $this->lastPublished($key);
        if ($published !== null) {
            $spare[] = $published->id;
        }

        $dir = $this->dirFor($key);

        foreach ($this->retention->expired($ids, $spare) as $id) {
            @unlink($dir . '/' . $id . '.json');
        }
    }

    private function dirFor(ContentKey $key): ?string
    {
        $segments = explode(':', $key->toString());

        foreach ($segments as $segment) {
            if (!$this->isSafeSegment($segment)) {
                return null;
            }
        }

        return $this->versionsDir . '/' . implode('/', $segments);
    }

    private function isSafeSegment(string $segment): bool
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }

        return preg_match(self::SAFE_SEGMENT, $segment) === 1;
    }
}
