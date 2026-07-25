<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\SqliteStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * A throwaway installation to back up and restore, plus the plumbing every
 * backup test needs.
 *
 * Sites are built here on both a flat-file and a SQLite backend, because the one
 * property the whole feature exists for — an archive taken from one backend
 * restoring onto another — cannot be asserted with only one of them.
 */
abstract class BackupTestCase extends TestCase
{
    protected string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-backup-test-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content/media', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    /* ----------------------------------------------------------------- sites -- */

    protected function jsonStorage(string $suffix = ''): JsonStorage
    {
        $dir = $this->base . '/content' . $suffix;
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return new JsonStorage($dir);
    }

    protected function sqliteStorage(string $suffix = ''): SqliteStorage
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }

        return new SqliteStorage($this->base . '/data/content' . $suffix . '.sqlite');
    }

    /** Storage with the version chain and draft/publish behaviour a real site has. */
    protected function versioning(StorageInterface $inner, string $suffix = ''): VersioningStorage
    {
        return new VersioningStorage($inner, new JsonVersionStore($this->base . '/data/versions' . $suffix));
    }

    protected function contentService(StorageInterface $storage): ContentService
    {
        return new ContentService($storage);
    }

    /**
     * Put a published page into a site, the way a real publish does: a save
     * records a working copy, and only a publish writes it live.
     */
    protected function publishPage(VersioningStorage $storage, string $slug, array $data = []): void
    {
        $storage->save(Content::create(ContentKey::page($slug), $data + ['title' => ucfirst($slug)]));
        $storage->publish(ContentKey::page($slug));
    }

    /* ----------------------------------------------------------------- media -- */

    protected function mediaDir(): string
    {
        return $this->base . '/content/media';
    }

    protected function writeMedia(string $name, string $bytes, ?string $dir = null): string
    {
        $dir ??= $this->mediaDir();
        $path = $dir . '/' . $name;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    /* ------------------------------------------------------------------ zip -- */

    protected function openArchive(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, "The archive at {$path} is not a readable zip.");

        return $zip;
    }

    /** @return list<string> */
    protected function entryNames(string $path): array
    {
        $zip = $this->openArchive($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        return $names;
    }

    /** @return array<string, mixed> */
    protected function manifestOf(string $path): array
    {
        $zip = $this->openArchive($path);
        $raw = (string) $zip->getFromName('manifest.json');
        $zip->close();

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'The archive has no readable manifest.');

        return $decoded;
    }

    /**
     * Rewrite one entry of an archive in place — how a truncated download, bit
     * rot, or a deliberately altered page is simulated.
     */
    protected function replaceEntry(string $path, string $entry, string $bytes): void
    {
        $zip = $this->openArchive($path);
        $this->assertTrue($zip->addFromString($entry, $bytes));
        $zip->close();
    }

    protected function rewriteManifest(string $path, callable $mutate): void
    {
        $manifest = $this->manifestOf($path);
        $manifest = $mutate($manifest);

        $this->replaceEntry($path, 'manifest.json', (string) json_encode($manifest));
    }

    /* ------------------------------------------------------------- utilities -- */

    protected function removeTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
