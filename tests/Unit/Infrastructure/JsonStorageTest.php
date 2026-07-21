<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JsonStorageTest extends TestCase
{
    private string $dir;
    private JsonStorage $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->storage = new JsonStorage($this->dir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    public function testSaveThenFindRoundTrips(): void
    {
        $content = Content::create(ContentKey::page('home'), ['title' => 'Home', 'content' => 'Hi']);
        $this->storage->save($content);

        $found = $this->storage->find(ContentKey::page('home'));

        $this->assertNotNull($found);
        $this->assertSame('Home', $found->title());
        $this->assertSame('Hi', $found->content());
    }

    public function testFindReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->storage->find(ContentKey::page('nope')));
    }

    public function testSaveWritesOneFilePerItemGroupedByType(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));
        $this->storage->save(Content::create(ContentKey::user('admin')));

        $this->assertFileExists($this->dir . '/page/home.json');
        $this->assertFileExists($this->dir . '/user/admin.json');
    }

    public function testSaveLeavesNoTemporaryFilesBehind(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));

        $this->assertSame([], glob($this->dir . '/page/*.tmp') ?: []);
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

    public function testDeleteRemovesAndReportsWhetherAnythingWasRemoved(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));

        $this->assertTrue($this->storage->delete(ContentKey::page('home')));
        $this->assertFalse($this->storage->delete(ContentKey::page('home')));
        $this->assertNull($this->storage->find(ContentKey::page('home')));
    }

    public function testExistsReflectsStoredState(): void
    {
        $this->assertFalse($this->storage->exists(ContentKey::page('home')));
        $this->storage->save(Content::create(ContentKey::page('home')));
        $this->assertTrue($this->storage->exists(ContentKey::page('home')));
    }

    public function testSaveOverwritesExistingItem(): void
    {
        $content = Content::create(ContentKey::page('home'), ['title' => 'First']);
        $this->storage->save($content);

        $content->update(['title' => 'Second']);
        $this->storage->save($content);

        $this->assertSame('Second', $this->storage->find(ContentKey::page('home'))?->title());
        $this->assertCount(1, glob($this->dir . '/page/*.json') ?: []);
    }

    public function testCorruptFileIsTreatedAsAbsentRatherThanThrowing(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));
        file_put_contents($this->dir . '/page/home.json', '{ this is not json');

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

    public function testUnsafeKeyWriteDoesNotCreateAnyFile(): void
    {
        try {
            $this->storage->save(Content::create(ContentKey::fromString('page:../../evil')));
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame([], glob($this->dir . '/*/*.json') ?: []);
    }

    public function testUnicodeAndSlashesInContentSurviveRoundTrip(): void
    {
        $content = Content::create(ContentKey::page('home'), [
            'title' => 'Grüße & Ümläute',
            'content' => 'a/b/c',
        ]);
        $this->storage->save($content);

        $found = $this->storage->find(ContentKey::page('home'));

        $this->assertSame('Grüße & Ümläute', $found?->title());
        $this->assertSame('a/b/c', $found?->content());
    }
}
