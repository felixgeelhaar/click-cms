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

    /**
     * A layout that needs a fixed box wants a properly-cropped file, not the
     * whole aspect-ratio-preserving ladder squeezed with CSS. The crop is square
     * and centred on the focal point, so the subject the editor marked survives
     * the crop.
     */
    public function testGeneratesASquareCropCentredOnTheFocalPoint(): void
    {
        // A wide source: cropping to a square must choose which horizontal band
        // to keep, and the focal point decides it.
        $path = $this->makeImage('wide.jpg', 2000, 1000);

        $side = $this->processor->generateSquareCrop($path, $this->dir, 'wide', 'jpg', 0.5, 0.5);

        $this->assertNotNull($side);
        $crop = $this->dir . '/wide-square.jpg';
        $this->assertFileExists($crop);
        $info = $this->processor->inspect($crop);
        // Genuinely square.
        $this->assertSame($info['width'], $info['height']);
    }

    /**
     * Never invent pixels. The largest square a 2000×1000 source can yield is
     * 1000×1000; the crop is that or smaller, never larger.
     */
    public function testSquareCropNeverUpscales(): void
    {
        $path = $this->makeImage('shortish.jpg', 2000, 1000);

        $side = $this->processor->generateSquareCrop($path, $this->dir, 'shortish', 'jpg', 0.5, 0.5);

        $this->assertNotNull($side);
        $this->assertLessThanOrEqual(1000, $side);
        $this->assertSame($side, $this->processor->inspect($this->dir . '/shortish-square.jpg')['width']);
    }

    /**
     * A small source produces a small square, not an enlarged one. A 300×300
     * source can only give a square at or below 300.
     */
    public function testSquareCropOfASmallSourceStaysSmall(): void
    {
        $path = $this->makeImage('small.jpg', 400, 300);

        $side = $this->processor->generateSquareCrop($path, $this->dir, 'small', 'jpg', 0.5, 0.5);

        $this->assertNotNull($side);
        $this->assertLessThanOrEqual(300, $side);
    }

    public function testGeneratesAnArtDirectedCropAtTheDeclaredAspect(): void
    {
        // A near-square source cut to 16:9 must end up wider than it is tall.
        $path = $this->makeImage('portraitish.jpg', 1200, 1000);

        $dims = $this->processor->generateCrop($path, $this->dir, 'portraitish', 'jpg', 'wide', 16, 9, 0.5, 0.5);

        $this->assertNotNull($dims);
        $file = $this->dir . '/portraitish-crop-wide.jpg';
        $this->assertFileExists($file);
        // The output aspect matches 16:9 within a pixel of rounding.
        $this->assertEqualsWithDelta(16 / 9, $dims['width'] / $dims['height'], 0.02);
    }

    public function testArtDirectedCropNeverUpscales(): void
    {
        // A 800×450 source is already 16:9 and below CROP_MAX, so the crop is the
        // source size, never larger.
        $path = $this->makeImage('exact.jpg', 800, 450);

        $dims = $this->processor->generateCrop($path, $this->dir, 'exact', 'jpg', 'wide', 16, 9, 0.5, 0.5);

        $this->assertNotNull($dims);
        $this->assertLessThanOrEqual(800, $dims['width']);
        $this->assertLessThanOrEqual(450, $dims['height']);
    }

    public function testArtDirectedCropCapsItsLongEdge(): void
    {
        // A very wide source cut to 16:9: the long edge is capped (CROP_MAX 1600),
        // so a 4000-wide source does not yield a 4000-wide crop.
        $path = $this->makeImage('huge.jpg', 4000, 3000);

        $dims = $this->processor->generateCrop($path, $this->dir, 'huge', 'jpg', 'wide', 16, 9, 0.5, 0.5);

        $this->assertNotNull($dims);
        $this->assertLessThanOrEqual(1600, max($dims['width'], $dims['height']));
    }

    public function testArtDirectedCropRefusesAMalformedName(): void
    {
        $path = $this->makeImage('src.jpg', 1200, 800);

        $this->assertNull($this->processor->generateCrop($path, $this->dir, 'src', 'jpg', 'bad name', 16, 9, 0.5, 0.5));
        $this->assertNull($this->processor->generateCrop($path, $this->dir, 'src', 'jpg', 'wide', 0, 9, 0.5, 0.5));
    }

    public function testDeletingVariantsRemovesNamedCropsToo(): void
    {
        $path = $this->makeImage('gone.jpg', 1200, 800);
        $this->processor->generateSquareCrop($path, $this->dir, 'gone', 'jpg', 0.5, 0.5);
        $this->processor->generateCrop($path, $this->dir, 'gone', 'jpg', 'wide', 16, 9, 0.5, 0.5);
        $this->assertFileExists($this->dir . '/gone-crop-wide.jpg');

        $this->processor->deleteVariants($this->dir, 'gone', 'jpg');

        $this->assertFileDoesNotExist($this->dir . '/gone-crop-wide.jpg');
        $this->assertFileDoesNotExist($this->dir . '/gone-square.jpg');
    }

    /**
     * The focal point steers which part of a wide image the square keeps. A
     * focal point at the far right must keep the right edge — the crop region is
     * pushed against that edge rather than centred — so a subject on the right is
     * not the thing lost.
     */
    public function testFocalPointDeterminesWhichBandTheCropKeeps(): void
    {
        // Left half solid red, right half black (the makeImage default fills the
        // left half red on a black canvas). A right-biased focal point should
        // keep mostly black; a left-biased one mostly red.
        $path = $this->makeImage('sides.jpg', 2000, 1000);

        $this->processor->generateSquareCrop($path, $this->dir, 'left', 'jpg', 0.0, 0.5);
        $this->processor->generateSquareCrop($path, $this->dir, 'right', 'jpg', 1.0, 0.5);

        $leftCrop = imagecreatefromjpeg($this->dir . '/left-square.jpg');
        $rightCrop = imagecreatefromjpeg($this->dir . '/right-square.jpg');

        // Sample the centre pixel of each crop.
        $leftCentre = imagecolorsforindex($leftCrop, imagecolorat($leftCrop, (int) (imagesx($leftCrop) / 2), (int) (imagesy($leftCrop) / 2)));
        $rightCentre = imagecolorsforindex($rightCrop, imagecolorat($rightCrop, (int) (imagesx($rightCrop) / 2), (int) (imagesy($rightCrop) / 2)));

        // The left-biased crop centres on the red half; the right-biased crop on
        // the black half. Red channel is high on the left, low on the right.
        $this->assertGreaterThan($rightCentre['red'], $leftCentre['red']);
    }

    public function testSquareCropOfANonImageYieldsNull(): void
    {
        $path = $this->dir . '/fake.jpg';
        file_put_contents($path, 'not an image');

        $this->assertNull($this->processor->generateSquareCrop($path, $this->dir, 'fake', 'jpg', 0.5, 0.5));
    }

    public function testDeleteVariantsAlsoRemovesTheSquareCrop(): void
    {
        $path = $this->makeImage('boxed.jpg', 2000, 1500);
        $this->processor->generateVariants($path, $this->dir, 'boxed', 'jpg');
        $this->processor->generateSquareCrop($path, $this->dir, 'boxed', 'jpg', 0.5, 0.5);
        $this->assertFileExists($this->dir . '/boxed-square.jpg');

        $this->processor->deleteVariants($this->dir, 'boxed', 'jpg');

        $this->assertFileDoesNotExist($this->dir . '/boxed-square.jpg');
    }
}
