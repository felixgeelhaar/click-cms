<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Domain\Media\ImageQuality;
use Click\Cms\Domain\Media\ImageQualityLevel;
use PHPUnit\Framework\TestCase;

final class ImageQualityTest extends TestCase
{
    public function testAnImageWideEnoughForTheWholeLadderSaysNothing(): void
    {
        $quality = ImageQuality::forUpload(2400);

        $this->assertSame(ImageQualityLevel::Full, $quality->level);
        $this->assertFalse($quality->isWarning());
        $this->assertSame('', $quality->message);
    }

    /** Exactly the top rung is still full quality: the original serves as `xl`. */
    public function testTheTopRungWidthCountsAsFull(): void
    {
        $this->assertSame(ImageQualityLevel::Full, ImageQuality::forUpload(2048)->level);
    }

    /**
     * The upload that motivated all of this: 1022 pixels produced only `sm`, and
     * the library said nothing about why or what it cost.
     */
    public function testTheThousandAndTwentyTwoPixelUploadIsReportedAsLow(): void
    {
        $quality = ImageQuality::forUpload(1022);

        $this->assertSame(ImageQualityLevel::Low, $quality->level);
        $this->assertTrue($quality->isWarning());
        $this->assertStringContainsString('only 1022 pixels wide', $quality->message);
        $this->assertStringContainsString('look soft', $quality->message);
        $this->assertStringContainsString('2048 pixels wide', $quality->message);
    }

    public function testAMidSizedImageIsAdequateRatherThanLow(): void
    {
        $quality = ImageQuality::forUpload(1400);

        $this->assertSame(ImageQualityLevel::Adequate, $quality->level);
        $this->assertStringContainsString('sharp in most places', $quality->message);
        $this->assertStringContainsString('full width', $quality->message);
    }

    public function testMissingVariantsMatchTheRungsTheLadderWouldSkip(): void
    {
        // Nothing at or above the source width is generated, because upscaling
        // makes a larger file that looks no better.
        $this->assertSame(['md', 'lg', 'xl'], $this->missing(ImageQuality::forUpload(1022)));
        $this->assertSame(['xl'], $this->missing(ImageQuality::forUpload(2000)));
        $this->assertSame([], $this->missing(ImageQuality::forUpload(2500)));
    }

    public function testASlotSmallEnoughForTheImageIsNotWarnedAbout(): void
    {
        // 1022 real pixels covers a 400-pixel card at 2x with room to spare.
        $quality = ImageQuality::forSlot(1022, 400);

        $this->assertFalse($quality->isWarning());
        $this->assertSame('', $quality->message);
        $this->assertSame(400, $quality->displayWidth);
    }

    public function testTheSameImageIsWarnedAboutInAWiderSlot(): void
    {
        $quality = ImageQuality::forSlot(1022, 1200);

        $this->assertTrue($quality->isWarning());
        $this->assertSame(ImageQualityLevel::Low, $quality->level);
        $this->assertSame(2400, $quality->recommendedWidth);
        $this->assertStringContainsString('1022 pixels wide', $quality->message);
        $this->assertStringContainsString('shown here at 1200 pixels', $quality->message);
        $this->assertStringContainsString('2400 pixels wide', $quality->message);
    }

    public function testASlotIsSatisfiedAtExactlyTwiceItsWidth(): void
    {
        $this->assertFalse(ImageQuality::forSlot(1600, 800)->isWarning());
        $this->assertTrue(ImageQuality::forSlot(1599, 800)->isWarning());
    }

    public function testSharpUpToWidthIsHalfTheSourceWidth(): void
    {
        $this->assertSame(511, ImageQuality::forUpload(1022)->sharpUpToWidth());
    }

    public function testSerialisesEverythingAClientNeedsToRenderTheWarning(): void
    {
        $out = ImageQuality::forSlot(1022, 1200)->toArray();

        $this->assertSame('low', $out['level']);
        $this->assertTrue($out['warning']);
        $this->assertSame(1022, $out['sourceWidth']);
        $this->assertSame(1200, $out['displayWidth']);
        $this->assertSame(2400, $out['recommendedWidth']);
        $this->assertSame(511, $out['sharpUpToWidth']);
        $this->assertSame(['md', 'lg', 'xl'], $out['missingVariants']);
        $this->assertNotSame('', $out['message']);
    }

    /**
     * @return list<string>
     */
    private function missing(ImageQuality $quality): array
    {
        return $quality->toArray()['missingVariants'];
    }
}
