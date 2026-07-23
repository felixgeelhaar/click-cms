<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;

final class JsonSectionTypeRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-schema-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function write(string $filename, string $json): void
    {
        file_put_contents($this->dir . '/' . $filename, $json);
    }

    private function repo(): JsonSectionTypeRepository
    {
        return new JsonSectionTypeRepository($this->dir);
    }

    public function testLoadsTypesFromDirectory(): void
    {
        $this->write('banner.json', json_encode([
            'label' => 'Banner',
            'fields' => [['name' => 'heading', 'type' => 'text']],
        ]));

        $repo = $this->repo();

        $this->assertCount(1, $repo->all());
        $this->assertTrue($repo->has('banner'));
        $this->assertSame('Banner', $repo->find('banner')?->label);
        $this->assertSame([], $repo->errors());
    }

    /**
     * The filename is the id unless the file says otherwise, so a definition
     * cannot silently disagree with the file it lives in.
     */
    public function testIdDefaultsToFilename(): void
    {
        $this->write('feature-grid.json', json_encode([
            'fields' => [['name' => 'heading', 'type' => 'text']],
        ]));

        $this->assertSame('feature-grid', $this->repo()->find('feature-grid')?->id);
    }

    public function testExplicitIdWins(): void
    {
        $this->write('anything.json', json_encode([
            'id' => 'promo',
            'fields' => [['name' => 'heading', 'type' => 'text']],
        ]));

        $repo = $this->repo();

        $this->assertTrue($repo->has('promo'));
        $this->assertFalse($repo->has('anything'));
    }

    public function testMissingDirectoryIsEmptyNotAnError(): void
    {
        $repo = new JsonSectionTypeRepository($this->dir . '/does-not-exist');

        $this->assertSame([], $repo->all());
        $this->assertSame([], $repo->errors());
    }

    public function testEmptyDirectoryYieldsNoTypes(): void
    {
        $this->assertSame([], $this->repo()->all());
    }

    /**
     * One broken definition must not take the whole admin UI down.
     */
    public function testMalformedFileIsReportedButOthersStillLoad(): void
    {
        $this->write('good.json', json_encode([
            'fields' => [['name' => 'heading', 'type' => 'text']],
        ]));
        $this->write('broken.json', '{ not json at all');

        $repo = $this->repo();

        $this->assertCount(1, $repo->all());
        $this->assertTrue($repo->has('good'));
        $this->assertArrayHasKey('broken.json', $repo->errors());
        $this->assertStringContainsString('Invalid JSON', $repo->errors()['broken.json']);
    }

    public function testInvalidSchemaIsReportedWithItsReason(): void
    {
        $this->write('bad.json', json_encode([
            'fields' => [['name' => 'x', 'type' => 'hologram']],
        ]));

        $errors = $this->repo()->errors();

        $this->assertArrayHasKey('bad.json', $errors);
        $this->assertStringContainsString('hologram', $errors['bad.json']);
    }

    public function testNonObjectJsonIsReported(): void
    {
        $this->write('list.json', json_encode(['a', 'b']));

        $this->assertArrayHasKey('list.json', $this->repo()->errors());
    }

    public function testDuplicateIdsAreReportedRatherThanSilentlyOverwriting(): void
    {
        $this->write('a.json', json_encode([
            'id' => 'same',
            'fields' => [['name' => 'x', 'type' => 'text']],
        ]));
        $this->write('b.json', json_encode([
            'id' => 'same',
            'fields' => [['name' => 'y', 'type' => 'text']],
        ]));

        $repo = $this->repo();

        $this->assertCount(1, $repo->all());
        $this->assertNotSame([], $repo->errors());
    }

    public function testTypesAreOrderedStablyByFilename(): void
    {
        foreach (['charlie', 'alpha', 'bravo'] as $name) {
            $this->write("{$name}.json", json_encode([
                'fields' => [['name' => 'x', 'type' => 'text']],
            ]));
        }

        $ids = array_map(
            static fn ($t): string => $t->id,
            $this->repo()->all()
        );

        $this->assertSame(['alpha', 'bravo', 'charlie'], $ids);
    }

    public function testNonJsonFilesAreIgnored(): void
    {
        $this->write('notes.txt', 'ignore me');
        $this->write('real.json', json_encode([
            'fields' => [['name' => 'x', 'type' => 'text']],
        ]));

        $this->assertCount(1, $this->repo()->all());
    }
}
