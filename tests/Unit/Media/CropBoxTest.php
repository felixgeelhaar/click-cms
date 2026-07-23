<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Domain\Media\CropBox;
use PHPUnit\Framework\TestCase;

/**
 * A declared art-directed crop: a name and an aspect ratio, parsed leniently so
 * one malformed entry never costs the whole set.
 */
final class CropBoxTest extends TestCase
{
    public function testParsesAWellFormedCrop(): void
    {
        $box = CropBox::fromArray(['name' => 'wide', 'aspectWidth' => 16, 'aspectHeight' => 9]);

        $this->assertNotNull($box);
        $this->assertSame('wide', $box->name);
        $this->assertSame(16, $box->aspectWidth);
        $this->assertSame(9, $box->aspectHeight);
        $this->assertEqualsWithDelta(16 / 9, $box->ratio(), 0.0001);
    }

    public function testRejectsMalformedCrops(): void
    {
        $this->assertNull(CropBox::fromArray(['name' => '', 'aspectWidth' => 16, 'aspectHeight' => 9]));
        $this->assertNull(CropBox::fromArray(['name' => 'Bad Name', 'aspectWidth' => 16, 'aspectHeight' => 9]));
        $this->assertNull(CropBox::fromArray(['name' => 'wide', 'aspectWidth' => 0, 'aspectHeight' => 9]));
        $this->assertNull(CropBox::fromArray(['name' => 'wide', 'aspectWidth' => 16, 'aspectHeight' => 0]));
    }

    public function testConfigReadsTheDeclaredCropsAndDropsBadOnes(): void
    {
        $config = CoreConfig::fromArray(['core' => ['media' => ['crops' => [
            ['name' => 'wide', 'aspectWidth' => 16, 'aspectHeight' => 9],
            ['name' => 'portrait', 'aspectWidth' => 3, 'aspectHeight' => 4],
            ['name' => 'has space', 'aspectWidth' => 1, 'aspectHeight' => 1], // non-slug, dropped
            'not-an-object',
            // A duplicate name keeps the first.
            ['name' => 'wide', 'aspectWidth' => 1, 'aspectHeight' => 1],
        ]]]]);

        $crops = $config->mediaCrops();
        $names = array_map(static fn (CropBox $c): string => $c->name, $crops);

        $this->assertSame(['wide', 'portrait'], $names);
        // The first 'wide' won, not the 1:1 duplicate.
        $this->assertSame(16, $crops[0]->aspectWidth);
    }

    public function testConfigDefaultsToNoCrops(): void
    {
        $this->assertSame([], CoreConfig::fromArray([])->mediaCrops());
    }
}
