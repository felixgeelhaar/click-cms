<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\SqliteStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The backup plugin's HTTP surface: listing what exists, taking one on demand,
 * and the gate in front of both.
 *
 * The export itself is covered in `tests/Unit/Backup`. What is asserted here is
 * what only this layer can get wrong: that a signed-in editor cannot reach any
 * of it, and — the reason this plugin was rewritten — that the archive it
 * produces contains the site's documents on a *database* backend, which the old
 * directory walk did not.
 */
final class BackupTest extends TestCase
{
    private string $base;
    private object $plugin;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-backup-plugin-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content/media', 0o775, true);
        mkdir($this->base . '/data/sessions', 0o775, true);
        mkdir($this->base . '/config', 0o775, true);

        require_once dirname(__DIR__, 3) . '/plugins/backup/bootstrap.php';

        $_COOKIE = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_GET = [];
        $this->removeTree($this->base);
    }

    /* ------------------------------------------------------------- helpers -- */

    /**
     * Wire the plugin against an installation configured for a given backend.
     * The plugin reads `config/core.json` itself, exactly as it does in
     * production, which is what makes the SQLite case below meaningful.
     */
    private function install(string $backend): void
    {
        file_put_contents(
            $this->base . '/config/core.json',
            (string) json_encode(['core' => [
                'storage' => ['backend' => $backend, 'sqlite' => ['path' => 'data/content.sqlite']],
                'backup' => ['enabled' => true, 'keep' => 7],
            ]])
        );

        $storage = new VersioningStorage(
            $this->contentBackend($backend),
            new JsonVersionStore($this->base . '/data/versions'),
        );

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService(new ContentService($storage));

        $this->plugin = new \Plugin_backup($manager);
    }

    private function contentBackend(string $backend): JsonStorage|SqliteStorage
    {
        if ($backend === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                $this->markTestSkipped('pdo_sqlite is not available.');
            }

            return new SqliteStorage($this->base . '/data/content.sqlite');
        }

        return new JsonStorage($this->base . '/content');
    }

    /** Write a live document straight into the configured backend. */
    private function storeDocument(string $backend, ContentKey $key, array $data): void
    {
        $this->contentBackend($backend)->save(Content::create($key, $data));
    }

    private function signInAs(string $role): void
    {
        $id = bin2hex(random_bytes(32));
        file_put_contents(
            $this->base . '/data/sessions/' . $id . '.json',
            (string) json_encode([
                'user' => ['username' => 'someone', 'role' => $role],
                'lastActivity' => time(),
            ])
        );
        $_COOKIE['click_session'] = $id;
    }

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
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /* ------------------------------------------------------------- the bug -- */

    /**
     * The bug this plugin was rewritten for, stated as one test.
     *
     * On SQLite the documents are in the database and `content/` holds only the
     * media, so the old directory walk produced an archive with the pictures in
     * it, no pages, and a manifest reporting success.
     */
    public function testASqliteSitesDocumentsAreInTheDownloadedArchive(): void
    {
        $this->install('sqlite');
        $this->storeDocument('sqlite', ContentKey::page('home'), ['title' => 'Welcome home']);
        file_put_contents($this->base . '/content/media/photo.png', 'a picture');

        $path = $this->plugin->createArchive();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertStringContainsString(
            'Welcome home',
            (string) $zip->getFromName('content/page/en/home.json'),
            'A SQLite site must have its documents in the archive.'
        );
        $this->assertSame('a picture', (string) $zip->getFromName('content/media/photo.png'));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame('sqlite', $manifest['sourceBackend']);
        $this->assertSame(1, $manifest['counts']['documents']);
        $zip->close();
        @unlink($path);
    }

    /* ------------------------------------------------------------- routing -- */

    public function testTheRoutesAreListingCreatingAndDownloading(): void
    {
        $this->install('json');

        $this->assertSame(
            ['GET /api/backup', 'GET /api/backups', 'POST /api/backups'],
            array_keys($this->plugin->hook_api_routes([]))
        );
    }

    /* --------------------------------------------------------- listing them -- */

    public function testAnAdministratorSeesTheRetainedBackupsAndTheSettings(): void
    {
        $this->install('json');
        $this->storeDocument('json', ContentKey::page('home'), ['title' => 'Home']);
        $this->signInAs('admin');

        $this->plugin->handleCreate();
        $result = $this->plugin->handleList();

        $this->assertTrue($result['data']['enabled']);
        $this->assertSame(7, $result['data']['keep']);
        $this->assertCount(1, $result['data']['backups']);
        $this->assertSame(1, $result['data']['backups'][0]['documents']);
    }

    public function testListingIsEmptyBeforeAnyBackupHasBeenTaken(): void
    {
        $this->install('json');
        $this->signInAs('admin');

        $this->assertSame([], $this->plugin->handleList()['data']['backups']);
    }

    /* --------------------------------------------------------- taking one -- */

    public function testAnAdministratorCanTakeABackupOnDemand(): void
    {
        $this->install('json');
        $this->storeDocument('json', ContentKey::page('home'), ['title' => 'Home']);
        file_put_contents($this->base . '/content/media/photo.png', 'a picture');
        $this->signInAs('admin');

        $result = $this->plugin->handleCreate();

        $this->assertSame(1, $result['data']['documents']);
        $this->assertSame(1, $result['data']['media']);
        $this->assertSame('json', $result['data']['sourceBackend']);
        $this->assertFileExists($this->base . '/data/backups/' . $result['data']['name']);
    }

    /**
     * A retained backup lives under `data/`, which is not web-served. Anywhere
     * reachable would turn this feature into a way to download every draft and
     * every password hash without signing in.
     */
    public function testRetainedArchivesAreWrittenUnderDataAndNotUnderContent(): void
    {
        $this->install('json');
        $this->signInAs('admin');

        $this->plugin->handleCreate();

        $this->assertDirectoryExists($this->base . '/data/backups');
        $this->assertFileDoesNotExist($this->base . '/content/backups');
    }

    /** Media a backup deliberately left out is reported to the person who asked. */
    public function testMediaLeftOutIsReportedInTheResponse(): void
    {
        file_put_contents(
            $this->base . '/config/core.json',
            (string) json_encode(['core' => [
                'storage' => ['backend' => 'json'],
                'backup' => ['enabled' => true, 'maxMediaBytes' => 10],
            ]])
        );
        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService(new ContentService(new JsonStorage($this->base . '/content')));
        $this->plugin = new \Plugin_backup($manager);

        file_put_contents($this->base . '/content/media/huge.mp4', str_repeat('v', 500));
        $this->signInAs('admin');

        $result = $this->plugin->handleCreate();

        $this->assertSame(0, $result['data']['media']);
        $this->assertCount(1, $result['data']['skippedMedia']);
        $this->assertSame('huge.mp4', $result['data']['skippedMedia'][0]['path']);
    }

    /* ---------------------------------------------------- administrator-only -- */

    /**
     * @return list<array{0: string}>
     */
    public static function everyHandler(): array
    {
        return [['handleBackup'], ['handleList'], ['handleCreate']];
    }

    #[DataProvider('everyHandler')]
    public function testAnUnauthenticatedCallerIsRefused(string $handler): void
    {
        $this->install('json');

        $result = $this->plugin->{$handler}();

        $this->assertSame(401, $result['status']);
        $this->assertArrayNotHasKey('raw', $result);
    }

    /**
     * An editor has full editorial control but not the settings capability, and
     * a backup contains every draft on the site and every password hash — not an
     * editor's call.
     */
    #[DataProvider('everyHandler')]
    public function testAnEditorIsRefused(string $handler): void
    {
        $this->install('json');
        $this->signInAs('editor');

        $result = $this->plugin->{$handler}();

        $this->assertSame(403, $result['status']);
        $this->assertArrayNotHasKey('raw', $result);
    }

    /** And a refused caller must not have caused a backup to be taken. */
    public function testARefusedRequestWritesNothing(): void
    {
        $this->install('json');
        $this->signInAs('editor');

        $this->plugin->handleCreate();

        $this->assertDirectoryDoesNotExist($this->base . '/data/backups');
    }

    /* ---------------------------------------------- downloading a retained one -- */

    public function testARetainedArchiveCanBeDownloadedAsASelfContainedCopy(): void
    {
        $this->install('json');
        $this->storeDocument('json', ContentKey::page('home'), ['title' => 'Home']);
        file_put_contents($this->base . '/content/media/photo.png', 'a picture');
        $this->signInAs('admin');

        $name = $this->plugin->handleCreate()['data']['name'];
        $path = $this->plugin->createPortableCopy($name);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame('embedded', $manifest['mediaStorage']);
        $this->assertSame('a picture', (string) $zip->getFromName('content/media/photo.png'));
        $zip->close();
        @unlink($path);
    }

    /**
     * The archive name arrives in a query string, so it must not be able to name
     * anything but an archive — and a refusal must be an error rather than a
     * file.
     */
    public function testAskingForAnArchiveThatIsNotOneIsRefused(): void
    {
        $this->install('json');
        $this->signInAs('admin');
        $_GET['archive'] = '../../config/core.json';

        $result = $this->plugin->handleBackup();

        $this->assertSame(400, $result['status']);
        $this->assertArrayNotHasKey('raw', $result);
    }
}
