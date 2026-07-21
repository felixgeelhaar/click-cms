<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\SqliteStorage;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * The shared contract, plus what is true only of a database file.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteStorageTest extends StorageContractTestCase
{
    private string $dir;
    private string $dbPath;

    protected function createStorage(): StorageInterface
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-sqlite-' . bin2hex(random_bytes(6));
        $this->dbPath = $this->dir . '/content.sqlite';

        return new SqliteStorage($this->dbPath);
    }

    protected function corruptStoredItem(ContentKey $key): void
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->prepare('UPDATE content SET payload = :p WHERE type = :t AND slug = :s');
        $stmt->execute(['p' => '{ this is not json', 't' => $key->type, 's' => $key->slug]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /**
     * The database and its directory are created on first use, not on
     * construction — a backend that is configured but never touched must not
     * leave a file behind, and construction happens during every boot.
     */
    public function testTheDatabaseIsCreatedLazilyOnFirstUse(): void
    {
        $this->assertFileDoesNotExist($this->dbPath);

        $this->storage->save(Content::create(ContentKey::page('home')));

        $this->assertFileExists($this->dbPath);
    }

    public function testASecondInstanceSeesWhatTheFirstWrote(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        $reopened = new SqliteStorage($this->dbPath);

        $this->assertSame('Home', $reopened->find(ContentKey::page('home'))?->title());
    }

    /** Opening an existing database must not fail on the CREATE TABLE it re-runs. */
    public function testOpeningAnExistingDatabaseIsIdempotent(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));

        $reopened = new SqliteStorage($this->dbPath);
        $reopened->save(Content::create(ContentKey::page('other')));

        $this->assertCount(2, $reopened->findByType('page'));
    }

    /**
     * Slugs differing only in case are distinct here, where on a
     * case-insensitive filesystem the flat-file backend collapses them. See the
     * note in {@see StorageContractTestCase} — this is asserted for SQLite only
     * because only SQLite can guarantee it.
     */
    public function testKeysAreCaseSensitive(): void
    {
        $this->storage->save(Content::create(ContentKey::page('Home'), ['title' => 'Upper']));
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Lower']));

        $this->assertSame('Upper', $this->storage->find(ContentKey::page('Home'))?->title());
        $this->assertSame('Lower', $this->storage->find(ContentKey::page('home'))?->title());
        $this->assertCount(2, $this->storage->findByType('page'));
    }

    /**
     * `updated_at` is a real column so that a future query can order by it
     * without decoding every payload. It has to actually track the document.
     */
    public function testUpdatedAtIsStoredAsAColumn(): void
    {
        $content = Content::create(
            ContentKey::page('home'),
            ['updatedAt' => '2021-06-07T08:09:10+00:00']
        );
        $this->storage->save($content);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $stored = $pdo->query("SELECT updated_at FROM content WHERE slug = 'home'")->fetchColumn();

        $this->assertSame('2021-06-07T08:09:10+00:00', $stored);
    }
}
