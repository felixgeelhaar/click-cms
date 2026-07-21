<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every storage backend owes its callers.
 *
 * Backends are chosen by configuration, so a site can switch from flat files to
 * SQLite without a line of application code changing. That promise is only real
 * if the two behave the same, and the only way to know they do is to run one set
 * of assertions against both. Anything asserted here is part of the port's
 * contract; anything a single backend does differently belongs in its own class.
 *
 * `SqliteStorage` had never been executed before this suite existed.
 */
abstract class StorageContractTestCase extends TestCase
{
    protected StorageInterface $storage;

    abstract protected function createStorage(): StorageInterface;

    /**
     * Replace a stored item's payload with something undecodable.
     *
     * How you corrupt a document is necessarily backend-specific — a truncated
     * file, a mangled column — but that a corrupt document reads as absent
     * rather than throwing is contract.
     */
    abstract protected function corruptStoredItem(ContentKey $key): void;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
    }

    public function testSaveThenFindRoundTrips(): void
    {
        $this->storage->save(
            Content::create(ContentKey::page('home'), ['title' => 'Home', 'content' => 'Hi'])
        );

        $found = $this->storage->find(ContentKey::page('home'));

        $this->assertNotNull($found);
        $this->assertSame('Home', $found->title());
        $this->assertSame('Hi', $found->content());
    }

    public function testFindReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->storage->find(ContentKey::page('nope')));
    }

    public function testFindByTypeReturnsOnlyThatTypeInStableOrder(): void
    {
        foreach (['charlie', 'alpha', 'bravo'] as $slug) {
            $this->storage->save(Content::create(ContentKey::page($slug)));
        }
        $this->storage->save(Content::create(ContentKey::user('admin')));

        $slugs = array_map(
            static fn (Content $c): string => $c->slug(),
            $this->storage->findByType('page')
        );

        $this->assertSame(['alpha', 'bravo', 'charlie'], $slugs);
    }

    public function testFindByTypeReturnsEmptyArrayForUnknownType(): void
    {
        $this->assertSame([], $this->storage->findByType('nothing-here'));
    }

    public function testFindByTypeIsAListWithSequentialKeys(): void
    {
        foreach (['a', 'b'] as $slug) {
            $this->storage->save(Content::create(ContentKey::page($slug)));
        }

        $found = $this->storage->findByType('page');

        $this->assertSame([0, 1], array_keys($found));
    }

    public function testDeleteRemovesAndReportsWhetherAnythingWasRemoved(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));

        $this->assertTrue($this->storage->delete(ContentKey::page('home')));
        $this->assertFalse($this->storage->delete(ContentKey::page('home')));
        $this->assertNull($this->storage->find(ContentKey::page('home')));
    }

    public function testDeleteRemovesOnlyTheNamedItem(): void
    {
        $this->storage->save(Content::create(ContentKey::page('keep')));
        $this->storage->save(Content::create(ContentKey::page('drop')));

        $this->storage->delete(ContentKey::page('drop'));

        $this->assertTrue($this->storage->exists(ContentKey::page('keep')));
    }

    public function testExistsReflectsStoredState(): void
    {
        $this->assertFalse($this->storage->exists(ContentKey::page('home')));
        $this->storage->save(Content::create(ContentKey::page('home')));
        $this->assertTrue($this->storage->exists(ContentKey::page('home')));
    }

    public function testSaveOverwritesRatherThanDuplicating(): void
    {
        $content = Content::create(ContentKey::page('home'), ['title' => 'First']);
        $this->storage->save($content);

        $content->update(['title' => 'Second']);
        $this->storage->save($content);

        $this->assertSame('Second', $this->storage->find(ContentKey::page('home'))?->title());
        $this->assertCount(1, $this->storage->findByType('page'));
    }

    /*
     * Case sensitivity is deliberately NOT asserted here, because it is not a
     * property the port can promise. SQLite's default collation is
     * case-sensitive, so `page:Home` and `page:home` are two documents. The
     * flat-file backend inherits whatever the host filesystem does, and on macOS
     * or Windows those two are one file that overwrites itself.
     *
     * Asserting either way would be a lie on some supported platform. It is
     * recorded as a migration caveat in `docs/core.md` instead: slugs that
     * differ only in case are not portable, and a site relying on them would
     * lose documents moving from SQLite to files.
     */

    public function testTimestampsSurviveRoundTrip(): void
    {
        $content = Content::create(ContentKey::page('home'), [
            'createdAt' => '2020-01-02T03:04:05+00:00',
            'updatedAt' => '2021-06-07T08:09:10+00:00',
        ]);
        $this->storage->save($content);

        $found = $this->storage->find(ContentKey::page('home'));

        $this->assertSame('2020-01-02T03:04:05+00:00', $found?->createdAt->format(DATE_ATOM));
        $this->assertSame('2021-06-07T08:09:10+00:00', $found?->updatedAt()->format(DATE_ATOM));
    }

    public function testArbitraryNestedDataSurvivesRoundTrip(): void
    {
        $data = [
            'sections' => [
                ['type' => 'hero', 'values' => ['heading' => 'Hi', 'n' => 3, 'on' => true]],
            ],
            'nothing' => [],
        ];
        $this->storage->save(Content::create(ContentKey::page('home'), $data));

        $this->assertSame($data, $this->storage->find(ContentKey::page('home'))?->data);
    }

    public function testUnicodeAndSlashesInContentSurviveRoundTrip(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home'), [
            'title' => 'Grüße & Ümläute',
            'content' => 'a/b/c',
        ]));

        $found = $this->storage->find(ContentKey::page('home'));

        $this->assertSame('Grüße & Ümläute', $found?->title());
        $this->assertSame('a/b/c', $found?->content());
    }

    public function testCorruptItemIsTreatedAsAbsentRatherThanThrowing(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));
        $this->corruptStoredItem(ContentKey::page('home'));

        $this->assertNull($this->storage->find(ContentKey::page('home')));
        // And it must not break a listing of its siblings.
        $this->assertSame([], $this->storage->findByType('page'));
    }

    /**
     * Reads are reached straight from URLs, so an impossible key must be an
     * ordinary miss — throwing here turns every stray request into a 500.
     */
    public function testReadingAnUnsafeKeyIsAMissNotAnError(): void
    {
        $this->assertNull($this->storage->find(ContentKey::fromString('page:../../etc/passwd')));
        $this->assertNull($this->storage->find(ContentKey::fromString('page:nested/slug')));
        $this->assertFalse($this->storage->exists(ContentKey::fromString('page:../escape')));
        $this->assertFalse($this->storage->delete(ContentKey::fromString('page:../escape')));
        $this->assertSame([], $this->storage->findByType('../secrets'));
    }

    /**
     * Writing one, by contrast, is a bug or an attack and must be loud.
     */
    public function testWritingAnUnsafeSlugThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->save(Content::create(ContentKey::fromString('page:../../evil')));
    }

    public function testWritingAnUnsafeTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->save(Content::create(ContentKey::fromString('../secrets:x')));
    }

    public function testUnsafeKeyWriteStoresNothing(): void
    {
        try {
            $this->storage->save(Content::create(ContentKey::fromString('page:../../evil')));
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame([], $this->storage->findByType('page'));
    }
}
