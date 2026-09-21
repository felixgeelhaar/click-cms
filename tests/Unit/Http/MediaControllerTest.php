<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\MediaController;
use PHPUnit\Framework\TestCase;

/**
 * Media management as its own controller (alongside SectionTypesController).
 *
 * Pins the route table and one cheap gate (missing id → 404) without needing
 * GD — image-processing coverage stays in the MediaService / MediaLibrary tests.
 */
final class MediaControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-media-ctrl-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content/media', 0o775, true);
        $_GET = [];
        $_FILES = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_FILES = [];
        $_POST = [];
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

    /** @param array<string, mixed> $user */
    private function controller(array $user = ['role' => 'admin']): MediaController
    {
        return new MediaController(
            $this->base,
            static fn (): array => $user,
        );
    }

    public function testTheRouteTableNamesTheMediaEndpoints(): void
    {
        $routes = $this->controller()->routes();

        $this->assertArrayHasKey('GET /api/media', $routes);
        $this->assertArrayHasKey('POST /api/media', $routes);
        $this->assertArrayHasKey('POST /api/media/bulk-delete', $routes);
        $this->assertArrayHasKey('GET /api/media/capabilities', $routes);
        $this->assertArrayHasKey('GET /api/media/file/:filename', $routes);
        $this->assertArrayHasKey('GET /api/media/:id', $routes);
        $this->assertArrayHasKey('PUT /api/media/:id', $routes);
        $this->assertArrayHasKey('DELETE /api/media/:id', $routes);
    }

    public function testGetMediaReturnsNotFoundForAnUnknownId(): void
    {
        $response = $this->controller()->getMedia('does-not-exist-deadbeef');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['code']);
        $this->assertSame('Media not found', $response['error']);
    }

    public function testUploadWithoutAFileIsBadRequest(): void
    {
        $_FILES = [];
        $response = $this->controller()->uploadMedia();

        $this->assertSame(400, $response['status']);
        $this->assertSame('bad_request', $response['code']);
        $this->assertSame('No file was uploaded.', $response['error']);
    }

    public function testCapabilitiesReportsAcceptedTypesWithoutNeedingGd(): void
    {
        $response = $this->controller()->mediaCapabilities();

        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('acceptedMimeTypes', $response['data']);
        $this->assertArrayHasKey('resizingAvailable', $response['data']);
        $this->assertIsBool($response['data']['resizingAvailable']);
    }
}
