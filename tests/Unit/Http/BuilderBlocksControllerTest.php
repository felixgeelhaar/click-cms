<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Builder\BuilderBlockRepository;
use Click\Cms\Http\BuilderBlocksController;
use PHPUnit\Framework\TestCase;

/**
 * Builder blocks are free-form paste templates, so the interesting part of this
 * controller is who may touch them and that a malformed subtree is refused
 * rather than stored. Gates follow Role capabilities: admin may, editor may not.
 */
final class BuilderBlocksControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-builder-blocks-api-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o775, true);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $this->rrmdir($this->base);
    }

    /** @param array<string, mixed> $user */
    private function controller(array $user): BuilderBlocksController
    {
        return new BuilderBlocksController(
            new BuilderBlockRepository($this->base),
            static fn (): array => $user
        );
    }

    private function repository(): BuilderBlockRepository
    {
        return new BuilderBlockRepository($this->base);
    }

    /** @return array{name: string, root: string, nodes: array<string, mixed>} */
    private function payload(string $name = 'Hero'): array
    {
        return [
            'name' => $name,
            'root' => 'n1',
            'nodes' => [
                'n1' => [
                    'id' => 'n1',
                    'type' => 'text',
                    'children' => [],
                    'props' => ['text' => 'Hi'],
                    'styles' => [],
                ],
            ],
        ];
    }

    public function testTheRouteTableNamesTheThreeEndpoints(): void
    {
        $routes = $this->controller(['role' => 'admin'])->routes();

        $this->assertArrayHasKey('GET /api/builder/blocks', $routes);
        $this->assertArrayHasKey('POST /api/builder/blocks', $routes);
        $this->assertArrayHasKey('DELETE /api/builder/blocks/:id', $routes);
    }

    /* ---------------------------------------------------------------- list -- */

    public function testAnonymousCallersCannotListBlocks(): void
    {
        $response = $this->controller([])->list();

        $this->assertSame(401, $response['status']);
        $this->assertSame('unauthenticated', $response['code']);
    }

    public function testAnEditorCannotListBlocks(): void
    {
        // Free-form builder is admin-only by default; the palette must not leak
        // saved layouts to accounts that cannot use them.
        $response = $this->controller(['role' => 'editor'])->list();

        $this->assertSame(403, $response['status']);
        $this->assertSame('forbidden', $response['code']);
    }

    public function testAnAdministratorListsBlocks(): void
    {
        $this->repository()->save($this->payload());

        $response = $this->controller(['role' => 'admin'])->list();

        $this->assertArrayNotHasKey('status', $response);
        $this->assertSame(['hero'], array_column($response['data'], 'id'));
    }

    /* -------------------------------------------------------------- create -- */

    public function testAnonymousCallersCannotCreateBlocks(): void
    {
        $_POST = $this->payload();

        $response = $this->controller([])->create();

        $this->assertSame(401, $response['status']);
        $this->assertSame('unauthenticated', $response['code']);
        $this->assertSame([], $this->repository()->all());
    }

    public function testAnEditorCannotCreateBlocks(): void
    {
        $_POST = $this->payload();

        $response = $this->controller(['role' => 'editor'])->create();

        $this->assertSame(403, $response['status']);
        $this->assertSame('forbidden', $response['code']);
        $this->assertSame([], $this->repository()->all());
    }

    public function testAnAdministratorCreatesABlock(): void
    {
        $_POST = $this->payload();

        $response = $this->controller(['role' => 'admin'])->create();

        $this->assertSame(201, $response['status']);
        $this->assertSame('hero', $response['data']['id']);
        $this->assertNotNull($this->repository()->find('hero'));
    }

    public function testCreatingWithNoNameIsRefused(): void
    {
        $_POST = $this->payload('  ');

        $response = $this->controller(['role' => 'admin'])->create();

        $this->assertSame(400, $response['status']);
        $this->assertSame('bad_request', $response['code']);
    }

    public function testCreatingWithARootMissingFromNodesIsRefused(): void
    {
        $_POST = [
            'name' => 'Broken',
            'root' => 'missing',
            'nodes' => [
                'n1' => ['id' => 'n1', 'type' => 'text', 'children' => [], 'props' => [], 'styles' => []],
            ],
        ];

        $response = $this->controller(['role' => 'admin'])->create();

        $this->assertSame(400, $response['status']);
        $this->assertSame('bad_request', $response['code']);
        $this->assertSame([], $this->repository()->all());
    }

    public function testCreatingWithANodeMissingChildrenIsRefused(): void
    {
        $_POST = [
            'name' => 'Broken',
            'root' => 'n1',
            'nodes' => [
                'n1' => ['id' => 'n1', 'type' => 'text', 'props' => [], 'styles' => []],
            ],
        ];

        $response = $this->controller(['role' => 'admin'])->create();

        $this->assertSame(400, $response['status']);
        $this->assertSame('bad_request', $response['code']);
    }

    /* -------------------------------------------------------------- delete -- */

    public function testAnAdministratorDeletesABlock(): void
    {
        $this->repository()->save($this->payload());

        $response = $this->controller(['role' => 'admin'])->delete('hero');

        $this->assertArrayNotHasKey('status', $response);
        $this->assertTrue($response['data']['deleted']);
        $this->assertNull($this->repository()->find('hero'));
    }

    public function testDeletingAMissingBlockIsNotFound(): void
    {
        $response = $this->controller(['role' => 'admin'])->delete('nope');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['code']);
    }

    public function testAnEditorCannotDeleteBlocks(): void
    {
        $this->repository()->save($this->payload());

        $response = $this->controller(['role' => 'editor'])->delete('hero');

        $this->assertSame(403, $response['status']);
        $this->assertSame('forbidden', $response['code']);
        $this->assertNotNull($this->repository()->find('hero'));
    }

    /* ------------------------------------------------------------- helpers -- */

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
