<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The backup/export plugin, exercised as itself.
 *
 * A backup is the entire content of the site, unpublished drafts included, so
 * two properties matter more than any convenience: it must be administrator-only
 * (the capability check, not the kernel's coarse guard, is what these pin), and
 * it must archive only what lives under the content root — a backup that
 * followed a link out of the tree would hand out whatever it pointed at.
 */
final class BackupExportTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private object $plugin;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-backup-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data/sessions', 0o775, true);

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        );
        $this->content = new ContentService($storage);

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService($this->content);

        require_once dirname(__DIR__, 3) . '/plugins/backup/bootstrap.php';
        $this->plugin = new \Plugin_backup($manager);

        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    /* --------------------------------------------------------- helpers -- */

    private function removeTree(string $path): void
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
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /**
     * Put a published page on disk, the way a real publish does: a save records
     * a working copy, and only a publish writes it into `content/`.
     */
    private function publishPage(string $slug, array $data): void
    {
        $this->content->save(Content::create(ContentKey::page($slug), $data));
        $storage = (new \ReflectionProperty($this->content, 'storage'))->getValue($this->content);
        $storage->publish(ContentKey::page($slug));
    }

    private function writeMediaFile(string $filename, string $bytes): void
    {
        mkdir($this->base . '/content/media', 0o775, true);
        file_put_contents($this->base . '/content/media/' . $filename, $bytes);
    }

    /** Sign a session in as the given role, the way the browser cookie does. */
    private function signInAs(string $role): void
    {
        $id = bin2hex(random_bytes(32));
        file_put_contents(
            $this->base . '/data/sessions/' . $id . '.json',
            json_encode([
                'user' => ['username' => 'someone', 'role' => $role],
                'lastActivity' => time(),
            ])
        );
        $_COOKIE['click_session'] = $id;
    }

    /** Open a produced archive for reading, failing the test if it will not. */
    private function openArchive(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The archive is not a readable zip.');

        return $zip;
    }

    /** @return list<string> */
    private function entryNames(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        return $names;
    }

    /* --------------------------------------------------- what is inside -- */

    public function testArchiveIncludesAPublishedPageDocument(): void
    {
        $this->publishPage('home', ['title' => 'Welcome home', 'sections' => []]);

        $zip = $this->openArchive($this->plugin->createArchive());
        $names = $this->entryNames($zip);

        $pageEntries = array_filter(
            $names,
            static fn (string $n): bool => str_starts_with($n, 'content/page/') && str_ends_with($n, '/home.json')
        );
        $this->assertNotEmpty($pageEntries, 'The published page document is missing from the archive.');

        $entry = array_values($pageEntries)[0];
        $this->assertStringContainsString('Welcome home', (string) $zip->getFromName($entry));
        $zip->close();
    }

    public function testArchiveIncludesTheUploadedMediaFile(): void
    {
        $this->writeMediaFile('photo.png', "\x89PNG\r\n_fake_image_bytes_");

        $zip = $this->openArchive($this->plugin->createArchive());

        $this->assertContains('content/media/photo.png', $this->entryNames($zip));
        $this->assertSame(
            "\x89PNG\r\n_fake_image_bytes_",
            (string) $zip->getFromName('content/media/photo.png')
        );
        $zip->close();
    }

    public function testArchiveIsAValidReadableZipWithAManifest(): void
    {
        $this->publishPage('home', ['title' => 'Home', 'sections' => []]);

        $zip = $this->openArchive($this->plugin->createArchive());

        $this->assertGreaterThan(0, $zip->numFiles);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame('click-cms', $manifest['generator'] ?? null);
        $this->assertGreaterThanOrEqual(1, $manifest['fileCount'] ?? 0);
        $zip->close();
    }

    /* ------------------------------------------------------ path safety -- */

    public function testArchiveExcludesFilesOutsideTheContentRoot(): void
    {
        $this->publishPage('home', ['title' => 'Home', 'sections' => []]);
        // Secrets that live beside content but not under it: session files,
        // version drafts, an installer's leftover. None of these belong in a
        // content backup, and the directory walk is rooted so none can appear.
        file_put_contents($this->base . '/secret-outside.txt', 'do-not-export-me');
        $this->signInAs('admin');

        $zip = $this->openArchive($this->plugin->createArchive());
        $names = $this->entryNames($zip);

        foreach ($names as $name) {
            $this->assertStringStartsNotWith('..', $name);
            $this->assertStringNotContainsString('secret-outside', $name);
        }

        // And no entry carries the secret's bytes either.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('do-not-export-me', (string) $zip->getFromIndex($i));
        }
        $zip->close();
    }

    public function testArchiveDoesNotFollowASymlinkOutOfTheContentRoot(): void
    {
        $outside = $this->base . '/outside-secret.txt';
        file_put_contents($outside, 'symlinked-secret');

        // A symlink sitting inside content/ that points at a file outside it.
        // Following it would copy the target into the backup — exactly the
        // exfiltration the real-path guard exists to stop.
        $link = $this->base . '/content/link-to-secret.json';
        if (!@symlink($outside, $link)) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }

        $zip = $this->openArchive($this->plugin->createArchive());

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('symlinked-secret', (string) $zip->getFromIndex($i));
        }
        $zip->close();
    }

    /* ------------------------------------------------- administrator-only -- */

    public function testAnUnauthenticatedCallerIsRefused(): void
    {
        // No session cookie at all.
        $result = $this->plugin->handleBackup();

        $this->assertSame(401, $result['status']);
        $this->assertArrayNotHasKey('raw', $result);
    }

    public function testAnInsufficientRoleIsRefused(): void
    {
        // An editor has full editorial control but not the settings capability,
        // and a backup contains every draft on the site — not an editor's call.
        $this->signInAs('editor');

        $result = $this->plugin->handleBackup();

        $this->assertSame(403, $result['status']);
        $this->assertArrayNotHasKey('raw', $result);
    }

    public function testAnAdministratorReceivesAStreamedZip(): void
    {
        $this->publishPage('home', ['title' => 'Home', 'sections' => []]);
        $this->signInAs('admin');

        ob_start();
        $result = $this->plugin->handleBackup();
        $body = (string) ob_get_clean();

        $this->assertTrue($result['raw'] ?? false, 'An administrator download must be a raw response.');
        // The streamed body is the zip's bytes, which begin with the local file
        // header signature "PK\x03\x04".
        $this->assertStringStartsWith("PK\x03\x04", $body);

        // And it opens back up as a real archive holding the page.
        $tmp = tempnam(sys_get_temp_dir(), 'backup-assert');
        file_put_contents($tmp, $body);
        $zip = $this->openArchive($tmp);
        $this->assertGreaterThan(0, $zip->numFiles);
        $zip->close();
        @unlink($tmp);
    }
}
