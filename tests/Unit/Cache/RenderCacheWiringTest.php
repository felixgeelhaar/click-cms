<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Cache;

use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

/**
 * The render cache as the kernel actually uses it.
 *
 * The cache and its invalidating decorator are tested on their own elsewhere.
 * What is left — and what actually determines whether a visitor is ever served
 * a stale page — is the wiring: that the public path reads and writes it, that
 * nothing else does, and that an edit really does reach the page a visitor gets
 * next. A cache that is correct in isolation and wired to the wrong side of a
 * publish is still a cache that lies.
 *
 * Driven through `handleRequest()` rather than by calling the cache, because the
 * bugs being guarded against live in the wiring, not in the class.
 */
final class RenderCacheWiringTest extends TestCase
{
    private string $base;
    private Application $app;

    private function boot(bool $cacheEnabled): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-cachewiring-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }

        mkdir($this->base . '/config/sections', 0o775, true);
        foreach (glob(dirname(__DIR__, 3) . '/config/sections/*.json') ?: [] as $type) {
            copy($type, $this->base . '/config/sections/' . basename($type));
        }

        file_put_contents(
            $this->base . '/config/core.json',
            json_encode(['core' => [
                'cache' => ['enabled' => $cacheEnabled],
                'languages' => ['default' => 'en', 'available' => ['en']],
            ]])
        );

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application($this->base);
        $this->app->boot();

        $this->publishHome('THE FIRST VERSION');
    }

    private function publishHome(string $body): void
    {
        $content = $this->app->getContentService();
        $content->save(Content::create(ContentKey::page('home'), [
            'title' => 'Home',
            'sections' => [['type' => 'rich-text', 'values' => ['body' => $body]]],
        ]));
        $content->publish(ContentKey::page('home'));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        if (isset($this->base)) {
            self::removeTree($this->base);
        }
    }

    private function get(string $uri = '/'): string
    {
        ob_start();
        try {
            $response = (new \ReflectionMethod($this->app, 'handleRequest'))->invoke($this->app, $uri, 'GET');
        } finally {
            $echoed = (string) ob_get_clean();
        }

        return ($response['raw'] ?? false) ? $echoed : (string) json_encode($response);
    }

    private function cacheDir(): string
    {
        return $this->base . '/data/cache/pages';
    }

    private function cachedEntryCount(): int
    {
        return count(glob($this->cacheDir() . '/*.html') ?: []);
    }

    /* ------------------------------------------------------------- it is on -- */

    public function testAPublicPageIsStoredOnFirstRequestAndServedFromCacheAfter(): void
    {
        $this->boot(cacheEnabled: true);

        $first = $this->get('/');
        $this->assertStringContainsString('THE FIRST VERSION', $first);
        $this->assertSame(1, $this->cachedEntryCount(), 'the first request must leave an entry behind');

        // Byte-identical, which is what "served from cache" means; a re-render
        // that happened to produce the same output would also pass, so the entry
        // count above is what distinguishes them.
        $this->assertSame($first, $this->get('/'));
    }

    /**
     * The test the whole feature turns on. An editor publishes a change and the
     * next visitor must see it — not the version cached a moment before.
     */
    public function testPublishingAChangeReachesTheNextVisitor(): void
    {
        $this->boot(cacheEnabled: true);

        $this->assertStringContainsString('THE FIRST VERSION', $this->get('/'));

        $this->publishHome('THE SECOND VERSION');

        $fresh = $this->get('/');
        $this->assertStringContainsString('THE SECOND VERSION', $fresh);
        $this->assertStringNotContainsString('THE FIRST VERSION', $fresh);
    }

    /** Unpublishing must take the page down, not leave it cached and readable. */
    public function testUnpublishingTakesThePageDown(): void
    {
        $this->boot(cacheEnabled: true);
        $this->get('/');

        $this->app->getContentService()->unpublish(ContentKey::page('home'));

        $this->assertStringNotContainsString('THE FIRST VERSION', $this->get('/'));
    }

    /**
     * A preview renders unpublished work behind a signed link. Storing one would
     * put a draft in the entry an anonymous visitor reads next; reading one would
     * tell an editor their unsaved work looks fine when it is the last published
     * render they are looking at.
     */
    public function testAPreviewIsNeverStored(): void
    {
        $this->boot(cacheEnabled: true);

        $content = $this->app->getContentService();
        $content->save(Content::create(ContentKey::page('secret'), [
            'title' => 'Secret',
            'sections' => [['type' => 'rich-text', 'values' => ['body' => 'UNPUBLISHED DRAFT']]],
        ]));

        $token = (new \Click\Cms\Application\Preview\PreviewLinks($this->base . '/data/preview-secret'))
            ->issue(ContentKey::page('secret'))['token'];

        $before = $this->cachedEntryCount();
        $_GET['token'] = $token;
        $body = $this->get('/preview/secret?token=' . $token);

        $this->assertSame($before, $this->cachedEntryCount(), 'a preview must not add a cache entry');
        $this->assertStringNotContainsString('UNPUBLISHED DRAFT', implode('', array_map(
            static fn (string $f): string => (string) file_get_contents($f),
            glob($this->cacheDir() . '/*.html') ?: []
        )));
        // The preview itself still worked; this is not passing by 404ing.
        $this->assertStringContainsString('UNPUBLISHED DRAFT', $body);
    }

    /* ------------------------------------------------------------ it is off -- */

    public function testNothingIsCachedWhenTheCacheIsOff(): void
    {
        $this->boot(cacheEnabled: false);

        $this->assertStringContainsString('THE FIRST VERSION', $this->get('/'));
        $this->assertSame(0, $this->cachedEntryCount());
        $this->assertFalse(is_dir($this->cacheDir()), 'a disabled cache should not even make its directory');
    }

    /**
     * Off is the default. A cache that arrives switched on with an upgrade is a
     * cache nobody chose, and its failure mode is invisible to whoever would have
     * to notice it.
     */
    public function testTheCacheIsOffUnlessTheSiteAsksForIt(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-cachedefault-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }
        file_put_contents($this->base . '/config/core.json', json_encode(['core' => []]));

        $app = new Application($this->base);
        $app->boot();

        $cache = (new \ReflectionProperty($app, 'renderCache'))->getValue($app);
        $this->assertNotNull($cache);
        $this->assertFalse($cache->isEnabled());
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            self::removeTree($path . '/' . $e);
        }
        @rmdir($path);
    }
}
