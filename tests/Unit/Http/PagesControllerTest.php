<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\PagesController;
use PHPUnit\Framework\TestCase;

/**
 * Page management as its own controller (alongside SectionTypesController).
 *
 * Pins the route table and one cheap gate (anonymous preview → 401) without
 * needing GD or a full content tree.
 */
final class PagesControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-pages-ctrl-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data/sessions', 0o775, true);
        $_GET = [];
        $_COOKIE = [];
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

    private function controller(): PagesController
    {
        return new PagesController($this->base);
    }

    public function testTheRouteTableNamesThePageEndpoints(): void
    {
        $routes = $this->controller()->routes();

        $this->assertArrayHasKey('GET /api/pages', $routes);
        $this->assertArrayHasKey('GET /api/pages/:slug', $routes);
        $this->assertArrayHasKey('POST /api/pages', $routes);
        $this->assertArrayHasKey('PUT /api/pages/:slug', $routes);
        $this->assertArrayHasKey('DELETE /api/pages/:slug', $routes);
        $this->assertArrayHasKey('POST /api/pages/:slug/publish', $routes);
        $this->assertArrayHasKey('POST /api/pages/:slug/unpublish', $routes);
        $this->assertArrayHasKey('GET /api/schedule', $routes);
        $this->assertArrayHasKey('GET /api/pages/:slug/schedule', $routes);
        $this->assertArrayHasKey('PUT /api/pages/:slug/schedule', $routes);
        $this->assertArrayHasKey('DELETE /api/pages/:slug/schedule', $routes);
        $this->assertArrayHasKey('GET /api/pages/:slug/versions', $routes);
        $this->assertArrayHasKey('GET /api/pages/:slug/versions/:id', $routes);
        $this->assertArrayHasKey('POST /api/pages/:slug/versions/:id/restore', $routes);
        $this->assertArrayHasKey('POST /api/pages/:slug/preview', $routes);

        // Section types live on SectionTypesController, not pages.
        $this->assertArrayNotHasKey('GET /api/section-types', $routes);
    }

    public function testPreviewWithoutASessionIsUnauthorized(): void
    {
        $response = $this->controller()->createPreviewLink('home');

        $this->assertSame(401, $response['status']);
        $this->assertSame('unauthenticated', $response['code']);
        $this->assertSame('Not authenticated', $response['error']);
    }

    public function testGetPageReturnsNotFoundForAnUnknownSlug(): void
    {
        $response = $this->controller()->getPage('does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['code']);
        $this->assertSame('Page not found', $response['error']);
    }
}
