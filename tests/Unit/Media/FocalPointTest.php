<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Domain\Media\FocalPoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FocalPointTest extends TestCase
{
    /**
     * An image with no focal point marked is not a special case: the middle is
     * the honest default, and it is what a browser does with object-position
     * anyway.
     */
    public function testDefaultsToTheCentre(): void
    {
        $point = FocalPoint::center();

        $this->assertSame(0.5, $point->x);
        $this->assertSame(0.5, $point->y);
    }

    public function testHoldsACoordinateWithinRange(): void
    {
        $point = FocalPoint::at(0.25, 0.75);

        $this->assertSame(0.25, $point->x);
        $this->assertSame(0.75, $point->y);
    }

    /** The corners are valid — a subject can sit hard against an edge. */
    public function testAcceptsTheBounds(): void
    {
        $this->assertSame(0.0, FocalPoint::at(0.0, 0.0)->x);
        $this->assertSame(1.0, FocalPoint::at(1.0, 1.0)->y);
    }

    /**
     * A fraction outside 0..1 is not a point on the image, so it is refused
     * rather than clamped — a caller that computed one has a bug to hear about.
     */
    #[DataProvider('outOfRange')]
    public function testRejectsAValueOutsideTheImage(float $x, float $y): void
    {
        $this->expectException(InvalidArgumentException::class);
        FocalPoint::at($x, $y);
    }

    /** @return array<string, array{float, float}> */
    public static function outOfRange(): array
    {
        return [
            'x below zero'  => [-0.01, 0.5],
            'x above one'   => [1.5, 0.5],
            'y below zero'  => [0.5, -1.0],
            'y above one'   => [0.5, 1.01],
        ];
    }

    /**
     * The whole reason this value exists: a front end that crops an image with
     * object-fit can keep the subject visible by honouring object-position.
     */
    public function testExpressesItselfAsAnObjectPositionValue(): void
    {
        $this->assertSame('50% 50%', FocalPoint::center()->toCss());
        $this->assertSame('25% 75%', FocalPoint::at(0.25, 0.75)->toCss());
        $this->assertSame('0% 100%', FocalPoint::at(0.0, 1.0)->toCss());
    }

    /** Fractions that are not round percentages must not print noise like "33.30%". */
    public function testObjectPositionTrimsTrailingZeros(): void
    {
        $this->assertSame('33.3% 66.7%', FocalPoint::at(0.333, 0.667)->toCss());
    }

    public function testRoundTripsThroughArray(): void
    {
        $restored = FocalPoint::fromArray(FocalPoint::at(0.2, 0.8)->toArray());

        $this->assertSame(0.2, $restored->x);
        $this->assertSame(0.8, $restored->y);
    }

    /** A record written before focal points existed reads back as centred. */
    public function testMissingDataReadsAsTheCentre(): void
    {
        $point = FocalPoint::fromArray([]);

        $this->assertTrue($point->isCenter());
        $this->assertSame('50% 50%', $point->toCss());
    }
}
