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

    /**
     * A stored image, and a published page that references it.
     *
     * Written as metadata rather than uploaded: what is under test is the URL
     * the API hands out for a known id, and going through the image processor
     * would make the test depend on GD for no gain.
     *
     * @return string The media id.
     */
    private function storeImage(Application $app): string
    {
        // The shape a real id has — a name, a dash and eight hex digits — because
        // that is what the reference scanner recognises in a page's sections.
        $id = 'photo-a1b2c3d4';
        mkdir($this->base . '/content/media', 0o775, true);
        file_put_contents(
            $this->base . '/content/media/' . $id . '.json',
            json_encode([
                'id' => $id,
                'extension' => 'jpg',
                'mimeType' => 'image/jpeg',
                'originalName' => 'photo.jpg',
                'bytes' => 1024,
                'width' => 1600,
                'height' => 900,
                'variants' => ['md', 'lg'],
            ])
        );

        $storage = (new \ReflectionMethod($app, 'getContentService'))->invoke($app);
        $storage->save(Content::create(ContentKey::page('with-media'), [
            'title' => 'With media',
            'sections' => [['type' => 'image', 'values' => ['image' => $id]]],
        ]));
        $inner = (new \ReflectionProperty($storage, 'storage'))->getValue($storage);
        $inner->publish(ContentKey::page('with-media'));

        return $id;
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

    /* ------------------------------------------- what the site hands out -- */

    /**
     * The half that is easy to ship broken: a site that routes correctly and
     * then serves a page whose stylesheet, links and images all point at the
     * domain root. The admin still works, so nothing looks wrong until a visitor
     * loads an unstyled page.
     */
    public function testARenderedPageLinksToItselfNotToTheDomainRoot(): void
    {
        $app = $this->installedAt('/2026/cms/index.php');

        $html = $this->get($app, '/2026/cms/home')['body'];

        $this->assertStringContainsString('href="/2026/cms/theme.css"', $html);
        $this->assertStringNotContainsString('href="/theme.css"', $html);
    }

    /**
     * The delivery API is read by a front end on another host, which resolves
     * these against the CMS origin. A media URL missing the prefix is a broken
     * image on someone else's site.
     */
    public function testDeliveredMediaUrlsCarryThePrefix(): void
    {
        $app = $this->installedAt('/2026/cms/index.php');
        $id = $this->storeImage($app);

        $body = json_decode($this->get($app, '/2026/cms/api/pages/with-media')['body'], true);
        $media = $body['media'][$id] ?? null;

        $this->assertNotNull($media, 'the page response should resolve its media references');
        $this->assertSame("/2026/cms/api/media/file/{$id}.jpg", $media['urls']['original']);

        // The srcset too, not only the original: a front end that gets one
        // prefixed and the other not renders the fallback and no variant, which
        // looks like a working page until someone checks what was downloaded.
        $this->assertStringStartsWith("/2026/cms/api/media/file/{$id}-md.jpg 1024w", $media['srcset']);
    }

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
