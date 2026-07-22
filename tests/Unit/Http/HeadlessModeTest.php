<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

/**
 * Headless mode, driven through the kernel the way a request drives it.
 *
 * The switch has one job: stop this instance rendering its own public site while
 * leaving everything else — the delivery API a front end reads, and the admin UI
 * an editor uses — exactly as it was. These pin both halves of that.
 */
final class HeadlessModeTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-headless-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }

        // The renderer needs the site's section types to render anything, so use
        // the real ones — otherwise a rich-text section is silently skipped and
        // "is the page rendered" cannot be told from "is the page empty".
        mkdir($this->base . '/config/sections', 0o775, true);
        foreach (glob(dirname(__DIR__, 3) . '/config/sections/*.json') ?: [] as $type) {
            copy($type, $this->base . '/config/sections/' . basename($type));
        }

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application($this->base);
        $this->app->boot();

        // A published page, so there is a rendered site to turn off.
        $storage = (new \ReflectionMethod($this->app, 'getContentService'))->invoke($this->app);
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home', 'sections' => [
            ['type' => 'rich-text', 'values' => ['body' => 'THE RENDERED SITE']],
        ]]));
        $inner = (new \ReflectionProperty($storage, 'storage'))->getValue($storage);
        $inner->publish(ContentKey::page('home'));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    private function setHeadless(bool $on): void
    {
        file_put_contents(
            $this->base . '/data/settings.json',
            json_encode(['headless' => $on])
        );
        // A fresh Application, since settings are read once at boot.
        $this->app = new Application($this->base);
        $this->app->boot();
    }

    /**
     * Drive the kernel and collect what it produced.
     *
     * A rendered page echoes its HTML and returns a `raw` marker; an API route
     * returns its data as an array that the real entry point would JSON-encode.
     * This captures both, so a test can assert on either without caring which
     * path answered.
     */
    private function request(string $uri): array
    {
        http_response_code(200);
        ob_start();
        try {
            $response = (new \ReflectionMethod($this->app, 'handleRequest'))->invoke($this->app, $uri, 'GET');
        } finally {
            $echoed = (string) ob_get_clean();
        }

        $body = ($response['raw'] ?? false) ? $echoed : (string) json_encode($response);
        $status = $response['status'] ?? http_response_code();

        return ['body' => $body, 'status' => $status];
    }

    private function signIn(string $role): void
    {
        $id = bin2hex(random_bytes(32));
        mkdir($this->base . '/data/sessions', 0o700, true);
        file_put_contents(
            $this->base . '/data/sessions/' . $id . '.json',
            json_encode([
                'username' => 'ann',
                'expiresAt' => time() + 3600,
                'lastActivity' => time(),
                'user' => ['username' => 'ann', 'role' => $role],
            ])
        );
        $_COOKIE[SessionStore::COOKIE] = $id;
    }

    /* ----------------------------------------------- who may switch -- */

    public function testAnEditorCannotChangeSettings(): void
    {
        $this->signIn('editor');

        // The role gate fires before the body is read, so an empty PUT still
        // proves the boundary: taking a site's public pages away is not an
        // editor's decision.
        $result = (new \ReflectionMethod($this->app, 'handleSettingsRequest'))->invoke($this->app, 'PUT');

        $this->assertSame(403, $result['status'] ?? null);
        $this->assertFalse(is_file($this->base . '/data/settings.json'));
    }

    public function testASignedInUserCanReadTheCurrentMode(): void
    {
        $this->signIn('editor');

        $read = (new \ReflectionMethod($this->app, 'handleSettingsRequest'))->invoke($this->app, 'GET');

        $this->assertSame(['headless' => false, 'siteName' => ''], $read['data'] ?? null);
    }

    public function testAnAnonymousRequestCannotReadSettings(): void
    {
        $result = (new \ReflectionMethod($this->app, 'handleSettingsRequest'))->invoke($this->app, 'GET');

        $this->assertSame(401, $result['status'] ?? null);
    }

    /* -------------------------------------------------- the default -- */

    public function testByDefaultTheRenderedSiteIsServed(): void
    {
        $result = $this->request('/home');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('THE RENDERED SITE', $result['body']);
    }

    /* ---------------------------------------------------- headless -- */

    public function testHeadlessStopsRenderingTheSite(): void
    {
        $this->setHeadless(true);

        $result = $this->request('/home');

        $this->assertSame(404, $result['status']);
        $this->assertStringNotContainsString('THE RENDERED SITE', $result['body']);
    }

    public function testHeadlessRootExplainsItselfRatherThanErroring(): void
    {
        $this->setHeadless(true);

        $result = $this->request('/');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('/api/pages', $result['body']);
    }

    public function testHeadlessLeavesTheDeliveryApiServingPublishedContent(): void
    {
        $this->setHeadless(true);

        // The whole point: a front end can still read the content over the API.
        $result = $this->request('/api/pages/home');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('THE RENDERED SITE', $result['body']);
    }

    public function testHeadlessDoesNotRouteTheAdminThroughTheHeadlessPlaceholder(): void
    {
        $this->setHeadless(true);

        // Editors still need to edit; headless is about the public face only.
        // The built admin assets do not exist in a unit-test base, so this
        // cannot assert the admin renders — but it can assert the admin path is
        // handled as the admin, not swallowed by the headless placeholder that
        // now answers content URLs.
        $result = $this->request('/admin/');

        $this->assertStringNotContainsString('This is a headless Click CMS', $result['body']);
        $this->assertStringNotContainsString('/api/pages', $result['body']);
    }
}
