<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Media\TransformRequest;
use Click\Cms\Infrastructure\Media\GdImageProcessor;
use PHPUnit\Framework\TestCase;

final class MediaPathContainmentTest extends TestCase
{
    private string $root;
    private string $media;
    private string $outside;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-media-path-' . bin2hex(random_bytes(6));
        $this->media = $this->root . '/media';
        $this->outside = $this->root . '/outside.svg';
        mkdir($this->media, 0o775, true);
        file_put_contents($this->outside, '<svg>outside media directory</svg>');
    }

    protected function tearDown(): void
    {
        @unlink($this->media . '/linked-00000000.svg');
        @unlink($this->media . '/source-00000000.jpg');
        if (is_link($this->media . '/derived')) {
            @unlink($this->media . '/derived');
        } else {
            foreach (glob($this->media . '/derived/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->media . '/derived');
        }
        @unlink($this->media . '/image-00000000.jpg');
        @unlink($this->media . '/derived/source-00000000-w640.jpg');
        @unlink($this->media . '/derived/image-00000000-w640.jpg');
        @unlink($this->outside);
        foreach (glob($this->root . '/outside-cache/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/outside-cache');
        @rmdir($this->media);
        @rmdir($this->root);
    }

    public function testMediaFileLookupRejectsSymlinksOutsideTheMediaDirectory(): void
    {
        if (!@symlink($this->outside, $this->media . '/linked-00000000.svg')) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }

        $path = (new MediaService($this->media))->pathForFile('linked-00000000.svg');

        self::assertNull($path, 'a syntactically valid media name must not expose a symlink target outside media/');
    }

    public function testCachedRenditionLookupRejectsASymlinkedDerivedDirectory(): void
    {
        $filename = 'source-00000000.jpg';
        file_put_contents($this->media . '/' . $filename, 'not an image');
        $transform = TransformRequest::fromQuery('640');
        self::assertNotNull($transform);

        $outsideCache = $this->root . '/outside-cache';
        mkdir($outsideCache);
        $outsideRendition = $outsideCache . '/' . $transform->cacheName('source-00000000', 'jpg');
        file_put_contents($outsideRendition, 'outside cache');
        if (!@symlink($outsideCache, $this->media . '/derived')) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }

        $path = (new MediaService($this->media))->pathForFileAtWidth($filename, $transform);

        self::assertSame(realpath($this->media . '/' . $filename), $path);
    }

    public function testCachedRenditionLookupRejectsASymlinkedCacheFile(): void
    {
        $filename = 'source-00000000.jpg';
        file_put_contents($this->media . '/' . $filename, 'not an image');
        mkdir($this->media . '/derived');
        if (!@symlink($this->outside, $this->media . '/derived/' . 'source-00000000-w640.jpg')) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }

        $transform = TransformRequest::fromQuery('640');
        self::assertNotNull($transform);
        $path = (new MediaService($this->media))->pathForFileAtWidth($filename, $transform);

        self::assertSame(realpath($this->media . '/' . $filename), $path);
    }

    public function testRenditionMissDoesNotWriteThroughASymlinkedDerivedDirectory(): void
    {
        if (!GdImageProcessor::isAvailable()) {
            $this->markTestSkipped('The gd extension is not available.');
        }

        $image = imagecreatetruecolor(1600, 900);
        imagejpeg($image, $this->media . '/image-00000000.jpg');
        unset($image);

        $outsideCache = $this->root . '/outside-cache';
        mkdir($outsideCache);
        if (!@symlink($outsideCache, $this->media . '/derived')) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }

        $transform = TransformRequest::fromQuery('640');
        self::assertNotNull($transform);
        $outsideRendition = $outsideCache . '/' . $transform->cacheName('image-00000000', 'jpg');
        $path = (new MediaService($this->media))->pathForFileAtWidth('image-00000000.jpg', $transform);

        self::assertSame(realpath($this->media . '/image-00000000.jpg'), $path);
        self::assertFileDoesNotExist($outsideRendition, 'a rejected derived directory must not receive a rendered file');
    }
}
