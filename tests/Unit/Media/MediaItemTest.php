<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

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
