<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Media\TransformRequest;
use Click\Cms\Infrastructure\Media\GdImageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Serving an image at a width it was not pre-generated at.
 *
 * The feature is small; the property that matters is the bound. Resizing on
 * demand is a denial-of-service vector wearing a feature's clothes — `?w=1`,
 * `?w=2`, `?w=3` … would be a few thousand cheap requests that each cost a
 * decode, a resample and an unbounded cache entry. Snapping to a fixed ladder is
 * what keeps the derived cache to (images × ladder entries) no matter what is
 * asked for, and that is what these tests hold in place.
 */
final class TransformRequestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!GdImageProcessor::isAvailable()) {
            $this->markTestSkipped('The gd extension is not available.');
        }
        $this->dir = sys_get_temp_dir() . '/click-cms-transform-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /* ------------------------------------------------------------ snapping -- */

    /** The bound. Any width at all collapses to one of a fixed, small set. */
    public function testEveryConceivableWidthSnapsIntoTheLadder(): void
    {
        $produced = [];
        foreach ([1, 2, 37, 159, 161, 500, 999, 1025, 2047, 2049, 100000] as $asked) {
            $produced[] = TransformRequest::fromQuery((string) $asked)?->width;
        }

        $this->assertSame(
            [],
            array_diff(array_unique($produced), TransformRequest::WIDTHS),
            'a requested width must never produce anything outside the ladder'
        );
    }

    /**
     * Snapped *up*, never down: an image narrower than asked for would be
     * upscaled by the browser, which is the blurriness this is meant to avoid.
     */
    public function testAWidthSnapsUpwardsSoTheImageIsNeverTooSmall(): void
    {
        $this->assertSame(160, TransformRequest::snap(1));
        $this->assertSame(320, TransformRequest::snap(161));
        $this->assertSame(768, TransformRequest::snap(700));
        $this->assertSame(768, TransformRequest::snap(768));
    }

    /** A request beyond the ladder is a request for the largest we make. */
    public function testAnAbsurdlyLargeWidthIsCappedRatherThanHonoured(): void
    {
        $this->assertSame(2048, TransformRequest::snap(100000));
    }

    public function testNoWidthOrNonsenseMeansNoTransform(): void
    {
        $this->assertNull(TransformRequest::fromQuery(null));
        $this->assertNull(TransformRequest::fromQuery(''));
        $this->assertNull(TransformRequest::fromQuery('wide'));
        $this->assertNull(TransformRequest::fromQuery('-40'));
        $this->assertNull(TransformRequest::fromQuery('0'));
    }

    /* ------------------------------------------------------- rendering -- */

    private function service(): MediaService
    {
        return new MediaService($this->dir);
    }

    private function storeImage(int $w = 1600, int $h = 900): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, (int) ($w / 2), $h, imagecolorallocate($im, 10, 120, 200));
        $tmp = $this->dir . '/upload.jpg';
        imagejpeg($im, $tmp, 90);

        $item = $this->service()->store([
            'name' => 'wide.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
        ])['item'];
        $this->assertNotNull($item);

        return $item->id . '.jpg';
    }

    public function testARequestedWidthIsRenderedOnceAndThenCached(): void
    {
        $filename = $this->storeImage();
        $service = $this->service();
        $transform = TransformRequest::fromQuery('700'); // snaps to 768

        $first = $service->pathForFileAtWidth($filename, $transform);
        $this->assertNotNull($first);
        $this->assertStringContainsString('-w768.', (string) $first);
        $this->assertSame(768, getimagesize((string) $first)[0]);

        // The second request is served from the file the first wrote.
        $before = filemtime((string) $first);
        $second = $service->pathForFileAtWidth($filename, $transform);
        $this->assertSame($first, $second);
        $this->assertSame($before, filemtime((string) $second), 'a cached rendition must not be rewritten');
    }

    /**
     * Never invent pixels: asking for more than the source has returns the
     * original, which is already the best available.
     */
    public function testAWidthLargerThanTheSourceServesTheOriginal(): void
    {
        $filename = $this->storeImage(400, 300);

        $path = $this->service()->pathForFileAtWidth($filename, TransformRequest::fromQuery('2048'));

        $this->assertStringNotContainsString('-w', (string) $path);
        $this->assertSame(400, getimagesize((string) $path)[0]);
    }

    public function testNoTransformServesTheOriginalUnchanged(): void
    {
        $filename = $this->storeImage();

        $this->assertSame(
            $this->service()->pathForFile($filename),
            $this->service()->pathForFileAtWidth($filename, null)
        );
    }

    public function testAnUnknownFileIsStillNotFound(): void
    {
        $this->assertNull(
            $this->service()->pathForFileAtWidth('absent-00000000.jpg', TransformRequest::fromQuery('640'))
        );
    }

    /**
     * Renditions are cache, not content. Keeping them out of the upload
     * directory is what stops them appearing in the media library as files an
     * editor never created and cannot manage.
     */
    public function testRenditionsDoNotAppearInTheMediaLibrary(): void
    {
        $filename = $this->storeImage();
        $service = $this->service();
        $service->pathForFileAtWidth($filename, TransformRequest::fromQuery('640'));

        $this->assertCount(1, $service->all(), 'the library lists the upload, not its renditions');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
