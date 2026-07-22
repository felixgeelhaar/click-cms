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
     * SVG is an XML document that can carry script, so serving one inline is a
     * cross-site scripting hole.
     */
    public function testRefusesSvgWithAnExplanation(): void
    {
        $this->assertFalse(UploadPolicy::isAccepted('image/svg+xml'));
        $this->assertStringContainsString('script', UploadPolicy::refusalReason('image/svg+xml'));
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
