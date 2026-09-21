<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Builder;

use Click\Cms\Application\Builder\BuilderBlockRepository;
use PHPUnit\Framework\TestCase;

/**
 * Blocks are snapshot files under data/, so what is pinned here is the contract
 * authors rely on: a save survives a fresh repository, unsafe ids never reach
 * the filesystem, and a collision gets a new id rather than overwriting.
 */
final class BuilderBlockRepositoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-builder-blocks-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    private function repository(): BuilderBlockRepository
    {
        return new BuilderBlockRepository($this->base);
    }

    /** @return array{name: string, root: string, nodes: array<string, mixed>} */
    private function sample(string $name = 'Hero'): array
    {
        return [
            'name' => $name,
            'root' => 'n1',
            'nodes' => [
                'n1' => [
                    'id' => 'n1',
                    'type' => 'section',
                    'children' => ['n2'],
                    'props' => [],
                    'styles' => ['padding' => '24px'],
                ],
                'n2' => [
                    'id' => 'n2',
                    'type' => 'text',
                    'children' => [],
                    'props' => ['text' => 'Hello'],
                    'styles' => [],
                ],
            ],
        ];
    }

    public function testSavingPersistsAndSurvivesAFreshRepository(): void
    {
        $saved = $this->repository()->save($this->sample());
        $this->assertNotNull($saved);
        $this->assertSame('hero', $saved['id']);
        $this->assertFileExists($this->base . '/hero.json');

        $found = $this->repository()->find('hero');
        $this->assertSame('Hero', $found['name'] ?? null);
        $this->assertSame('n1', $found['root'] ?? null);
        $this->assertArrayHasKey('n2', $found['nodes'] ?? []);
    }

    public function testListingIsSortedById(): void
    {
        $this->repository()->save($this->sample('Zebra'));
        $this->repository()->save($this->sample('Alpha'));

        $ids = array_column($this->repository()->all(), 'id');
        $this->assertSame(['alpha', 'zebra'], $ids);
    }

    public function testASecondSaveWithTheSameNameGetsAUniqueId(): void
    {
        $first = $this->repository()->save($this->sample('Hero'));
        $second = $this->repository()->save($this->sample('Hero'));

        $this->assertSame('hero', $first['id'] ?? null);
        $this->assertNotSame('hero', $second['id'] ?? null);
        $this->assertStringStartsWith('hero-', (string) ($second['id'] ?? ''));
        $this->assertCount(2, $this->repository()->all());
    }

    public function testUnsafeIdsAreRefused(): void
    {
        $this->assertNull($this->repository()->find('../hero'));
        $this->assertNull($this->repository()->find('Hero'));
        $this->assertFalse($this->repository()->delete('../hero'));
    }

    public function testDeleteRemovesTheFile(): void
    {
        $saved = $this->repository()->save($this->sample());
        $this->assertTrue($this->repository()->delete($saved['id']));
        $this->assertNull($this->repository()->find($saved['id']));
        $this->assertFalse($this->repository()->delete($saved['id']));
    }

    public function testAnEmptyNameOrBrokenSubtreeIsRefused(): void
    {
        $this->assertNull($this->repository()->save([
            'name' => '  ',
            'root' => 'n1',
            'nodes' => ['n1' => ['children' => []]],
        ]));
        $this->assertNull($this->repository()->save([
            'name' => 'Ok',
            'root' => 'missing',
            'nodes' => ['n1' => ['children' => []]],
        ]));
        $this->assertSame([], $this->repository()->all());
    }

    public function testAMissingDirectoryIsAnEmptyListNotAFailure(): void
    {
        $repository = new BuilderBlockRepository($this->base . '/nowhere');

        $this->assertSame([], $repository->all());
        $this->assertNull($repository->find('hero'));
    }

    public function testSavingCreatesTheDirectoryWhenItIsNotThereYet(): void
    {
        $dir = $this->base . '/nested/blocks';
        $repository = new BuilderBlockRepository($dir);
        $this->assertDirectoryDoesNotExist($dir);

        $saved = $repository->save($this->sample());
        $this->assertNotNull($saved);
        $this->assertDirectoryExists($dir);
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
