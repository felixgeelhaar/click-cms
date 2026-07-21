<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JsonVersionStoreTest extends TestCase
{
    private string $dir;
    private JsonVersionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-versions-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->store = new JsonVersionStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
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

    private function page(string $title): Content
    {
        return Content::create(ContentKey::page('home'), ['title' => $title, 'owner' => 'ada']);
    }

    public function testRecordThenFindRoundTrips(): void
    {
        $version = $this->store->record($this->page('First'), 'ada');

        $found = $this->store->find(ContentKey::page('home'), $version->id);

        $this->assertNotNull($found);
        $this->assertSame('ada', $found->author);
        $this->assertSame(Version::REASON_SAVE, $found->reason);
        $this->assertSame('First', $found->content()->title());
    }

    /**
     * The layout is derived from the key's own string form, one directory per
     * part, so a key that gains a dimension gains a directory level here
     * without this store changing.
     */
    public function testVersionsAreFiledUnderEveryPartOfTheKey(): void
    {
        $version = $this->store->record($this->page('First'), 'ada');

        $this->assertFileExists($this->dir . '/page/home/' . $version->id . '.json');
    }

    /**
     * History contains unpublished drafts, so it must not sit anywhere the
     * content store globs or a document root might expose.
     */
    public function testNothingIsWrittenOutsideTheVersionsDirectory(): void
    {
        $this->store->record($this->page('First'), 'ada');

        $this->assertDirectoryExists($this->dir . '/page/home');
        $this->assertSame(['page'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])));
    }

    public function testAllReturnsEveryVersionNewestFirst(): void
    {
        foreach (['First', 'Second', 'Third'] as $title) {
            $this->store->record($this->page($title), 'ada');
        }

        $titles = array_map(
            static fn (Version $v): string => $v->content()->title(),
            $this->store->all(ContentKey::page('home'))
        );

        $this->assertSame(['Third', 'Second', 'First'], $titles);
    }

    public function testAllIsEmptyForADocumentWithNoHistory(): void
    {
        $this->assertSame([], $this->store->all(ContentKey::page('never-written')));
    }

    public function testVersionsOfDifferentDocumentsDoNotMix(): void
    {
        $this->store->record($this->page('Home'), 'ada');
        $this->store->record(Content::create(ContentKey::page('about'), ['title' => 'About']), 'ada');

        $this->assertCount(1, $this->store->all(ContentKey::page('home')));
        $this->assertCount(1, $this->store->all(ContentKey::page('about')));
    }

    public function testRetentionKeepsTheNewestAndDiscardsTheOldest(): void
    {
        $store = new JsonVersionStore($this->dir, RetentionPolicy::keeping(3));

        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $title) {
            $store->record($this->page($title), 'ada');
        }

        $titles = array_map(
            static fn (Version $v): string => $v->content()->title(),
            $store->all(ContentKey::page('home'))
        );

        $this->assertSame(['Five', 'Four', 'Three'], $titles);
        $this->assertCount(3, glob($this->dir . '/page/home/*.json') ?: []);
    }

    /**
     * The limit is per document: a busy page must not push a quiet one's
     * history out.
     */
    public function testRetentionIsCountedPerDocument(): void
    {
        $store = new JsonVersionStore($this->dir, RetentionPolicy::keeping(2));

        foreach (range(1, 5) as $n) {
            $store->record($this->page("Home {$n}"), 'ada');
        }
        $store->record(Content::create(ContentKey::page('about'), ['title' => 'About']), 'ada');

        $this->assertCount(2, $store->all(ContentKey::page('home')));
        $this->assertCount(1, $store->all(ContentKey::page('about')));
    }

    public function testAnAuthorIsOptional(): void
    {
        $version = $this->store->record($this->page('First'));

        $this->assertNull($version->author);
        $this->assertNull($this->store->find(ContentKey::page('home'), $version->id)?->author);
    }

    public function testRecordLeavesNoTemporaryFilesBehind(): void
    {
        $this->store->record($this->page('First'), 'ada');

        $this->assertSame([], glob($this->dir . '/page/home/*.tmp') ?: []);
    }

    /**
     * A version identifier comes straight from a URL.
     */
    public function testACraftedVersionIdentifierIsAMissNotAnEscape(): void
    {
        $this->store->record($this->page('First'), 'ada');

        $this->assertNull($this->store->find(ContentKey::page('home'), '../../../etc/passwd'));
        $this->assertNull($this->store->find(ContentKey::page('home'), 'anything'));
    }

    public function testFindingUnderAnImpossibleKeyIsAMiss(): void
    {
        $this->assertNull(
            $this->store->find(ContentKey::fromString('page:../escape'), '20260721T104512.123456Z-a3f9')
        );
        $this->assertSame([], $this->store->all(ContentKey::fromString('page:../escape')));
    }

    /**
     * Recording one, by contrast, is a bug or an attack and must be loud.
     */
    public function testRecordingUnderAnImpossibleKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->record(Content::create(ContentKey::fromString('page:../../evil')));
    }

    /**
     * A history showing the versions it can still read beats one that shows an
     * error because a single file is damaged.
     */
    public function testOneCorruptVersionDoesNotHideTheOthers(): void
    {
        $first = $this->store->record($this->page('First'), 'ada');
        $this->store->record($this->page('Second'), 'ada');

        file_put_contents($this->dir . '/page/home/' . $first->id . '.json', '{ not json');

        $remaining = $this->store->all(ContentKey::page('home'));

        $this->assertCount(1, $remaining);
        $this->assertSame('Second', $remaining[0]->content()->title());
        $this->assertNull($this->store->find(ContentKey::page('home'), $first->id));
    }

    public function testEachRecordGetsItsOwnIdentifier(): void
    {
        $ids = [];
        foreach (range(1, 10) as $n) {
            $ids[] = $this->store->record($this->page("Take {$n}"), 'ada')->id;
        }

        $this->assertCount(10, array_unique($ids));
    }
}
