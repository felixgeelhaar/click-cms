<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Domain\Update\SemanticVersion;
use Click\Cms\Domain\Update\UpdateStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Version comparison, which the auto-updater trusts to decide what supersedes
 * what. Comparing these as strings is how an updater skips a security release,
 * so the ordering is pinned here rather than assumed.
 */
final class SemanticVersionTest extends TestCase
{
    public function testParsesAPlainVersion(): void
    {
        $v = SemanticVersion::fromString('1.4.2');

        $this->assertSame(1, $v->major);
        $this->assertSame(4, $v->minor);
        $this->assertSame(2, $v->patch);
        $this->assertFalse($v->isPreRelease());
    }

    public function testAcceptsALeadingVAsTagsAndReleasesUseIt(): void
    {
        $this->assertSame('2.0.0', SemanticVersion::fromString('v2.0.0')->toString());
    }

    public function testParsesAPreReleaseAndIgnoresBuildMetadata(): void
    {
        $v = SemanticVersion::fromString('1.0.0-beta.2+build.99');

        $this->assertTrue($v->isPreRelease());
        $this->assertSame('beta.2', $v->preRelease);
        $this->assertSame('1.0.0-beta.2', $v->toString());
    }

    public function testRejectsWhatIsNotAVersion(): void
    {
        $this->assertNull(SemanticVersion::tryFromString('latest'));
        $this->assertNull(SemanticVersion::tryFromString('1.2'));
        $this->assertNull(SemanticVersion::tryFromString(''));

        $this->expectException(InvalidArgumentException::class);
        SemanticVersion::fromString('nonsense');
    }

    /**
     * The bug this exists to prevent: 1.10.0 is newer than 1.9.0, though it
     * sorts earlier as text.
     */
    public function testComparesNumericallyNotAlphabetically(): void
    {
        $ten = SemanticVersion::fromString('1.10.0');
        $nine = SemanticVersion::fromString('1.9.0');

        $this->assertTrue($ten->isNewerThan($nine));
        $this->assertFalse($nine->isNewerThan($ten));
    }

    public function testOrdersMajorThenMinorThenPatch(): void
    {
        $this->assertTrue(SemanticVersion::fromString('2.0.0')->isNewerThan(SemanticVersion::fromString('1.99.99')));
        $this->assertTrue(SemanticVersion::fromString('1.3.0')->isNewerThan(SemanticVersion::fromString('1.2.99')));
        $this->assertTrue(SemanticVersion::fromString('1.2.3')->isNewerThan(SemanticVersion::fromString('1.2.2')));
        $this->assertSame(0, SemanticVersion::fromString('1.2.3')->compare(SemanticVersion::fromString('1.2.3')));
    }

    public function testAPreReleaseIsOlderThanItsStableRelease(): void
    {
        $beta = SemanticVersion::fromString('1.0.0-beta.1');
        $stable = SemanticVersion::fromString('1.0.0');

        $this->assertTrue($stable->isNewerThan($beta));
        $this->assertFalse($beta->isNewerThan($stable));
    }

    public function testPreReleasesOrderAmongThemselves(): void
    {
        $this->assertTrue(
            SemanticVersion::fromString('1.0.0-beta.2')->isNewerThan(SemanticVersion::fromString('1.0.0-beta.1'))
        );
        // Numeric identifiers rank below alphanumeric ones, per the spec.
        $this->assertTrue(
            SemanticVersion::fromString('1.0.0-beta')->isNewerThan(SemanticVersion::fromString('1.0.0-2'))
        );
    }

    public function testStepNamesHowBigTheChangeIs(): void
    {
        $current = SemanticVersion::fromString('1.4.2');

        $this->assertSame(UpdateStep::Patch, SemanticVersion::fromString('1.4.3')->stepFrom($current));
        $this->assertSame(UpdateStep::Minor, SemanticVersion::fromString('1.5.0')->stepFrom($current));
        $this->assertSame(UpdateStep::Major, SemanticVersion::fromString('2.0.0')->stepFrom($current));
    }

    public function testAnOlderOrEqualVersionIsNoStepAtAll(): void
    {
        $current = SemanticVersion::fromString('1.4.2');

        $this->assertSame(UpdateStep::None, SemanticVersion::fromString('1.4.2')->stepFrom($current));
        $this->assertSame(UpdateStep::None, SemanticVersion::fromString('1.4.1')->stepFrom($current));
        $this->assertSame(UpdateStep::None, SemanticVersion::fromString('0.9.0')->stepFrom($current));
    }
}
