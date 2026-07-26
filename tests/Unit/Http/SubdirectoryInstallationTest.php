<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

/**
 * A site installed under a URL prefix, driven the way a request drives it.
 *
 * {@see \Click\Cms\Tests\Unit\Http\BasePathTest} pins the rule; this pins that
 * the kernel actually applies it, which is the part that decides whether the
 * install works. Both hosting shapes are exercised against the same fixture, so
 * a change that quietly makes one of them the only supported one fails here.
 */
final class SubdirectoryInstallationTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-subdir-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }
        mkdir($this->base . '/config/sections', 0o775, true);
        foreach (glob(dirname(__DIR__, 3) . '/config/sections/*.json') ?: [] as $type) {
            copy($type, $this->base . '/config/sections/' . basename($type));
        }

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        unset($_SERVER['SCRIPT_NAME']);
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /** An installation reached through the given front controller path. */
    private function installedAt(string $scriptName): Application
    {
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $app = new Application($this->base);
        $app->boot();

        $storage = (new \ReflectionMethod($app, 'getContentService'))->invoke($app);
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home', 'sections' => [
            ['type' => 'rich-text', 'values' => ['body' => 'THE RENDERED SITE']],
        ]]));
        $inner = (new \ReflectionProperty($storage, 'storage'))->getValue($storage);
        $inner->publish(ContentKey::page('home'));

        return $app;
    }

    /** @return array{body: string, status: int} */
    private function get(Application $app, string $requestUri): array
    {
        http_response_code(200);
        ob_start();
        try {
            $response = $app->route($requestUri, 'GET');
        } finally {
            $echoed = (string) ob_get_clean();
        }

        return [
            'body' => ($response['raw'] ?? false) ? $echoed : (string) json_encode($response),
            'status' => (int) ($response['status'] ?? http_response_code()),
        ];
    }

    /* ------------------------------------------------------------- root -- */

    public function testASiteAtTheRootIsUnaffected(): void
    {
        $app = $this->installedAt('/index.php');

        $api = $this->get($app, '/api/pages');
        $this->assertSame(200, $api['status']);
        $this->assertStringContainsString('home', $api['body']);

        $this->assertStringContainsString('THE RENDERED SITE', $this->get($app, '/home')['body']);
    }

    /* ---------------------------------------------------- subdirectory -- */

    public function testTheDeliveryApiAnswersUnderAPrefix(): void
    {
        $app = $this->installedAt('/2026/cms/index.php');

        $api = $this->get($app, '/2026/cms/api/pages');

        $this->assertSame(200, $api['status']);
        $this->assertStringContainsString('home', $api['body']);
    }

    /**
     * The bug this whole change exists for: before the prefix was stripped, a
     * request under a subdirectory matched no route at all.
     */
    public function testAPublicPageRendersUnderAPrefix(): void
    {
        $app = $this->installedAt('/2026/cms/index.php');

        $this->assertStringContainsString(
            'THE RENDERED SITE',
            $this->get($app, '/2026/cms/home')['body']
        );
    }

    public function testTheInstallationRootRendersTheSiteRoot(): void
    {
        $app = $this->installedAt('/2026/cms/index.php');

        $this->assertSame(
            $this->get($app, '/2026/cms/')['body'],
            $this->get($app, '/2026/cms')['body']
        );
    }

    /** A query string survives the prefix being taken off. */
    public function testQueryParametersStillReachTheRouterUnderAPrefix(): void
    {
        $app = $this->installedAt('/2026/cms/index.php');

        $this->assertSame(200, $this->get($app, '/2026/cms/api/pages?locale=en')['status']);
    }

    /**
     * Shared hosting without mod_rewrite names the front controller in the URL.
     * The router must see the same path either way, or the CMS is unusable on
     * exactly the hosting it is built for.
     */
    public function testTheApiAnswersWhenTheFrontControllerIsNamedInThePath(): void
    {
        $app = $this->installedAt('/2026/cms/index.php');

        $this->assertSame(200, $this->get($app, '/2026/cms/index.php/api/pages')['status']);
    }

    /**
     * A configured prefix beats detection, which is what a site behind a reverse
     * proxy needs — there the script's own path says nothing about the public
     * URL.
     */
    public function testAConfiguredPrefixOverridesTheDetectedOne(): void
    {
        file_put_contents(
            $this->base . '/config/core.json',
            json_encode(['core' => ['basePath' => '/published/here']])
        );

        $app = $this->installedAt('/index.php');

        $this->assertSame('/published/here', $app->urlBase()->prefix());
        $this->assertSame(200, $this->get($app, '/published/here/api/pages')['status']);
    }
}
