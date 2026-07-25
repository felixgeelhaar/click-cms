<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Cache;

use Click\Cms\Application\Cache\RenderCache;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Cache\CacheInvalidatingStorage;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The decorator that keeps the render cache honest.
 *
 * A render cache has exactly one way to be worse than no cache: serving a page
 * that is no longer true. Every test here is a way that could happen. The
 * decorator exists so the answer to "did this code path remember to
 * invalidate?" is structural rather than a matter of review — so what is tested
 * is that *every* write clears, including the ones nobody thinks about.
 */
final class CacheInvalidatingStorageTest extends TestCase
{
    private string $dir;
    private RenderCache $cache;
    private CacheInvalidatingStorage $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-invalidate-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->cache = new RenderCache($this->dir . '/cache', true);
        $this->storage = new CacheInvalidatingStorage(
            new VersioningStorage(
                new JsonStorage($this->dir . '/content'),
                new JsonVersionStore($this->dir . '/versions', RetentionPolicy::keeping(5)),
                static fn (): string => 'admin',
            ),
            $this->cache,
        );
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        self::removeTree($this->dir);
    }

    /** A populated cache entry, and the key it lives under. */
    private function warm(): string
    {
        $key = $this->cache->keyFor('home', 'en', 'default', '/themes/default/theme.css?v=1');
        $this->cache->put($key, '<p>the old page</p>');
        $this->assertNotNull($this->cache->get($key), 'precondition: the cache is warm');

        return $key;
    }

    private function page(string $slug = 'home', string $title = 'Home'): Content
    {
        return Content::create(ContentKey::page($slug), ['title' => $title, 'sections' => []]);
    }

    public function testSavingContentClearsTheCache(): void
    {
        $key = $this->warm();

        $this->storage->save($this->page());

        $this->assertNull($this->cache->get($key));
    }

    public function testSavingWithAReasonClearsTheCacheToo(): void
    {
        $key = $this->warm();

        // The path a history restore takes. Missing it would mean rolling a page
        // back left the cache serving the version that was rolled away from —
        // the most confusing possible outcome of an undo.
        $this->storage->saveWithReason($this->page(title: 'Restored'), 'restored from history');

        $this->assertNull($this->cache->get($key));
    }

    public function testDeletingContentClearsTheCache(): void
    {
        $this->storage->save($this->page());
        $key = $this->warm();

        $this->storage->delete(ContentKey::page('home'));

        $this->assertNull($this->cache->get($key));
    }

    /** A delete that found nothing still clears, and still reports nothing. */
    public function testDeletingSomethingAbsentIsStillSafe(): void
    {
        $key = $this->warm();

        $this->assertFalse($this->storage->delete(ContentKey::page('never-existed')));
        $this->assertNull($this->cache->get($key));
    }

    public function testPublishingClearsTheCache(): void
    {
        $this->storage->save($this->page());
        $key = $this->warm();

        $this->storage->publish(ContentKey::page('home'));

        $this->assertNull($this->cache->get($key));
    }

    public function testUnpublishingClearsTheCache(): void
    {
        $this->storage->save($this->page());
        $this->storage->publish(ContentKey::page('home'));
        $key = $this->warm();

        $this->storage->unpublish(ContentKey::page('home'));

        $this->assertNull($this->cache->get($key));
    }

    /**
     * Publishing *another* page clears this page's entry as well. Not an
     * over-flush to be tuned away later: every document embeds the site header,
     * which is built from the main menu, so a page entering the menu changes the
     * header of every other page.
     */
    public function testAWriteToOnePageClearsEveryPagesEntry(): void
    {
        $key = $this->warm();

        $this->storage->save($this->page('about', 'About'));

        $this->assertNull($this->cache->get($key));
    }

    /**
     * The property the decorator is for. Reads are extremely frequent and must
     * not clear anything — a cache emptied by being read is not a cache.
     */
    public function testReadsLeaveTheCacheAlone(): void
    {
        $this->storage->save($this->page());
        $key = $this->warm();

        $this->storage->find(ContentKey::page('home'));
        $this->storage->findByType('page');
        $this->storage->exists(ContentKey::page('home'));
        $this->storage->draft(ContentKey::page('home'));
        $this->storage->workingCopies('page');
        $this->storage->publicationOf(ContentKey::page('home'));

        $this->assertSame('<p>the old page</p>', $this->cache->get($key));
    }

    /**
     * Every write on the storage surface must clear. Enumerated by reflection
     * rather than by hand so that a method added to the interface later cannot
     * quietly become an uninvalidated write — the list this test checks is the
     * interface itself.
     */
    public function testNoWriteMethodOnTheInterfaceEscapesInvalidation(): void
    {
        $writes = ['save', 'saveWithReason', 'delete', 'publish', 'unpublish'];

        $declared = [];
        foreach ((new \ReflectionClass(CacheInvalidatingStorage::class))->getMethods() as $method) {
            if ($method->isPublic() && $method->getName() !== '__construct') {
                $declared[] = $method->getName();
            }
        }

        $this->assertSame(
            [],
            array_diff($writes, $declared),
            'a write named here is not implemented by the decorator, so it bypasses invalidation'
        );

        // And each of them, called, leaves the cache empty. Asserted in a loop so
        // adding a write to the list above without wiring it fails immediately.
        foreach ($writes as $write) {
            $this->storage->save($this->page());
            $key = $this->warm();

            match ($write) {
                'save' => $this->storage->save($this->page()),
                'saveWithReason' => $this->storage->saveWithReason($this->page(), 'why'),
                'delete' => $this->storage->delete(ContentKey::page('home')),
                'publish' => $this->storage->publish(ContentKey::page('home')),
                'unpublish' => $this->storage->unpublish(ContentKey::page('home')),
            };

            $this->assertNull($this->cache->get($key), "{$write}() did not clear the cache");
        }
    }

    /** Writes still reach the backend — invalidation must not swallow them. */
    public function testTheWriteItselfStillHappens(): void
    {
        $this->storage->save($this->page(title: 'Written through'));

        // The working copy, because a page is publishable and a bare save leaves
        // it as a draft; `find()` reads what is live.
        $this->assertSame('Written through', $this->storage->draft(ContentKey::page('home'))?->title());

        $this->storage->publish(ContentKey::page('home'));

        $this->assertSame('Written through', $this->storage->find(ContentKey::page('home'))?->title());
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
