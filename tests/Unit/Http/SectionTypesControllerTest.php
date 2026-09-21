<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\SectionTypesController;
use PHPUnit\Framework\TestCase;

/**
 * Section-type schema as its own controller, after CoreApiRoutes was emptied.
 *
 * Pins the route table and the cheap 404 / list shapes without needing a full
 * Application boot.
 */
final class SectionTypesControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-section-types-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/config/sections', 0o775, true);
        file_put_contents(
            $this->base . '/config/sections/hero.json',
            json_encode([
                'id' => 'hero',
                'label' => 'Hero',
                'fields' => [
                    ['name' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                ],
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
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

    private function controller(): SectionTypesController
    {
        return new SectionTypesController($this->base);
    }

    public function testTheRouteTableNamesTheSectionTypeEndpoints(): void
    {
        $routes = $this->controller()->routes();

        $this->assertArrayHasKey('GET /api/section-types', $routes);
        $this->assertArrayHasKey('GET /api/section-types/:id', $routes);
        $this->assertCount(2, $routes);
    }

    public function testListReturnsDeclaredTypes(): void
    {
        $response = $this->controller()->list();

        $this->assertArrayHasKey('data', $response);
        $this->assertCount(1, $response['data']);
        $this->assertSame('hero', $response['data'][0]['id']);
        $this->assertSame('Hero', $response['data'][0]['label']);
        $this->assertArrayNotHasKey('warnings', $response);
    }

    public function testGetReturnsAKnownType(): void
    {
        $response = $this->controller()->get('hero');

        $this->assertArrayHasKey('data', $response);
        $this->assertSame('hero', $response['data']['id']);
    }

    public function testGetReturnsNotFoundForAnUnknownId(): void
    {
        $response = $this->controller()->get('does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['code']);
        $this->assertSame('Section type not found', $response['error']);
    }

    public function testFallsBackToInstallRootWhenSiteHasNoSectionsDir(): void
    {
        $site = $this->base . '-site';
        mkdir($site, 0o775, true);

        try {
            $controller = new SectionTypesController($site, $this->base);
            $response = $controller->get('hero');

            $this->assertArrayHasKey('data', $response);
            $this->assertSame('hero', $response['data']['id']);
        } finally {
            $this->removeTree($site);
        }
    }
}
