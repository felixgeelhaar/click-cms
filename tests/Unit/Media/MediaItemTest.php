<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Domain\Media\FocalPoint;
use Click\Cms\Domain\Media\ImageSize;
use Click\Cms\Domain\Media\MediaItem;
use Click\Cms\Domain\Media\UploadPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MediaItemTest extends TestCase
{
    private function item(array $variants = [ImageSize::Small, ImageSize::Medium]): MediaItem
    {
        return MediaItem::create(
            id: 'harbour-crane-a1b2c3',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            originalName: 'Harbour Crane.JPG',
            bytes: 204_800,
            width: 2400,
            height: 1600,
            variants: $variants,
        );
    }

    public function testBuildsUrlsForOriginalAndEachVariant(): void
    {
        $urls = $this->item()->urls();

        $this->assertSame('/api/media/file/harbour-crane-a1b2c3.jpg', $urls['original']);
        $this->assertSame('/api/media/file/harbour-crane-a1b2c3-sm.jpg', $urls['variants']['sm']['url']);
        $this->assertSame(640, $urls['variants']['sm']['width']);
        $this->assertSame(1024, $urls['variants']['md']['width']);
    }

    public function testSrcsetIsOrderedSmallestFirstWithWidths(): void
    {
        $srcset = $this->item()->srcset();

        $this->assertSame(
            '/api/media/file/harbour-crane-a1b2c3-sm.jpg 640w, '
            . '/api/media/file/harbour-crane-a1b2c3-md.jpg 1024w',
            $srcset
        );
    }

    public function testSrcsetIsEmptyWithoutVariantsSoCallersCanFallBack(): void
    {
        $this->assertSame('', $this->item([])->srcset());
    }

    public function testUrlsRespectACustomBase(): void
    {
        $urls = $this->item()->urls('https://cdn.example.com/media/');

        $this->assertSame('https://cdn.example.com/media/harbour-crane-a1b2c3.jpg', $urls['original']);
    }

    public function testRoundTripsThroughArray(): void
    {
        $restored = MediaItem::fromArray($this->item()->toArray());

        $this->assertSame('harbour-crane-a1b2c3', $restored->id);
        $this->assertSame(2400, $restored->width);
        $this->assertEquals([ImageSize::Small, ImageSize::Medium], $restored->variants);
    }

    public function testCarriesNamedCropsIntoUrlsAndSurvivesARoundTrip(): void
    {
        $item = $this->item()->withCrops([
            'wide' => ['width' => 1600, 'height' => 900],
        ]);

        $urls = $item->urls();
        $this->assertSame('/api/media/file/harbour-crane-a1b2c3-crop-wide.jpg', $urls['crops']['wide']['url']);
        $this->assertSame(1600, $urls['crops']['wide']['width']);
        $this->assertSame(900, $urls['crops']['wide']['height']);

        // Round-trips through storage.
        $restored = MediaItem::fromArray($item->toArray());
        $this->assertSame(['wide' => ['width' => 1600, 'height' => 900]], $restored->crops);
    }

    public function testMalformedCropEntriesAreDropped(): void
    {
        $item = $this->item()->withCrops([
            'wide' => ['width' => 1600, 'height' => 900],
            'BadName' => ['width' => 100, 'height' => 100],   // non-slug
            'zero' => ['width' => 0, 'height' => 100],        // non-positive
        ]);

        $this->assertSame(['wide'], array_keys($item->crops));
    }

    public function testAnItemWithoutCropsExposesNoCropsKey(): void
    {
        $this->assertArrayNotHasKey('crops', $this->item()->urls());
        $this->assertSame([], $this->item()->crops);
    }

    public function testQualityIsReportedInThePayloadSoAnyClientCanShowIt(): void
    {
        $small = MediaItem::create(
            id: 'narrow-logo-a1b2c3',
            extension: 'png',
            mimeType: 'image/png',
            originalName: 'logo.png',
            bytes: 40_000,
            width: 1022,
            height: 575,
            variants: [ImageSize::Small],
        );

        $quality = $small->toArray()['quality'];

        $this->assertTrue($quality['warning']);
        $this->assertSame('low', $quality['level']);
        $this->assertSame(['md', 'lg', 'xl'], $quality['missingVariants']);
    }

    public function testQualityIsAnsweredForASlotWhenTheFieldDeclaresOne(): void
    {
        $item = MediaItem::create(
            id: 'narrow-logo-a1b2c3',
            extension: 'png',
            mimeType: 'image/png',
            originalName: 'logo.png',
            bytes: 40_000,
            width: 1022,
            height: 575,
        );

        // The same file: fine in a card, wrong in a header.
        $this->assertFalse($item->toArray(400)['quality']['warning']);
        $this->assertTrue($item->toArray(1200)['quality']['warning']);
    }

    /** A width nobody measured must not produce a verdict. */
    public function testQualityIsAbsentWhenThereAreNoPixelsToCount(): void
    {
        $unmeasured = MediaItem::create(
            id: 'unmeasured-a1b2c3',
            extension: 'gif',
            mimeType: 'image/gif',
            originalName: 'x.gif',
            bytes: 100,
        );

        $this->assertNull($unmeasured->quality());
        $this->assertNull($unmeasured->toArray()['quality']);
    }

    public function testRejectsAnIdThatCouldEscapeItsDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MediaItem::create('../../etc/passwd', 'jpg', 'image/jpeg', 'x', 1);
    }

    public function testRejectsAnUnusableExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MediaItem::create('ok-id', 'php', 'image/jpeg', 'x', 1);
    }

    public function testAltIsReplacedWithoutMutating(): void
    {
        $original = $this->item();
        $described = $original->withAlt('A crane at dusk');

        $this->assertSame('', $original->alt);
        $this->assertSame('A crane at dusk', $described->alt);
        $this->assertSame($original->id, $described->id);
    }

    /** With nothing marked, an item is centred and says so in the payload. */
    public function testDefaultsToACentredFocalPoint(): void
    {
        $array = $this->item()->toArray();

        $this->assertSame(['x' => 0.5, 'y' => 0.5], $array['focalPoint']);
        $this->assertSame('50% 50%', $array['objectPosition']);
    }

    public function testFocalPointIsReplacedWithoutMutating(): void
    {
        $original = $this->item();
        $marked = $original->withFocalPoint(FocalPoint::at(0.2, 0.8));

        $this->assertTrue($original->focalPoint->isCenter());
        $this->assertSame(0.2, $marked->focalPoint->x);
        $this->assertSame(0.8, $marked->focalPoint->y);
        $this->assertSame($original->id, $marked->id);
    }

    /** A front end reading the payload gets the object-position for free. */
    public function testFocalPointSurfacesAsObjectPositionInThePayload(): void
    {
        $array = $this->item()->withFocalPoint(FocalPoint::at(0.25, 0.75))->toArray();

        $this->assertSame(['x' => 0.25, 'y' => 0.75], $array['focalPoint']);
        $this->assertSame('25% 75%', $array['objectPosition']);
    }

    public function testRoundTripsAFocalPointThroughArray(): void
    {
        $marked = $this->item()->withFocalPoint(FocalPoint::at(0.1, 0.9));

        $restored = MediaItem::fromArray($marked->toArray());

        $this->assertSame(0.1, $restored->focalPoint->x);
        $this->assertSame(0.9, $restored->focalPoint->y);
    }

    public function testSizeLadderNamesFilesPredictably(): void
    {
        $this->assertSame('photo-sm.jpg', ImageSize::Small->filenameFor('photo', 'jpg'));
        $this->assertSame('photo-xl.webp', ImageSize::ExtraLarge->filenameFor('photo', 'webp'));
    }

    /**
     * An SVG has no raster dimensions and no variant ladder — it is
     * resolution-independent. It must still round-trip and describe itself
     * without the quality logic (which counts pixels) choking on the absence of
     * a width.
     */
    private function svg(): MediaItem
    {
        return MediaItem::create(
            id: 'company-logo-a1b2c3',
            extension: 'svg',
            mimeType: 'image/svg+xml',
            originalName: 'logo.svg',
            bytes: 512,
            width: null,
            height: null,
            variants: [],
        );
    }

    public function testSvgIsAnImageButHasNoQualityVerdict(): void
    {
        $svg = $this->svg();

        // It is served as an image…
        $this->assertTrue($svg->isImage());
        // …but has no pixels to count, so the ladder's quality warning stays
        // silent rather than reporting a nonsensical "too small".
        $this->assertNull($svg->quality());
        $this->assertNull($svg->quality(1200));
        $this->assertNull($svg->toArray()['quality']);
        $this->assertNull($svg->toArray(1200)['quality']);
    }

    public function testSvgServesItselfWithNoVariantsOrSrcset(): void
    {
        $svg = $this->svg();

        $this->assertSame('/api/media/file/company-logo-a1b2c3.svg', $svg->urls()['original']);
        $this->assertSame([], $svg->urls()['variants']);
        $this->assertSame('', $svg->srcset());
        $this->assertSame([], $svg->toArray()['variants']);
    }

    public function testSvgRoundTripsThroughArray(): void
    {
        $restored = MediaItem::fromArray($this->svg()->toArray());

        $this->assertSame('company-logo-a1b2c3', $restored->id);
        $this->assertSame('svg', $restored->extension);
        $this->assertSame('image/svg+xml', $restored->mimeType);
        $this->assertNull($restored->width);
        $this->assertSame([], $restored->variants);
    }

    /**
     * The square crop is an additional, focal-point-centred file for layouts
     * that need a fixed box. It rides alongside the ladder — it does not replace
     * it — so the payload exposes its own URL without disturbing `variants`.
     */
    public function testSquareCropSurfacesAsItsOwnUrlAndSize(): void
    {
        $cropped = MediaItem::create(
            id: 'harbour-crane-a1b2c3',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            originalName: 'crane.jpg',
            bytes: 200_000,
            width: 2400,
            height: 1600,
            variants: [ImageSize::Small, ImageSize::Medium],
            squareCrop: 1024,
        );

        $urls = $cropped->urls();
        $this->assertSame('/api/media/file/harbour-crane-a1b2c3-square.jpg', $urls['square']['url']);
        $this->assertSame(1024, $urls['square']['width']);
        $this->assertSame(1024, $urls['square']['height']);

        $array = $cropped->toArray();
        $this->assertSame(1024, $array['squareCrop']);
        // The uncropped ladder is untouched.
        $this->assertSame(['sm', 'md'], $array['variants']);
    }

    public function testNoSquareCropKeyWhenThereIsNoCrop(): void
    {
        $urls = $this->item()->urls();

        $this->assertArrayNotHasKey('square', $urls);
        $this->assertNull($this->item()->toArray()['squareCrop']);
    }

    public function testSquareCropRoundTripsThroughArray(): void
    {
        $restored = MediaItem::fromArray(
            $this->item()->withSquareCrop(768)->toArray()
        );

        $this->assertSame(768, $restored->squareCrop);
    }

    public function testSquareCropIsReplacedWithoutMutating(): void
    {
        $original = $this->item();
        $cropped = $original->withSquareCrop(512);

        $this->assertNull($original->squareCrop);
        $this->assertSame(512, $cropped->squareCrop);
        $this->assertSame($original->id, $cropped->id);
        // Clearing it back to none is expressible too.
        $this->assertNull($cropped->withSquareCrop(null)->squareCrop);
    }
}

final class UploadPolicyTest extends TestCase
{
    public function testAcceptsTheFourSupportedImageTypes(): void
    {
        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $mime) {
            $this->assertTrue(UploadPolicy::isAccepted($mime), $mime);
        }
    }

    /**
     * SVG is now an accepted *type* — but only because MediaService sanitises the
     * bytes before storing them. The type gate opening is what lets the sanitised
     * result be stored and served at all; the raw bytes are never trusted, and an
     * SVG that will not sanitise is still refused (covered in MediaServiceTest).
     */
    public function testSvgIsAnAcceptedTypeSoSanitisedLogosCanBeStored(): void
    {
        $this->assertTrue(UploadPolicy::isAccepted('image/svg+xml'));
        $this->assertSame('svg', UploadPolicy::extensionFor('image/svg+xml'));
        $this->assertContains('svg', UploadPolicy::acceptedExtensions());
        $this->assertContains('image/svg+xml', UploadPolicy::acceptedMimeTypes());
    }

    /** The message shown when an SVG's bytes cannot be made safe still names why. */
    public function testSvgRefusalNamesTheScriptRisk(): void
    {
        $this->assertStringContainsStringIgnoringCase('script', UploadPolicy::svgRefusalReason());
    }

    public function testRefusesExecutableAndMarkupTypes(): void
    {
        foreach (['application/x-php', 'text/html', 'application/zip', 'video/mp4'] as $mime) {
            $this->assertFalse(UploadPolicy::isAccepted($mime), $mime);
        }
    }

    public function testSlugIsSafeForUseInAFilename(): void
    {
        $this->assertSame('harbour-crane', UploadPolicy::slugFor('Harbour Crane.JPG'));
        $this->assertSame('etc-passwd', UploadPolicy::slugFor('../../etc/passwd.png'));
        $this->assertSame('shell', UploadPolicy::slugFor('shell.php.jpg'));
    }

    public function testSlugFallsBackWhenNothingUsableRemains(): void
    {
        $this->assertSame('image', UploadPolicy::slugFor('###.jpg'));
        $this->assertSame('image', UploadPolicy::slugFor(''));
    }

    public function testSlugIsLengthLimited(): void
    {
        $slug = UploadPolicy::slugFor(str_repeat('a', 200) . '.jpg');

        $this->assertLessThanOrEqual(40, strlen($slug));
    }
}
