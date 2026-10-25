<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Translation;

use Click\Cms\Domain\Translation\TranslationReviewStore;
use RuntimeException;

/** Stores review metadata under site data, outside content and delivery APIs. */
final class JsonTranslationReviewStore implements TranslationReviewStore
{
    public function __construct(private readonly string $root) {}

    public function find(string $slug, string $sourceLocale, string $targetLocale): ?array
    {
        try {
            $this->assertStoreBoundary();
        } catch (RuntimeException) {
            return null;
        }
        $path = $this->pathFor($slug, $sourceLocale, $targetLocale);
        if (is_link($path) || !is_file($path)) {
            return null;
        }

        return $this->readRecord($path, $slug, $sourceLocale, $targetLocale);
    }

    public function saveIfUnchanged(array $record, ?string $expectedReviewedAt): bool
    {
        foreach (['slug', 'sourceLocale', 'targetLocale', 'sourceRevision', 'targetRevision', 'reviewedAt', 'reviewedBy'] as $field) {
            if (!is_string($record[$field] ?? null) || $record[$field] === '') {
                throw new RuntimeException('Translation review metadata is incomplete.');
            }
        }

        $parent = dirname($this->root);
        $this->assertStoreBoundary();
        if (!is_dir($parent)) {
            throw new RuntimeException('Translation review data parent does not exist.');
        }
        $dir = $this->root;
        if (!is_dir($dir) && !@mkdir($dir, 0o700, false) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create translation review directory: {$dir}");
        }
        $this->assertPathHasNoSymlinks($dir);
        if (!@chmod($dir, 0o700) || (((fileperms($dir) ?: 0) & 0o077) !== 0)) {
            throw new RuntimeException('Translation review directory permissions are not private.');
        }

        $path = $this->pathFor($record['slug'], $record['sourceLocale'], $record['targetLocale']);
        $lockPath = $path . '.lock';
        if (is_link($lockPath) || is_link($path)) {
            throw new RuntimeException('Translation review metadata path cannot be a symbolic link.');
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Unable to lock translation review metadata.');
        }

        try {
            if (!@chmod($lockPath, 0o600) || (((fileperms($lockPath) ?: 0) & 0o077) !== 0)) {
                throw new RuntimeException('Translation review lock permissions are not private.');
            }
            $stat = fstat($lock);
            $pathStat = lstat($lockPath);
            if (!is_array($stat) || !is_array($pathStat) || $stat['ino'] !== $pathStat['ino'] || $stat['dev'] !== $pathStat['dev']) {
                throw new RuntimeException('Translation review lock changed while opening.');
            }
            $current = is_link($path) ? null : $this->readRecord($path, $record['slug'], $record['sourceLocale'], $record['targetLocale']);
            $currentReviewedAt = is_string($current['reviewedAt'] ?? null) ? $current['reviewedAt'] : null;
            if ($currentReviewedAt !== $expectedReviewedAt) {
                return false;
            }

            $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write translation review metadata.');
            }
            if (!@chmod($tmp, 0o600) || !@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Unable to commit translation review metadata.');
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function pathFor(string $slug, string $sourceLocale, string $targetLocale): string
    {
        $identity = json_encode([$slug, $sourceLocale, $targetLocale], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $this->root . '/' . hash('sha256', $identity) . '.json';
    }

    /** @return array<string, mixed>|null */
    private function readRecord(string $path, string $slug, string $sourceLocale, string $targetLocale): ?array
    {
        if (is_link($path) || !is_file($path)) return null;
        $json = @file_get_contents($path);
        if (!is_string($json)) return null;
        $record = json_decode($json, true);
        if (!is_array($record)
            || ($record['slug'] ?? null) !== $slug
            || ($record['sourceLocale'] ?? null) !== $sourceLocale
            || ($record['targetLocale'] ?? null) !== $targetLocale
            || !is_string($record['sourceRevision'] ?? null)
            || !is_string($record['targetRevision'] ?? null)
            || !is_string($record['reviewedAt'] ?? null)
            || !is_string($record['reviewedBy'] ?? null)
        ) return null;
        return $record;
    }

    private function assertPathHasNoSymlinks(string $path): void
    {
        if (str_contains($path, DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR)
            || str_ends_with($path, DIRECTORY_SEPARATOR . '..')
            || is_link($path)) {
            throw new RuntimeException('Translation review store cannot use a symbolic-link or parent-traversal path.');
        }
    }

    private function assertStoreBoundary(): void
    {
        $parent = dirname($this->root);
        $this->assertPathHasNoSymlinks($parent);
        $this->assertPathHasNoSymlinks($this->root);
    }
}
