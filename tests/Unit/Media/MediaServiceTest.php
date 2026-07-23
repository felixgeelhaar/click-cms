<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Media\CropBox;
use Click\Cms\Infrastructure\Media\GdImageProcessor;
use PHPUnit\Framework\TestCase;

final class MediaServiceTest extends TestCase
{
    private string $root;
    private string $mediaDir;
    private MediaService $service;

    protected function setUp(): void
    {
        if (!GdImageProcessor::isAvailable()) {
            $this->markTestSkipped('The gd extension is not available.');
        }

        $this->root = sys_get_temp_dir() . '/click-cms-svc-' . bin2hex(random_bytes(6));
        $this->mediaDir = $this->root . '/media';
        mkdir($this->mediaDir, 0o775, true);

        $this->service = new MediaService($this->mediaDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->root . '/*') ?: [] as $d) {
            is_dir($d) ? @rmdir($d) : @unlink($d);
        }
        @rmdir($this->root);
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function upload(string $name, int $w = 2400, int $h = 1600, string $format = 'jpeg'): array
    {
        $tmp = $this->root . '/upload-' . bin2hex(random_bytes(4));
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, (int) ($w / 2), $h, imagecolorallocate($image, 10, 120, 200));

        match ($format) {
            'png' => imagepng($image, $tmp),
            'webp' => imagewebp($image, $tmp),
            default => imagejpeg($image, $tmp, 90),
        };

        return [
            'name' => $name,
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function uploadSvg(string $name, string $svg): array
    {
        $tmp = $this->root . '/upload-' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($tmp, $svg);

        return [
            'name' => $name,
            'type' => 'image/svg+xml',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
    }

    /**
     * A logo is usually an SVG, and an SVG is an XML document a browser executes.
     * It is accepted only after sanitisation, and what lands on disk is the
     * sanitised bytes — never the raw upload with its script still in it.
     */
    public function testStoresASanitisedSvgWithoutItsScript(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)">'
            . '<script>steal(document.cookie)</script>'
            . '<rect width="10" height="10" fill="#09f"/></svg>';

        $result = $this->service->store($this->uploadSvg('company-logo.svg', $dirty));

        $this->assertNull($result['error']);
        $item = $result['item'];
        $this->assertNotNull($item);
        $this->assertSame('svg', $item->extension);
        $this->assertSame('image/svg+xml', $item->mimeType);
        // Resolution-independent: no raster dimensions, no ladder, no crop.
        $this->assertNull($item->width);
        $this->assertNull($item->height);
        $this->assertSame([], $item->variants);
        $this->assertNull($item->squareCrop);

        // The airtight part: the stored file itself carries no script.
        $stored = (string) file_get_contents($this->mediaDir . '/' . $item->filename());
        $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onload', $stored);
        $this->assertStringNotContainsString('steal', $stored);
        // The drawing survives the clean.
        $this->assertStringContainsString('<rect', $stored);
    }

    /** The quality logic counts pixels; an SVG has none and must not choke it. */
    public function testAStoredSvgHasNoQualityVerdict(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="5" cy="5" r="4"/></svg>';

        $item = $this->service->store($this->uploadSvg('mark.svg', $svg))['item'];

        $this->assertNotNull($item);
        $this->assertNull($item->quality());
        $this->assertNull($item->toArray(1200)['quality']);
    }

    /**
     * An SVG that cannot be parsed into a safe drawing is refused, not stored
     * raw — the whole reason SVG was refused outright before there was a
     * sanitiser to lean on.
     */
    public function testRefusesAnSvgThatCannotBeMadeSafe(): void
    {
        $malformed = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10"';

        $result = $this->service->store($this->uploadSvg('broken.svg', $malformed));

        $this->assertNull($result['item']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsStringIgnoringCase('safe', $result['error']);
        // Nothing was written.
        $this->assertSame([], glob($this->mediaDir . '/*.svg') ?: []);
    }

    public function testAStoredSvgIsServableAndFindable(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        $item = $this->service->store($this->uploadSvg('logo.svg', $svg))['item'];

        $this->assertNotNull($this->service->find($item->id));
        $this->assertNotNull($this->service->pathForFile($item->filename()));
    }

    /**
     * A focal-point-centred square crop rides alongside the ladder for a raster
     * upload, so a fixed-box layout gets a real cropped file.
     */
    public function testGeneratesASquareCropForARasterUpload(): void
    {
        $item = $this->service->store($this->upload('crane.jpg', 2400, 1600))['item'];

        $this->assertNotNull($item);
        $this->assertNotNull($item->squareCrop);
        $this->assertFileExists($this->mediaDir . '/' . $item->id . '-square.jpg');
        // The crop is a genuine square file.
        $info = getimagesize($this->mediaDir . '/' . $item->id . '-square.jpg');
        $this->assertSame($info[0], $info[1]);
    }

    /**
     * Moving the focal point re-cuts the square around the new subject, so the
     * crop always reflects the point the editor last marked.
     */
    public function testMovingTheFocalPointRegeneratesTheSquareCrop(): void
    {
        $stored = $this->service->store($this->upload('crane.jpg', 2400, 1600))['item'];
        $before = (string) file_get_contents($this->mediaDir . '/' . $stored->id . '-square.jpg');

        $updated = $this->service->updateFocalPoint($stored->id, 0.95, 0.5);

        $this->assertNotNull($updated);
        $this->assertNotNull($updated->squareCrop);
        $after = (string) file_get_contents($this->mediaDir . '/' . $stored->id . '-square.jpg');
        // A far-right focal point keeps a different band, so the bytes change.
        $this->assertNotSame($before, $after);
        // And the persisted record still points at a crop.
        $this->assertNotNull($this->service->find($stored->id)?->squareCrop);
    }

    public function testDeletingRemovesTheSquareCropToo(): void
    {
        $stored = $this->service->store($this->upload('crane.jpg', 2400, 1600))['item'];
        $this->assertFileExists($this->mediaDir . '/' . $stored->id . '-square.jpg');

        $this->service->delete($stored->id);

        $this->assertFileDoesNotExist($this->mediaDir . '/' . $stored->id . '-square.jpg');
    }

    /* ------------------------------------------------ art-directed crops -- */

    private function serviceWithCrops(): MediaService
    {
        return new MediaService($this->mediaDir, crops: [
            CropBox::fromArray(['name' => 'wide', 'aspectWidth' => 16, 'aspectHeight' => 9]),
            CropBox::fromArray(['name' => 'portrait', 'aspectWidth' => 3, 'aspectHeight' => 4]),
        ]);
    }

    public function testUploadCutsEveryDeclaredCrop(): void
    {
        $service = $this->serviceWithCrops();
        $item = $service->store($this->upload('crane.jpg', 2400, 1600))['item'];

        $this->assertNotNull($item);
        $this->assertArrayHasKey('wide', $item->crops);
        $this->assertArrayHasKey('portrait', $item->crops);
        $this->assertFileExists($this->mediaDir . '/' . $item->id . '-crop-wide.jpg');
        $this->assertFileExists($this->mediaDir . '/' . $item->id . '-crop-portrait.jpg');

        // The wide crop is genuinely 16:9.
        $this->assertEqualsWithDelta(16 / 9, $item->crops['wide']['width'] / $item->crops['wide']['height'], 0.02);
        // And it is surfaced in the delivery URLs.
        $this->assertArrayHasKey('crops', $item->urls());
        $this->assertStringContainsString('-crop-wide.jpg', $item->urls()['crops']['wide']['url']);
    }

    public function testMovingTheFocalPointRecutsEveryCrop(): void
    {
        $service = $this->serviceWithCrops();
        $stored = $service->store($this->upload('crane.jpg', 2400, 1600))['item'];
        // The test source varies horizontally (its left half is coloured), so
        // assert on the portrait crop: a 3:4 crop of a 3:2 source spans the full
        // height and varies with the horizontal focal position.
        $before = (string) file_get_contents($this->mediaDir . '/' . $stored->id . '-crop-portrait.jpg');

        $updated = $service->updateFocalPoint($stored->id, 0.95, 0.5);

        $this->assertNotNull($updated);
        $this->assertArrayHasKey('portrait', $updated->crops);
        $after = (string) file_get_contents($this->mediaDir . '/' . $stored->id . '-crop-portrait.jpg');
        $this->assertNotSame($before, $after);
        // The persisted record still carries the crop.
        $this->assertArrayHasKey('portrait', $service->find($stored->id)?->crops ?? []);
    }

    public function testDeletingRemovesNamedCropsToo(): void
    {
        $service = $this->serviceWithCrops();
        $stored = $service->store($this->upload('crane.jpg', 2400, 1600))['item'];
        $this->assertFileExists($this->mediaDir . '/' . $stored->id . '-crop-wide.jpg');

        $service->delete($stored->id);

        $this->assertFileDoesNotExist($this->mediaDir . '/' . $stored->id . '-crop-wide.jpg');
        $this->assertFileDoesNotExist($this->mediaDir . '/' . $stored->id . '-crop-portrait.jpg');
    }

    public function testASiteWithoutDeclaredCropsGetsNone(): void
    {
        // The default service declares no crops.
        $item = $this->service->store($this->upload('crane.jpg', 2400, 1600))['item'];

        $this->assertNotNull($item);
        $this->assertSame([], $item->crops);
        $this->assertArrayNotHasKey('crops', $item->urls());
    }

    public function testStoresAnImageAndGeneratesVariants(): void
    {
        $result = $this->service->store($this->upload('Harbour Crane.JPG'));

        $this->assertNull($result['error']);
        $item = $result['item'];
        $this->assertNotNull($item);

        $this->assertSame('image/jpeg', $item->mimeType);
        $this->assertSame(2400, $item->width);
        $this->assertStringStartsWith('harbour-crane-', $item->id);
        // 2400px is wider than every rung, so all four are produced.
        $this->assertSame(['sm', 'md', 'lg', 'xl'], array_map(fn ($s) => $s->value, $item->variants));

        $this->assertFileExists($this->mediaDir . '/' . $item->filename());
        $this->assertFileExists($this->mediaDir . '/' . $item->id . '-sm.jpg');
    }

    public function testSrcsetListsEveryGeneratedVariant(): void
    {
        $item = $this->service->store($this->upload('photo.jpg', 3000, 2000))['item'];

        $srcset = $item->srcset();

        foreach (['640w', '1024w', '1536w', '2048w'] as $descriptor) {
            $this->assertStringContainsString($descriptor, $srcset);
        }
    }

    /**
     * The stored name must never be derived from what the uploader supplied,
     * or a crafted filename becomes a path.
     */
    public function testGeneratedIdNeverContainsUploaderControlledPath(): void
    {
        $item = $this->service->store($this->upload('../../etc/passwd.jpg'))['item'];

        $this->assertNotNull($item);
        $this->assertStringNotContainsString('..', $item->id);
        $this->assertStringNotContainsString('/', $item->id);
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*$/', $item->id);
    }

    public function testTwoUploadsOfTheSameNameDoNotCollide(): void
    {
        $a = $this->service->store($this->upload('photo.jpg'))['item'];
        $b = $this->service->store($this->upload('photo.jpg'))['item'];

        $this->assertNotSame($a->id, $b->id);
        $this->assertCount(2, $this->service->all());
    }

    /**
     * Type is decided by content. A PHP file renamed to .jpg must be refused.
     */
    public function testRefusesAFileThatIsNotReallyAnImage(): void
    {
        $tmp = $this->root . '/evil';
        file_put_contents($tmp, "<?php system(\$_GET['c']); ?>");

        $result = $this->service->store([
            'name' => 'innocent.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ]);

        $this->assertNull($result['item']);
        $this->assertNotNull($result['error']);
        $this->assertSame([], glob($this->mediaDir . '/*.jpg') ?: []);
    }

    public function testRefusesAnOversizedFile(): void
    {
        $file = $this->upload('big.jpg', 400, 400);
        $file['size'] = 50 * 1024 * 1024;

        $result = $this->service->store($file);

        $this->assertNull($result['item']);
        $this->assertStringContainsString('smaller than', $result['error']);
    }

    public function testReportsUploadErrorsInPlainLanguage(): void
    {
        $result = $this->service->store([
            'name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0,
        ]);

        $this->assertNull($result['item']);
        $this->assertStringContainsString('larger than', $result['error']);
    }

    public function testFindAndAllRoundTripStoredMetadata(): void
    {
        $stored = $this->service->store($this->upload('photo.jpg'))['item'];

        $found = $this->service->find($stored->id);

        $this->assertNotNull($found);
        $this->assertSame($stored->id, $found->id);
        $this->assertSame($stored->width, $found->width);
        $this->assertCount(1, $this->service->all());
    }

    public function testFindRejectsATraversingId(): void
    {
        $this->assertNull($this->service->find('../../../etc/passwd'));
    }

    public function testAltTextCanBeSetAndPersists(): void
    {
        $stored = $this->service->store($this->upload('photo.jpg'))['item'];

        $this->service->updateAlt($stored->id, 'A blue crane');

        $this->assertSame('A blue crane', $this->service->find($stored->id)?->alt);
    }

    public function testFocalPointCanBeSetAndPersists(): void
    {
        $stored = $this->service->store($this->upload('photo.jpg'))['item'];

        $updated = $this->service->updateFocalPoint($stored->id, 0.2, 0.8);

        $this->assertNotNull($updated);
        $this->assertSame(0.2, $updated->focalPoint->x);
        $this->assertSame(0.8, $updated->focalPoint->y);

        // Persisted, not just returned: reading it back off disk agrees.
        $reloaded = $this->service->find($stored->id);
        $this->assertSame(0.2, $reloaded?->focalPoint->x);
        $this->assertSame(0.8, $reloaded?->focalPoint->y);
        $this->assertSame('20% 80%', $reloaded?->toArray()['objectPosition']);
    }

    /** Setting a focal point must not throw away the description beside it. */
    public function testFocalPointDoesNotDisturbTheAltText(): void
    {
        $stored = $this->service->store($this->upload('photo.jpg'))['item'];
        $this->service->updateAlt($stored->id, 'A blue crane');

        $this->service->updateFocalPoint($stored->id, 0.3, 0.6);

        $this->assertSame('A blue crane', $this->service->find($stored->id)?->alt);
    }

    public function testUpdatingTheFocalPointOfAMissingItemReturnsNull(): void
    {
        $this->assertNull($this->service->updateFocalPoint('does-not-exist', 0.5, 0.5));
    }

    public function testDeleteRemovesOriginalVariantsAndMetadata(): void
    {
        $stored = $this->service->store($this->upload('photo.jpg'))['item'];

        $this->assertTrue($this->service->delete($stored->id));

        $this->assertNull($this->service->find($stored->id));
        $this->assertSame([], glob($this->mediaDir . '/' . $stored->id . '*') ?: []);
        $this->assertFalse($this->service->delete($stored->id));
    }

    public function testPathForFileResolvesOriginalsAndVariantsOnly(): void
    {
        $stored = $this->service->store($this->upload('photo.jpg'))['item'];

        $this->assertNotNull($this->service->pathForFile($stored->filename()));
        $this->assertNotNull($this->service->pathForFile($stored->id . '-sm.jpg'));
        $this->assertNull($this->service->pathForFile($stored->id . '.json'));
    }

    /**
     * Serving is reached straight from a URL, so a crafted name must not be
     * able to walk out of the media directory.
     */
    public function testPathForFileRefusesTraversalAndUnknownExtensions(): void
    {
        foreach ([
            '../../../etc/passwd',
            '../config/core.json',
            'photo.php',
            'photo.jpg/../../x',
            '/etc/passwd',
        ] as $attempt) {
            $this->assertNull($this->service->pathForFile($attempt), $attempt);
        }
    }

    public function testAllReturnsNewestFirst(): void
    {
        $first = $this->service->store($this->upload('first.jpg', 900, 600))['item'];
        // Metadata is ordered by mtime, so make the difference observable.
        touch($this->mediaDir . '/' . $first->id . '.json', time() - 60);
        $second = $this->service->store($this->upload('second.jpg', 900, 600))['item'];

        $ids = array_map(static fn ($i): string => $i->id, $this->service->all());

        $this->assertSame([$second->id, $first->id], $ids);
    }
}
