<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Cache;

use Click\Cms\Application\Cache\RenderCache;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

/**
 * The render cache once collection entries have public addresses.
 *
 * ## The bug this exists to make impossible
 *
 * The cache key used to be (slug, locale, theme, theme version). A page and a
 * collection entry can hold the same slug — a page `notes` at `/notes` and a post
 * `notes` at `/blog/notes` are two unrelated documents — so under the old key they
 * were **one cache entry**, and whichever was rendered first was served at both
 * addresses. That failure has every property of the worst kind of bug: it is
 * invisible to the editor (whose requests are signed in, and so uncached), it
 * survives a redeploy, it cannot be reproduced by whoever reports it, and what
 * leaks is one document's content at another document's address.
 *
 * So there are two tests below for it, on purpose. The unit one pins the key. The
 * wiring one drives the kernel and would have failed on the old key even if the
 * key were changed and the kernel forgot to pass the type — which is the mistake
 * a unit test on `keyFor()` alone cannot catch.
 */
final class EntryRenderCacheTest extends TestCase
{
    private string $base;
    private Application $app;

    /* ------------------------------------------------------------- the key -- */

    public function testAPageAndAnEntryWithTheSameSlugAreNotOneCacheEntry(): void
    {
        $cache = new RenderCache(sys_get_temp_dir() . '/click-cms-key-only-' . bin2hex(random_bytes(4)));

        $page = $cache->keyFor('notes', 'en', 'default', 'v1', 'page');
        $post = $cache->keyFor('notes', 'en', 'default', 'v1', 'post');
        $other = $cache->keyFor('notes', 'en', 'default', 'v1', 'note');

        $this->assertNotSame($page, $post);
        $this->assertNotSame($post, $other);
    }

    public function testTheTypeCannotBeImpersonatedByASlug(): void
    {
        $cache = new RenderCache(sys_get_temp_dir() . '/click-cms-key-only-' . bin2hex(random_bytes(4)));

        // The NUL separator is what keeps every component's boundary its own; a
        // slug that spells another type's name must not collide with it.
        $this->assertNotSame(
            $cache->keyFor('notes', 'en', 'default', '', 'post'),
            $cache->keyFor('post', 'notes', 'default', '', 'en'),
        );
    }

    public function testAKeyWithNoTypeStillMeansAPage(): void
    {
        // Every call written before entries had addresses was keying a page, and
        // must go on meaning that.
        $cache = new RenderCache(sys_get_temp_dir() . '/click-cms-key-only-' . bin2hex(random_bytes(4)));

        $this->assertSame(
            $cache->keyFor('home', 'en', 'default', 'v1'),
            $cache->keyFor('home', 'en', 'default', 'v1', 'page'),
        );
    }

    /* ----------------------------------------------------------- the wiring -- */

    /**
     * The same collision, through the kernel, with the cache genuinely on.
     *
     * Both orders are exercised because the old bug was order-dependent: whichever
     * document was requested first won, so a test that only ever asked in one order
     * would have caught half of it.
     */
    public function testAnEntryIsNeverServedAtAPagesAddressOrTheOtherWayRound(): void
    {
        $this->boot();

        $this->publishPage('notes', 'THE PAGE CALLED NOTES');
        $this->publishPost('notes', 'THE POST CALLED NOTES');

        $page = $this->get('/notes');
        $entry = $this->get('/blog/notes');

        $this->assertStringContainsString('THE PAGE CALLED NOTES', $page);
        $this->assertStringNotContainsString('THE POST CALLED NOTES', $page);

        $this->assertStringContainsString('THE POST CALLED NOTES', $entry);
        $this->assertStringNotContainsString('THE PAGE CALLED NOTES', $entry);

        // Two documents, two entries — one would mean they are sharing a key even
        // if the assertions above happened to pass because nothing was cached.
        $this->assertSame(2, $this->cachedEntryCount());

        // And the served-from-cache reads are still each other's opposite.
        $this->assertSame($page, $this->get('/notes'));
        $this->assertSame($entry, $this->get('/blog/notes'));
    }

    public function testTheCollisionIsAbsentInTheOtherRequestOrderToo(): void
    {
        $this->boot();

        $this->publishPage('notes', 'THE PAGE CALLED NOTES');
        $this->publishPost('notes', 'THE POST CALLED NOTES');

        $entry = $this->get('/blog/notes');
        $page = $this->get('/notes');

        $this->assertStringContainsString('THE POST CALLED NOTES', $entry);
        $this->assertStringContainsString('THE PAGE CALLED NOTES', $page);
        $this->assertStringNotContainsString('THE POST CALLED NOTES', $page);
    }

    public function testTwoEntriesOfTheSameCollectionAreNotOneCacheEntry(): void
    {
        $this->boot();

        $this->publishPost('first', 'THE FIRST POST');
        $this->publishPost('second', 'THE SECOND POST');

        $first = $this->get('/blog/first');
        $second = $this->get('/blog/second');

        $this->assertStringContainsString('THE FIRST POST', $first);
        $this->assertStringNotContainsString('THE SECOND POST', $first);
        $this->assertStringContainsString('THE SECOND POST', $second);
        $this->assertSame($first, $this->get('/blog/first'));
    }

    /* -------------------------------------------------- storing and dropping -- */

    public function testAnEntryIsServedFromTheCacheOnTheSecondRequest(): void
    {
        $this->boot();
        $this->publishPost('why-we-stopped-staining', 'THE FIRST VERSION');

        $first = $this->get('/blog/why-we-stopped-staining');
        $this->assertStringContainsString('THE FIRST VERSION', $first);
        $this->assertSame(1, $this->cachedEntryCount(), 'the first request must leave an entry behind');
        $this->assertSame($first, $this->get('/blog/why-we-stopped-staining'));
    }

    /**
     * The test the cache turns on for entries: editing one must reach the next
     * visitor. Entries are content documents, so they go through the storage
     * decorator that flushes — there is no separate invalidation to forget.
     */
    public function testEditingAnEntryReachesTheNextVisitor(): void
    {
        $this->boot();
        $this->publishPost('why-we-stopped-staining', 'THE FIRST VERSION');

        $this->assertStringContainsString('THE FIRST VERSION', $this->get('/blog/why-we-stopped-staining'));

        $this->publishPost('why-we-stopped-staining', 'THE CORRECTED VERSION');

        $this->assertStringContainsString('THE CORRECTED VERSION', $this->get('/blog/why-we-stopped-staining'));
    }

    /**
     * And publishing an entry reaches the *listing* page, which is a different
     * document with a different key and no reference to the entry in it.
     */
    public function testPublishingAnEntryReachesACachedListingPage(): void
    {
        $this->boot();

        $this->app->getContentService()->save(Content::create(ContentKey::page('journal'), [
            'title' => 'Journal',
            'sections' => [['type' => 'collection-list', 'values' => ['collection' => 'post', 'limit' => 10]]],
        ]));
        $this->app->getContentService()->publish(ContentKey::page('journal'));

        $this->publishPost('first', 'THE FIRST POST');
        $this->assertStringContainsString('/blog/first', $this->get('/journal'));

        $this->publishPost('second', 'THE SECOND POST');
        $this->assertStringContainsString('/blog/second', $this->get('/journal'));
    }

    public function testAnUnpublishedEntryLeavesTheCacheBehindIt(): void
    {
        $this->boot();
        $this->publishPost('taken-down', 'THE POST');

        $this->assertStringContainsString('THE POST', $this->get('/blog/taken-down'));

        $this->app->getContentService()->unpublish(ContentKey::for('post', 'taken-down'));

        $served = $this->get('/blog/taken-down');
        $this->assertStringNotContainsString('THE POST', $served, 'a cached entry must not outlive its publication');
        $this->assertStringContainsString('not found', strtolower($served));
    }

    /* ------------------------------------------------------------- harness -- */

    private function boot(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-entry-cache-' . bin2hex(random_bytes(6));

        foreach (['content', 'data', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }
        mkdir($this->base . '/config/sections', 0o775, true);
        mkdir($this->base . '/config/collections', 0o775, true);

        $root = dirname(__DIR__, 3);
        foreach (glob($root . '/config/sections/*.json') ?: [] as $file) {
            copy($file, $this->base . '/config/sections/' . basename($file));
        }
        foreach (glob($root . '/config/collections/*.json') ?: [] as $file) {
            copy($file, $this->base . '/config/collections/' . basename($file));
        }

        file_put_contents($this->base . '/config/core.json', json_encode(['core' => [
            'cache' => ['enabled' => true],
            'languages' => ['default' => 'en', 'available' => ['en']],
        ]]));

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application($this->base);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        Publishable::reset();

        if (isset($this->base)) {
            self::removeTree($this->base);
        }
    }

    private function publishPage(string $slug, string $body): void
    {
        $content = $this->app->getContentService();
        $content->save(Content::create(ContentKey::page($slug), [
            'title' => 'Page ' . $slug,
            'sections' => [['type' => 'rich-text', 'values' => ['body' => '<p>' . $body . '</p>']]],
        ]));
        $content->publish(ContentKey::page($slug));
    }

    private function publishPost(string $slug, string $body): void
    {
        $content = $this->app->getContentService();
        $key = ContentKey::for('post', $slug);
        $content->save(Content::create($key, [
            'slug' => $slug,
            'title' => 'Post ' . $slug,
            'body' => '<p>' . $body . '</p>',
        ]));
        $content->publish($key);
    }

    private function get(string $uri): string
    {
        ob_start();
        try {
            $response = (new \ReflectionMethod($this->app, 'handleRequest'))->invoke($this->app, $uri, 'GET');
        } finally {
            $echoed = (string) ob_get_clean();
        }

        return ($response['raw'] ?? false) ? $echoed : (string) json_encode($response);
    }

    private function cachedEntryCount(): int
    {
        return count(glob($this->base . '/data/cache/pages/*.html') ?: []);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
