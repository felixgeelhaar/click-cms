<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\CoreApiRoutes;
use PHPUnit\Framework\TestCase;

/**
 * Which media a page references. A headless front end reading a rich-text body
 * needs its inline images resolved to variants, so ids are found both as a whole
 * field value and embedded in text — but a lookalike run of characters is not
 * mistaken for one.
 */
final class MediaReferenceScanTest extends TestCase
{
    public function testFindsAWholeValueImageField(): void
    {
        $sections = [['type' => 'media-text', 'values' => ['image' => 'harbour-crane-a1b2c3d4']]];

        $this->assertSame(['harbour-crane-a1b2c3d4'], CoreApiRoutes::mediaIdsIn($sections));
    }

    public function testFindsIdsEmbeddedInAMarkdownBody(): void
    {
        $sections = [[
            'type' => 'services',
            'values' => ['body' => "## Simulation\n![Simulation](simulation-xl-a64bf155)\n\ntext"],
        ]];

        $this->assertSame(['simulation-xl-a64bf155'], CoreApiRoutes::mediaIdsIn($sections));
    }

    public function testFindsIdsInsideAUrl(): void
    {
        $sections = [['values' => ['body' => '<img src="/api/media/file/hero-poster-9653dc33.jpg">']]];

        $this->assertSame(['hero-poster-9653dc33'], CoreApiRoutes::mediaIdsIn($sections));
    }

    public function testCollectsEveryDistinctIdOnceInOrder(): void
    {
        $sections = [
            ['values' => ['body' => '![a](one-image-aaaaaaaa) and ![b](two-image-bbbbbbbb)']],
            ['values' => ['body' => 'again ![a](one-image-aaaaaaaa)']],
        ];

        $this->assertSame(['one-image-aaaaaaaa', 'two-image-bbbbbbbb'], CoreApiRoutes::mediaIdsIn($sections));
    }

    public function testDoesNotMatchALongerHexRun(): void
    {
        // A git-sha-like token ends in more than eight hex digits, so it is not a
        // media id and must not be picked up.
        $sections = [['values' => ['body' => 'commit deadbeef-0123456789abcdef in the notes']]];

        $this->assertSame([], CoreApiRoutes::mediaIdsIn($sections));
    }

    public function testIgnoresOrdinaryWords(): void
    {
        $sections = [['values' => ['heading' => 'Leistungen', 'body' => 'Vier Kernbereiche.']]];

        $this->assertSame([], CoreApiRoutes::mediaIdsIn($sections));
    }
}
