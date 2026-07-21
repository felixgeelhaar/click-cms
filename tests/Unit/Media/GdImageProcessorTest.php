<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Domain\Media\ImageSize;
use Click\Cms\Infrastructure\Media\GdImageProcessor;
use PHPUnit\Framework\TestCase;

final class GdImageProcessorTest extends TestCase
{
    private string $dir;
    private GdImageProcessor $processor;

    protected function setUp(): void
    {
        if (!GdImageProcessor::isAvailable()) {
            $this->markTestSkipped('The gd extension is not available.');
        }

        $this->dir = sys_get_temp_dir() . '/click-cms-media-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->processor = new GdImageProcessor();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** Writes a real image so resizing is genuinely exercised, not mocked. */
    private function makeImage(string $name, int $width, int $height, string $format = 'jpeg'): string
    {
        $path = $this->dir . '/' . $name;
        $image = imagecreatetruecolor($width, $height);

        // Some actual content, so a resize has something to interpolate.
        $red = imagecolorallocate($image, 200, 40, 40);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, $red);

        match ($format) {
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path, 90),
        };

        return $path;
    }

    public function testInspectReportsTrueDimensionsAndType(): void
    {
        $path = $this->makeImage('photo.jpg', 1600, 900);

        $info = $this->processor->inspect($path);

        $this->assertNotNull($info);
        $this->assertSame(1600, $info['width']);
        $this->assertSame(900, $info['height']);
        $this->assertSame('image/jpeg', $info['mimeType']);
    }

    /**
     * The whole point of content-based detection: a file's name must not
     * influence what the system believes it is.
     */
    public function testInspectIgnoresAMisleadingExtension(): void
    {
        $real = $this->makeImage('actually-a-png.jpg', 100, 100, 'png');

        $this->assertSame('image/png', $this->processor->inspect($real)['mimeType']);
    }

    public function testInspectReturnsNullForNonImage(): void
    {
        $path = $this->dir . '/notes.jpg';
        file_put_contents($path, 'this is definitely not an image');

        $this->assertNull($this->processor->inspect($path));
    }

    public function testGeneratesEveryVariantNarrowerThanTheSource(): void
    {
        $path = $this->makeImage('wide.jpg', 3000, 2000);

        $written = $this->processor->generateVariants($path, $this->dir, 'wide', 'jpg');

        $this->assertSame(
            ['sm', 'md', 'lg', 'xl'],
            array_map(static fn (ImageSize $s): string => $s->value, $written)
        );

        foreach (ImageSize::ladder() as $size) {
            $variant = $this->dir . '/wide-' . $size->value . '.jpg';
            $this->assertFileExists($variant);
            $this->assertSame($size->width(), $this->processor->inspect($variant)['width']);
        }
    }

    /**
     * Enlarging a small upload wastes bytes and looks like a bug, so rungs
     * wider than the source are skipped.
     */
    public function testNeverUpscales(): void
    {
        $path = $this->makeImage('small.jpg', 800, 600);

        $written = $this->processor->generateVariants($path, $this->dir, 'small', 'jpg');

        $this->assertSame(['sm'], array_map(static fn (ImageSize $s): string => $s->value, $written));
        $this->assertFileExists($this->dir . '/small-sm.jpg');
        $this->assertFileDoesNotExist($this->dir . '/small-md.jpg');
        $this->assertFileDoesNotExist($this->dir . '/small-xl.jpg');
    }

    public function testTinyImageProducesNoVariants(): void
    {
        $path = $this->makeImage('tiny.jpg', 320, 240);

        $this->assertSame([], $this->processor->generateVariants($path, $this->dir, 'tiny', 'jpg'));
    }

    public function testAspectRatioIsPreserved(): void
    {
        $path = $this->makeImage('ratio.jpg', 2000, 1000);

        $this->processor->generateVariants($path, $this->dir, 'ratio', 'jpg');
        $info = $this->processor->inspect($this->dir . '/ratio-sm.jpg');

        $this->assertSame(640, $info['width']);
        $this->assertSame(320, $info['height']);
    }

    public function testHandlesPngWithTransparency(): void
    {
        $path = $this->makeImage('logo.png', 1200, 800, 'png');

        $written = $this->processor->generateVariants($path, $this->dir, 'logo', 'png');

        $this->assertNotEmpty($written);
        $this->assertSame('image/png', $this->processor->inspect($this->dir . '/logo-sm.png')['mimeType']);
    }

    public function testHandlesWebp(): void
    {
        $path = $this->makeImage('shot.webp', 1200, 800, 'webp');

        $written = $this->processor->generateVariants($path, $this->dir, 'shot', 'webp');

        $this->assertNotEmpty($written);
        $this->assertFileExists($this->dir . '/shot-sm.webp');
    }

    public function testNonImageYieldsNoVariantsRatherThanThrowing(): void
    {
        $path = $this->dir . '/fake.jpg';
        file_put_contents($path, 'nope');

        $this->assertSame([], $this->processor->generateVariants($path, $this->dir, 'fake', 'jpg'));
    }

    public function testDeleteVariantsRemovesThemAll(): void
    {
        $path = $this->makeImage('gone.jpg', 3000, 2000);
        $this->processor->generateVariants($path, $this->dir, 'gone', 'jpg');

        $removed = $this->processor->deleteVariants($this->dir, 'gone', 'jpg');

        $this->assertSame(4, $removed);
        foreach (ImageSize::ladder() as $size) {
            $this->assertFileDoesNotExist($this->dir . '/gone-' . $size->value . '.jpg');
        }
        // The original must survive.
        $this->assertFileExists($path);
    }
}
