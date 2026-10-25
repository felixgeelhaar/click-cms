<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Infrastructure\Schema\JsonSectionPresetRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonSectionPresetRepositoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-preset-path-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testRejectsSymlinkedDataDirectory(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside, 0700, true);
        symlink($outside, $this->root . '/data');
        $rejected = false;
        try {
            new JsonSectionPresetRepository($this->root);
        } catch (RuntimeException $error) {
            $rejected = true;
        } finally {
            @unlink($this->root . '/data');
            @rmdir($outside);
        }
        self::assertTrue($rejected, 'A symlinked site data directory must not redirect private preset writes.');
    }

    public function testPresetFileAndLockAreCreatedWithPrivatePermissions(): void
    {
        $site = sys_get_temp_dir() . '/click-presets-mode-' . bin2hex(random_bytes(6));
        mkdir($site . '/data', 0755, true);
        try {
            $repository = new JsonSectionPresetRepository($site);
            $repository->create('Private', [['type' => 'hero', 'values' => ['heading' => 'Private text']]], 'admin');
            self::assertSame(0600, fileperms($site . '/data/section-presets.json') & 0777);
            self::assertSame(0600, fileperms($site . '/data/section-presets.lock') & 0777);
        } finally {
            foreach (glob($site . '/data/*') ?: [] as $file) @unlink($file);
            @rmdir($site . '/data');
            @rmdir($site);
        }
    }

    private function removeTree(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $entry) is_dir($entry) && !is_link($entry) ? $this->removeTree($entry) : @unlink($entry);
        @rmdir($path);
    }
}
