<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\StorageMigrator;
use PHPUnit\Framework\TestCase;

/**
 * The mover's contract: every live document of every named type and language
 * reaches the target, the tally reports what it did, and a second run neither
 * duplicates nor corrupts what the first already moved.
 */
final class StorageMigratorTest extends TestCase
{
    private string $root;
    private JsonStorage $from;
    private JsonStorage $to;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-migrator-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/from', 0o775, true);
        mkdir($this->root . '/to', 0o775, true);

        $this->from = new JsonStorage($this->root . '/from');
        $this->to = new JsonStorage($this->root . '/to');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function seedSource(): void
    {
        // Two languages of one page, plus another type, so the test exercises
        // both "all locales" and "all types".
        $this->from->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
        $this->from->save(Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']));
        $this->from->save(Content::create(ContentKey::page('about'), ['title' => 'About']));
        $this->from->save(Content::create(ContentKey::user('alice'), ['email' => 'alice@example.com']));
    }

    public function testEveryDocumentAcrossTypesAndLocalesReachesTheTarget(): void
    {
        $this->seedSource();

        $migrator = new StorageMigrator(['page', 'user']);
        $migrator->migrate($this->from, $this->to);

        $this->assertSame('Home', $this->to->find(ContentKey::page('home'))?->title());
        $this->assertSame('Startseite', $this->to->find(ContentKey::page('home', 'de'))?->title());
        $this->assertSame('About', $this->to->find(ContentKey::page('about'))?->title());
        $this->assertSame(
            'alice@example.com',
            $this->to->find(ContentKey::user('alice'))?->data['email'] ?? null,
        );
    }

    public function testTheTallyCountsWhatWasCopied(): void
    {
        $this->seedSource();

        $migrator = new StorageMigrator(['page', 'user']);
        $summary = $migrator->migrate($this->from, $this->to);

        $this->assertSame(4, $summary['copied']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(['copied' => 3, 'skipped' => 0], $summary['types']['page']);
        $this->assertSame(['copied' => 1, 'skipped' => 0], $summary['types']['user']);
    }

    public function testATypeWithNoDocumentsCopiesNothing(): void
    {
        $this->seedSource();

        $migrator = new StorageMigrator(['page', 'user', 'redirect']);
        $summary = $migrator->migrate($this->from, $this->to);

        $this->assertSame(['copied' => 0, 'skipped' => 0], $summary['types']['redirect']);
    }

    public function testATypeNotNamedIsNotCopied(): void
    {
        $this->seedSource();

        // 'user' is deliberately omitted from the type list.
        $migrator = new StorageMigrator(['page']);
        $migrator->migrate($this->from, $this->to);

        $this->assertNull($this->to->find(ContentKey::user('alice')));
    }

    public function testReRunningSkipsInsteadOfDuplicatingOrCorrupting(): void
    {
        $this->seedSource();
        $migrator = new StorageMigrator(['page', 'user']);

        $first = $migrator->migrate($this->from, $this->to);
        $this->assertSame(4, $first['copied']);

        // Second run: nothing changed at the source, so everything is already
        // present identically and must be skipped, not rewritten.
        $second = $migrator->migrate($this->from, $this->to);
        $this->assertSame(0, $second['copied']);
        $this->assertSame(4, $second['skipped']);

        foreach ($second['skippedDetails'] as $skip) {
            $this->assertSame('unchanged', $skip['reason']);
        }

        // The target still holds exactly one of each document, uncorrupted.
        $this->assertCount(2, $this->to->findByType('page', ContentKey::page('home')->locale));
        $this->assertSame('Home', $this->to->find(ContentKey::page('home'))?->title());
        $this->assertSame('alice@example.com', $this->to->find(ContentKey::user('alice'))?->data['email'] ?? null);
    }

    public function testAChangedDocumentIsRewrittenOnReRun(): void
    {
        $this->seedSource();
        $migrator = new StorageMigrator(['page']);
        $migrator->migrate($this->from, $this->to);

        // The source page changes; a re-run must carry the change across rather
        // than skip it as "already there".
        $this->from->save(Content::create(ContentKey::page('home'), ['title' => 'Home Reworked']));

        $summary = $migrator->migrate($this->from, $this->to);

        $this->assertSame('Home Reworked', $this->to->find(ContentKey::page('home'))?->title());
        $this->assertGreaterThanOrEqual(1, $summary['copied']);
    }
}
