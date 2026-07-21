<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use InvalidArgumentException;

/**
 * The shared contract, plus what is true only of a file per document.
 */
final class JsonStorageTest extends StorageContractTestCase
{
    private string $dir;

    protected function createStorage(): StorageInterface
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        return new JsonStorage($this->dir);
    }

    protected function corruptStoredItem(ContentKey $key): void
    {
        file_put_contents($this->dir . '/' . $key->type . '/' . $key->slug . '.json', '{ this is not json');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    public function testSaveWritesOneFilePerItemGroupedByType(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));
        $this->storage->save(Content::create(ContentKey::user('admin')));

        $this->assertFileExists($this->dir . '/page/home.json');
        $this->assertFileExists($this->dir . '/user/admin.json');
    }

    public function testSaveLeavesNoTemporaryFilesBehind(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home')));

        $this->assertSame([], glob($this->dir . '/page/*.tmp') ?: []);
    }

    public function testOverwritingReusesTheSameFile(): void
    {
        $content = Content::create(ContentKey::page('home'), ['title' => 'First']);
        $this->storage->save($content);
        $this->storage->save($content->update(['title' => 'Second']));

        $this->assertCount(1, glob($this->dir . '/page/*.json') ?: []);
    }

    public function testUnsafeKeyWriteDoesNotCreateAnyFile(): void
    {
        try {
            $this->storage->save(Content::create(ContentKey::fromString('page:../../evil')));
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame([], glob($this->dir . '/*/*.json') ?: []);
    }
}
