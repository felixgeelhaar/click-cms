<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\ReferenceResolver;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Http\CollectionsController;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * Collection management faults carry stable {@see \Click\Cms\Http\ApiFault} codes.
 */
final class CollectionsControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-collections-ctrl-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/config/collections', 0o775, true);
        mkdir($this->base . '/content', 0o775, true);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
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

    private function controller(): CollectionsController
    {
        $types = new JsonCollectionTypeRepository($this->base . '/config/collections');
        $content = new ContentService(new JsonStorage($this->base . '/content'));
        $collections = new CollectionService($content, $types, new SectionValidator());
        $references = new ReferenceResolver($content, $types);

        return new CollectionsController(
            $collections,
            $references,
            static fn (): array => [],
        );
    }

    public function testUnknownCollectionIsNotFoundApiFault(): void
    {
        $response = $this->controller()->getType('does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['code']);
        $this->assertSame('Unknown collection.', $response['error']);
    }

    public function testPreviewForUnknownCollectionIsNotFound(): void
    {
        $response = $this->controller()->createEntryPreviewLink('blog', 'hello');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['code']);
    }
}
