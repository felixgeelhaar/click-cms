<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
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

    /* ---------------------------------------------------------- retention -- */

    /**
     * The data-loss bug the exemption exists to close.
     *
     * Retention used to have no exemptions, which was harmless while a version
     * was only ever a copy of something already safely in `content/`. Once the
     * newest version became the working copy and a published version became the
     * record of what the live site is serving, the twenty-first edit started
     * discarding the state the public was actually reading: the site would go
     * on serving it, and nothing could say what it was or put it back.
     */
    public function testRetentionNeverDiscardsThePublishedOrTheNewestVersion(): void
    {
        $store = new JsonVersionStore($this->dir, RetentionPolicy::keeping(3));
        $key = ContentKey::page('home');

        $store->record($this->page('Nothing special'));
        $published = $store->record($this->page('What the public reads'), 'ada', Version::REASON_PUBLISH);

        // Far more edits afterwards than the limit allows.
        for ($i = 0; $i < 10; $i++) {
            $store->record($this->page("Edit {$i}"));
        }

        $newest = $store->newest($key);

        $this->assertNotNull($store->find($key, $published->id));
        $this->assertSame('What the public reads', $store->lastPublished($key)?->content()->title());
        $this->assertSame('Edit 9', $newest?->content()->title());
        $this->assertNotNull($store->find($key, $newest->id));

        // The limit still bites. The exemption changes *which* three survive,
        // not how many — the published version keeps a place that would
        // otherwise have gone to a more recent edit, rather than being an extra
        // one on top. Overshooting the limit only happens when the exempt
        // entries outnumber the room, which is covered in RetentionPolicyTest.
        $this->assertCount(3, $store->all($key));
    }

    /**
     * A publish of the newest version makes one entry both exempt for both
     * reasons, and it must not be counted — or spared — twice.
     */
    public function testTheSameVersionBeingBothNewestAndPublishedIsNotDoubleCounted(): void
    {
        $store = new JsonVersionStore($this->dir, RetentionPolicy::keeping(2));
        $key = ContentKey::page('home');

        foreach (range(1, 5) as $n) {
            $store->record($this->page("Edit {$n}"));
        }
        $store->record($this->page('Live'), 'ada', Version::REASON_PUBLISH);

        $this->assertCount(2, $store->all($key));
        $this->assertSame('Live', $store->newest($key)?->content()->title());
    }

    /* --------------------------------------------------------- publication -- */

    public function testNewestIsTheMostRecentlyRecordedState(): void
    {
        $key = ContentKey::page('home');

        $this->assertNull($this->store->newest($key));

        $this->store->record($this->page('First'));
        $this->store->record($this->page('Second'));

        $this->assertSame('Second', $this->store->newest($key)?->content()->title());
    }

    public function testLastPublishedIgnoresOrdinarySaves(): void
    {
        $key = ContentKey::page('home');

        $this->store->record($this->page('Draft'));
        $this->assertNull($this->store->lastPublished($key));

        $this->store->record($this->page('Live'), 'ada', Version::REASON_PUBLISH);
        $this->store->record($this->page('Edited since'));

        $this->assertSame('Live', $this->store->lastPublished($key)?->content()->title());
    }

    /**
     * A management listing has to include documents that exist only as drafts,
     * and `content/` is by definition where those are not.
     */
    public function testKeysOfTypeFindsDocumentsThatExistOnlyAsVersions(): void
    {
        $this->store->record(Content::create(ContentKey::page('alpha', 'en'), ['title' => 'A']));
        $this->store->record(Content::create(ContentKey::page('beta', 'de'), ['title' => 'B']));
        $this->store->record(Content::create(ContentKey::user('admin'), ['role' => 'admin']));

        $all = array_map(
            static fn (ContentKey $k): string => $k->toString(),
            $this->store->keysOfType('page')
        );
        sort($all);

        $this->assertSame(['page:de:beta', 'page:en:alpha'], $all);

        // Per language, because a listing screen shows one.
        $this->assertSame(
            ['page:de:beta'],
            array_map(
                static fn (ContentKey $k): string => $k->toString(),
                $this->store->keysOfType('page', Locale::fromString('de'))
            )
        );
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

        $this->assertFileExists($this->dir . '/page/en/home/' . $version->id . '.json');
    }

    /**
     * History contains unpublished drafts, so it must not sit anywhere the
     * content store globs or a document root might expose.
     */
    public function testNothingIsWrittenOutsideTheVersionsDirectory(): void
    {
        $this->store->record($this->page('First'), 'ada');

        $this->assertDirectoryExists($this->dir . '/page/en/home');
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
        $this->assertCount(3, glob($this->dir . '/page/en/home/*.json') ?: []);
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

        $this->assertSame([], glob($this->dir . '/page/en/home/*.tmp') ?: []);
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

        file_put_contents($this->dir . '/page/en/home/' . $first->id . '.json', '{ not json');

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
