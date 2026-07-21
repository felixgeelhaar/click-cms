<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use InvalidArgumentException;
use RuntimeException;

/**
 * Flat-file content storage: one JSON document per item, grouped by type.
 *
 *   {contentDir}/{type}/{slug}.json
 *
 * Chosen as the default because it needs no database and therefore runs on
 * plain PHP shared hosting, which is where small sites actually live.
 *
 * Writes go through a temporary file and an atomic rename, so a crash or a
 * concurrent read can never observe a half-written document — the failure mode
 * that silently corrupts flat-file CMSs.
 */
final class JsonStorage implements StorageInterface
{
    /** Slugs and types become path segments, so they must not be able to escape. */
    private const SAFE_SEGMENT = '/^[A-Za-z0-9._-]+$/';

    public function __construct(private readonly string $contentDir) {}

    public function find(ContentKey $key): ?Content
    {
        // A key that cannot name a file is simply a miss. Reads are reached
        // straight from URLs, so throwing here would turn every request for
        // `/some/path` into a 500 instead of a 404.
        if (!$this->isSafeKey($key)) {
            return null;
        }

        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $row = json_decode($raw, true);
        if (!is_array($row)) {
            // A corrupt file should not take down a listing; treat it as absent.
            return null;
        }

        $row['key'] ??= $key->toString();

        return Content::fromArray($row);
    }

    public function findByType(string $type): array
    {
        if (!$this->isSafeSegment($type)) {
            return [];
        }

        $dir = $this->contentDir . '/' . $type;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        // glob() order is filesystem-dependent; sort so listings are stable.
        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $file) {
            $slug = basename($file, '.json');
            $content = $this->find(ContentKey::fromString($type . ':' . $slug));
            if ($content !== null) {
                $out[] = $content;
            }
        }

        return $out;
    }

    public function save(Content $content): void
    {
        $path = $this->pathFor($content->key);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create content directory: {$dir}");
        }

        $json = json_encode(
            $content->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        // Write-then-rename: rename() is atomic within a filesystem, so readers
        // see either the old document or the new one, never a partial write.
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write content file: {$path}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to commit content file: {$path}");
        }
    }

    public function delete(ContentKey $key): bool
    {
        if (!$this->isSafeKey($key)) {
            return false;
        }

        $path = $this->pathFor($key);

        return is_file($path) && @unlink($path);
    }

    public function exists(ContentKey $key): bool
    {
        return $this->isSafeKey($key) && is_file($this->pathFor($key));
    }

    private function pathFor(ContentKey $key): string
    {
        $this->assertSafeSegment($key->type, 'type');
        $this->assertSafeSegment($key->slug, 'slug');

        return $this->contentDir . '/' . $key->type . '/' . $key->slug . '.json';
    }

    private function isSafeKey(ContentKey $key): bool
    {
        return $this->isSafeSegment($key->type) && $this->isSafeSegment($key->slug);
    }

    private function isSafeSegment(string $segment): bool
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }

        return preg_match(self::SAFE_SEGMENT, $segment) === 1;
    }

    /**
     * Reject anything that could traverse out of the content directory.
     *
     * Used on the write path only: storing under an impossible key is a bug or
     * an attack and must be loud, whereas reading one is merely a miss.
     */
    private function assertSafeSegment(string $segment, string $label): void
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new InvalidArgumentException("Content {$label} is not a valid path segment.");
        }

        if (preg_match(self::SAFE_SEGMENT, $segment) !== 1) {
            throw new InvalidArgumentException(
                "Content {$label} may only contain letters, digits, dot, dash and underscore."
            );
        }
    }
}
