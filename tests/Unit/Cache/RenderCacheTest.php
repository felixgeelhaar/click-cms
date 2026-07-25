<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Cache;

use Click\Cms\Application\Cache\RenderCache;
use PHPUnit\Framework\TestCase;

/**
 * The rendered-page cache, tested for the thing that actually goes wrong.
 *
 * Storing a string in a file is not where a render cache fails. It fails by
 * answering — confidently, and only for the people who are not looking — with
 * the wrong page: the German document on the English URL, last month's theme
 * after a switch, or, worst of all, an editor's unpublished draft on the public
 * site. Every one of those is a cache doing exactly what it was asked to do with
 * a key that did not say enough.
 *
 * So these tests are mostly about keys and about refusing to store. The two
 * properties they hold in place are: **two renders that could differ never share
 * an entry**, and **nothing that is not the public, anonymous page is ever
 * written at all**. The rest — atomicity, surviving a corrupt entry, a slug from
 * a URL being unable to escape the cache directory — are the ways a cache turns
 * a performance feature into an outage or a vulnerability.
 */
final class RenderCacheTest extends TestCase
{
    private string $root;

    private string $dir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-render-cache-' . bin2hex(random_bytes(6));
        $this->dir = $this->root . '/data/cache/render';
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function cache(bool $enabled = true): RenderCache
    {
        return new RenderCache($this->dir, $enabled);
    }

    /* ------------------------------------------------------- storing/hits -- */

    /** The whole point: the second visitor is served without re-rendering. */
    public function testAMissIsFollowedByAHitOnceThePageIsStored(): void
    {
        $cache = $this->cache();
        $key = $cache->keyFor('home', 'en', 'default');

        $this->assertNull($cache->get($key), 'nothing has been rendered yet');

        $cache->put($key, '<!doctype html><p>Hello</p>');

        $this->assertSame('<!doctype html><p>Hello</p>', $cache->get($key));
    }

    /**
     * The cache directory is derived state, so it is the kind of thing an
     * operator deletes to clear it. Recreating it on demand is what stops that
     * being a way to permanently disable caching.
     */
    public function testTheCacheDirectoryIsCreatedWhenItIsNotThere(): void
    {
        $this->rrmdir($this->dir);
        $cache = $this->cache();
        $key = $cache->keyFor('home', 'en', 'default');

        $cache->put($key, '<p>Hello</p>');

        $this->assertSame('<p>Hello</p>', $cache->get($key));
    }

    /**
     * Write-then-rename. A reader arriving mid-write must see a whole document or
     * none, never half a page — the sort of failure that only shows up under the
     * load the cache was added for.
     */
    public function testAStoredPageLeavesNoTemporaryFileBehind(): void
    {
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default'), '<p>Hello</p>');

        $this->assertSame([], glob($this->dir . '/*.tmp') ?: [], 'the temporary file was renamed, not left');
    }

    /**
     * An empty render is a bug upstream. Caching it would take one broken
     * response and serve it to everyone until the next publish.
     */
    public function testAnEmptyRenderIsNeverStored(): void
    {
        $cache = $this->cache();
        $key = $cache->keyFor('home', 'en', 'default');

        $cache->put($key, '');

        $this->assertNull($cache->get($key));
    }

    /* ------------------------------------------------------------- keying -- */

    /** Different pages are different entries. The floor of the whole design. */
    public function testTwoSlugsDoNotShareAnEntry(): void
    {
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default'), '<p>Home</p>');
        $cache->put($cache->keyFor('about', 'en', 'default'), '<p>About</p>');

        $this->assertSame('<p>Home</p>', $cache->get($cache->keyFor('home', 'en', 'default')));
        $this->assertSame('<p>About</p>', $cache->get($cache->keyFor('about', 'en', 'default')));
    }

    /**
     * The same page in two languages is two documents — different prose, and a
     * different `lang` attribute, which is the one a screen reader acts on.
     */
    public function testTwoLocalesOfOnePageDoNotShareAnEntry(): void
    {
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default'), '<html lang="en">');
        $cache->put($cache->keyFor('home', 'de', 'default'), '<html lang="de">');

        $this->assertSame('<html lang="en">', $cache->get($cache->keyFor('home', 'en', 'default')));
        $this->assertSame('<html lang="de">', $cache->get($cache->keyFor('home', 'de', 'default')));
    }

    /**
     * A theme switch changes the stylesheet link in every document on the site.
     * A key that ignored the theme would keep serving the old design from cache —
     * from the admin's point of view, a theme switch that silently did nothing.
     */
    public function testTheActiveThemeIsPartOfTheKey(): void
    {
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default'), '<link href="/themes/default/style.css">');

        $this->assertNull(
            $cache->get($cache->keyFor('home', 'en', 'brutalist')),
            'switching theme must not hit the entry rendered with the previous one'
        );
    }

    /**
     * The stylesheet URL carries a cache-busting version taken from the file's
     * mtime. A designer editing their CSS in place bumps nothing and activates
     * nothing, so no invalidation fires — the key is what makes that edit heal
     * the cache instead of pinning a stale `?v=` into every document.
     */
    public function testEditingAThemeStylesheetInPlaceDoesNotHitTheOldEntry(): void
    {
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default', '1700000000'), '<link href="...?v=1700000000">');

        $this->assertNull($cache->get($cache->keyFor('home', 'en', 'default', '1700000900')));
    }

    /**
     * Joining the parts with a separator that cannot occur in any of them. With
     * a hyphen or a slash, slug `a` in locale `b-c` and slug `a-b` in locale `c`
     * would be the same entry — an ambiguity that is invisible until the day two
     * real pages happen to collide.
     */
    public function testKeyPartsCannotBeRearrangedIntoTheSameKey(): void
    {
        $cache = $this->cache();

        $this->assertNotSame(
            $cache->keyFor('a', 'b-c', 'default'),
            $cache->keyFor('a-b', 'c', 'default')
        );
        $this->assertNotSame(
            $cache->keyFor('a/b', 'en', 'default'),
            $cache->keyFor('a', 'b/en', 'default')
        );
    }

    /** The same inputs must name the same entry, or nothing ever hits. */
    public function testTheSameInputsAlwaysProduceTheSameKey(): void
    {
        $cache = $this->cache();

        $this->assertSame(
            $cache->keyFor('blog/post', 'de', 'default', '42'),
            $cache->keyFor('blog/post', 'de', 'default', '42')
        );
    }

    /* -------------------------------------------------------- eligibility -- */

    /**
     * The disclosure this feature could most easily cause. A preview renders
     * unpublished work; stored under a public page's key it would be served to
     * the next anonymous visitor.
     */
    public function testAPreviewIsNeverCacheable(): void
    {
        $this->assertFalse($this->cache()->isCacheable(preview: true, authenticated: false));
        $this->assertFalse($this->cache()->isCacheable(preview: true, authenticated: true));
    }

    /**
     * A signed-in render may contain something keyed to the person looking — a
     * `web.render` plugin is handed the request and can add whatever it likes.
     * A cache a logged-in request can write to is a cache the public reads out of.
     */
    public function testASignedInRenderIsNeverCacheable(): void
    {
        $this->assertFalse($this->cache()->isCacheable(preview: false, authenticated: true));
    }

    public function testThePublicAnonymousRenderIsTheOneThingThatIsCacheable(): void
    {
        $this->assertTrue($this->cache()->isCacheable(preview: false, authenticated: false));
    }

    /* ----------------------------------------------------------- disabled -- */

    /**
     * Off must mean genuinely uncached, not "stale but quiet". A site turning
     * this off is usually a site debugging a wrong page, and a disabled cache
     * that still answered would be the cruellest possible bug to chase.
     */
    public function testDisabledNeverStoresAndAlwaysMisses(): void
    {
        $cache = $this->cache(enabled: false);
        $key = $cache->keyFor('home', 'en', 'default');

        $cache->put($key, '<p>Hello</p>');

        $this->assertNull($cache->get($key));
        $this->assertSame([], glob($this->dir . '/*') ?: [], 'nothing was written to disk at all');
    }

    /**
     * Entries left over from when the cache was on must not be served after it
     * is turned off — otherwise turning it off does not take effect until
     * somebody remembers to clear the directory by hand.
     */
    public function testDisablingIgnoresEntriesWrittenWhileItWasEnabled(): void
    {
        $key = $this->cache()->keyFor('home', 'en', 'default');
        $this->cache()->put($key, '<p>Hello</p>');

        $this->assertNull($this->cache(enabled: false)->get($key));
    }

    public function testDisabledIsNeverCacheable(): void
    {
        $this->assertFalse($this->cache(enabled: false)->isCacheable(preview: false, authenticated: false));
    }

    /* ------------------------------------------------------- invalidation -- */

    /**
     * The site-wide invalidation: a menu change, a settings save or a publish
     * changes the header of every document, so every document has to go.
     */
    public function testFlushEmptiesTheCache(): void
    {
        $cache = $this->cache();
        foreach (['home', 'about', 'contact'] as $slug) {
            $cache->put($cache->keyFor($slug, 'en', 'default'), "<p>{$slug}</p>");
        }

        $cache->flush();

        foreach (['home', 'about', 'contact'] as $slug) {
            $this->assertNull($cache->get($cache->keyFor($slug, 'en', 'default')), $slug);
        }
    }

    /**
     * Flushing a cache that has never been written — or whose directory an
     * operator has just deleted — is a no-op, not an error. Invalidation runs on
     * every publish, and a publish must not fail because the cache was empty.
     */
    public function testFlushingAnAbsentCacheDirectoryIsHarmless(): void
    {
        $this->rrmdir($this->dir);

        $this->cache()->flush();

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    /**
     * Only what this class wrote is deleted, matched by filename shape. A cache
     * directory that is mis-configured — or one day shared with something else —
     * must not turn every publish into a delete of somebody's files.
     */
    public function testFlushDeletesOnlyItsOwnEntries(): void
    {
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default'), '<p>Home</p>');
        file_put_contents($this->dir . '/README.txt', 'not ours');

        $cache->flush();

        $this->assertFileExists($this->dir . '/README.txt');
    }

    /** The narrow, per-page drop, for the caller that can prove it is enough. */
    public function testForgetDropsOneEntryAndLeavesTheRest(): void
    {
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default'), '<p>Home</p>');
        $cache->put($cache->keyFor('about', 'en', 'default'), '<p>About</p>');

        $cache->forget($cache->keyFor('home', 'en', 'default'));

        $this->assertNull($cache->get($cache->keyFor('home', 'en', 'default')));
        $this->assertSame('<p>About</p>', $cache->get($cache->keyFor('about', 'en', 'default')));
    }

    /** Dropping something that was never there is not a failure worth raising. */
    public function testForgettingAnEntryThatWasNeverStoredIsHarmless(): void
    {
        $cache = $this->cache();

        $cache->forget($cache->keyFor('never-rendered', 'en', 'default'));

        $this->assertNull($cache->get($cache->keyFor('never-rendered', 'en', 'default')));
    }

    /* ------------------------------------------------- damaged entries --- */

    /**
     * An emptied entry reads as a miss. An empty document is never a legitimate
     * render, so serving it would blank the page for everyone; rendering again
     * costs one request and repairs it.
     */
    public function testAnEmptiedEntryReadsAsAMissRatherThanABlankPage(): void
    {
        $cache = $this->cache();
        $key = $cache->keyFor('home', 'en', 'default');
        $cache->put($key, '<p>Hello</p>');

        file_put_contents($this->dir . '/' . $key . '.html', '');

        $this->assertNull($cache->get($key));
    }

    /**
     * Anything that is not a readable file is a miss. The caller's only possible
     * response to a damaged entry is to render the page, so an exception here
     * would only convert a slow request into a 500.
     */
    public function testADirectoryWhereAnEntryShouldBeReadsAsAMiss(): void
    {
        $cache = $this->cache();
        $key = $cache->keyFor('home', 'en', 'default');
        mkdir($this->dir . '/' . $key . '.html');

        $this->assertNull($cache->get($key));
    }

    public function testAnUnreadableEntryReadsAsAMissRatherThanThrowing(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read a file regardless of its mode.');
        }

        $cache = $this->cache();
        $key = $cache->keyFor('home', 'en', 'default');
        $cache->put($key, '<p>Hello</p>');
        chmod($this->dir . '/' . $key . '.html', 0o000);

        $this->assertNull($cache->get($key));

        chmod($this->dir . '/' . $key . '.html', 0o644);
    }

    /**
     * A read-only cache directory makes the site slow, never broken. Storage
     * failing is the one thing a cache must be allowed to shrug off.
     */
    public function testAnUnwritableCacheDirectoryDegradesToNoCaching(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to a directory regardless of its mode.');
        }

        $cache = $this->cache();
        $key = $cache->keyFor('home', 'en', 'default');
        chmod($this->dir, 0o500);

        $cache->put($key, '<p>Hello</p>');

        $this->assertNull($cache->get($key));

        chmod($this->dir, 0o775);
    }

    /* ------------------------------------------------------------ escaping -- */

    /**
     * The security property. A slug arrives from the URL and is part of the key,
     * so if a key were ever concatenated into a path, a request could name a file
     * anywhere on disk — an arbitrary read from `get()` and an arbitrary *write*
     * from `put()`, which is remote code execution on a PHP site.
     *
     * Keys are digests, and anything that is not a digest is refused outright
     * rather than sanitised: a traversal that got cleaned up into some
     * neighbouring filename is still a bug, only now a silent one.
     */
    public function testAKeyContainingPathCharactersCannotEscapeTheCacheDirectory(): void
    {
        $cache = $this->cache();
        $digest = $cache->keyFor('home', 'en', 'default');

        $hostile = [
            '../../../../etc/passwd',
            '../escaped',
            '..' . DIRECTORY_SEPARATOR . 'escaped',
            $digest . '/../../escaped',
            '/etc/passwd',
            $this->root . '/escaped',
            "home\0/../escaped",
            'home',
        ];

        $before = $this->filesUnder($this->root);

        foreach ($hostile as $key) {
            $cache->put($key, '<p>escaped</p>');
            $this->assertNull($cache->get($key), "get must not read through: {$key}");
        }

        $this->assertSame(
            $before,
            $this->filesUnder($this->root),
            'no hostile key wrote a file anywhere, inside the cache directory or out of it'
        );
    }

    /**
     * The same refusal on the delete path. `forget()` unlinks, so a key it
     * accepted with a `..` in it would be a way to delete arbitrary files — the
     * quieter half of the same vulnerability.
     */
    public function testAKeyContainingPathCharactersCannotDeleteFilesOutsideTheCache(): void
    {
        // Named as the class names its own entries and placed one level up, so a
        // single `..` in an accepted key lands squarely on it. A decoy that a
        // traversal could not have reached anyway would prove nothing.
        $neighbour = dirname($this->dir) . '/precious.html';
        file_put_contents($neighbour, 'not ours');
        file_put_contents($this->root . '/precious.html', 'not ours');

        $cache = $this->cache();
        foreach (['../precious', '..' . DIRECTORY_SEPARATOR . 'precious', '../../../precious'] as $key) {
            $cache->forget($key);
        }
        $cache->forget(dirname($this->dir) . '/precious');

        $this->assertFileExists($neighbour);
        $this->assertFileExists($this->root . '/precious.html');
    }

    /**
     * Flush works off the filename shape, so a file somebody dropped in with a
     * traversal-looking name is not a way to make it delete outside the
     * directory either.
     */
    public function testFlushOnlyEverUnlinksInsideItsOwnDirectory(): void
    {
        file_put_contents($this->root . '/precious.json', '{}');
        $cache = $this->cache();
        $cache->put($cache->keyFor('home', 'en', 'default'), '<p>Home</p>');

        $cache->flush();

        $this->assertFileExists($this->root . '/precious.json');
        $this->assertNull($cache->get($cache->keyFor('home', 'en', 'default')));
    }

    /* -------------------------------------------------------------- helpers -- */

    /** @return list<string> every file below $dir, sorted, for before/after comparison. */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $found = array_merge($found, $this->filesUnder($path));
                continue;
            }
            $found[] = $path;
        }

        sort($found);

        return $found;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0o775);
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            if (is_dir($p)) {
                $this->rrmdir($p);
                continue;
            }
            @chmod($p, 0o644);
            @unlink($p);
        }
        @rmdir($dir);
    }
}
