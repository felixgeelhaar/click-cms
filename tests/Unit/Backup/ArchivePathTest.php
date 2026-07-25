<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Domain\Backup\ArchivePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Zip Slip guard.
 *
 * This repository has shipped a live Zip Slip remote-code-execution before, in
 * the marketplace installer, and a restore is the same shape of hazard wearing
 * friendlier clothes: an archive an administrator was emailed, extracted into a
 * live installation. `../../public/index.php` inside it is a shell.
 *
 * The names below are the standard ways that attack is written, and each one is
 * a name that must never become a path.
 */
final class ArchivePathTest extends TestCase
{
    public function testAnOrdinaryNestedNameIsAccepted(): void
    {
        $this->assertTrue(ArchivePath::isSafe('content/page/en/home.json'));
        $this->assertTrue(ArchivePath::isSafe('photo-a1b2c3d4.png'));
        $this->assertTrue(ArchivePath::isSafe('a/b/c/d/e.txt'));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function unsafeNames(): array
    {
        return [
            ['traversal', '../../public/index.php'],
            ['traversal in the middle', 'content/../../public/index.php'],
            ['a bare dot-dot', '..'],
            ['a single dot segment', 'content/./home.json'],
            ['an absolute posix path', '/etc/cron.d/backdoor'],
            ['a windows drive', 'C:/windows/system32/x.dll'],
            ['a windows UNC-ish name', '\\\\server\\share\\x'],
            ['a backslash separator', 'content\\..\\..\\index.php'],
            ['a NUL truncation', "safe.json\0../../evil.php"],
            ['an empty name', ''],
            ['a doubled separator', 'content//home.json'],
            ['a trailing separator (a directory entry)', 'content/'],
            ['a leading separator', '/content/home.json'],
        ];
    }

    #[DataProvider('unsafeNames')]
    public function testAnUnsafeNameIsRefused(string $why, string $name): void
    {
        $this->assertFalse(ArchivePath::isSafe($name), "An entry named by {$why} must be refused.");
    }

    #[DataProvider('unsafeNames')]
    public function testAnUnsafeNameNeverResolvesToAPath(string $why, string $name): void
    {
        $this->assertNull(
            ArchivePath::resolve('/var/www/content/media', $name),
            "An entry named by {$why} must not produce a path at all."
        );
    }

    public function testResolveJoinsASafeNameOntoTheDestination(): void
    {
        $this->assertSame(
            '/var/www/content/media/photo.png',
            ArchivePath::resolve('/var/www/content/media', 'photo.png')
        );
    }

    /** A trailing slash on the destination must not produce a doubled separator. */
    public function testResolveNormalisesATrailingSeparatorOnTheDestination(): void
    {
        $this->assertSame(
            '/var/www/media/photo.png',
            ArchivePath::resolve('/var/www/media/', 'photo.png')
        );
    }

    /**
     * A name that merely *starts* with the same characters as a sibling
     * directory is not traversal, and refusing it would be a false positive that
     * loses a legitimate file.
     */
    public function testANameResemblingASiblingDirectoryIsStillAccepted(): void
    {
        $this->assertSame(
            '/var/www/media/media-evil.png',
            ArchivePath::resolve('/var/www/media', 'media-evil.png')
        );
    }
}
