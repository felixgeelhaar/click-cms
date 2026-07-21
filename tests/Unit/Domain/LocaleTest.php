<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\ValueObjects\Locale;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    public function testAcceptsAPlainLanguage(): void
    {
        $this->assertSame('en', Locale::fromString('en')->code);
        $this->assertSame('deu', Locale::fromString('deu')->code);
    }

    public function testAcceptsALanguageWithARegion(): void
    {
        $this->assertSame('pt-BR', Locale::fromString('pt-BR')->code);
    }

    /**
     * The same language spelt two ways would otherwise become two documents,
     * and the second one would be invisible to whoever wrote the first.
     */
    public function testNormalisesCaseSoOneLanguageIsOneDocument(): void
    {
        $this->assertSame('en', Locale::fromString('EN')->code);
        $this->assertSame('pt-BR', Locale::fromString('pt-br')->code);
        $this->assertSame('pt-BR', Locale::fromString('PT-BR')->code);
        $this->assertTrue(Locale::fromString('DE')->equals(Locale::fromString('de')));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('de', Locale::fromString('  de  ')->code);
    }

    /**
     * A locale becomes a directory name, so anything that could climb out of
     * the content directory has to be refused here rather than downstream.
     */
    public function testRefusesAnythingThatIsNotALanguageTag(): void
    {
        foreach (['', '..', '../../etc', 'e', 'en_US', 'en/de', 'en"', '1234', 'toolongsubtag-x'] as $bad) {
            $this->assertNull(Locale::tryFromString($bad), "\"{$bad}\" should not parse");
        }
    }

    public function testFromStringThrowsWhereTryFromStringReturnsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Locale::fromString('../escape');
    }

    public function testTryFromStringHandlesNull(): void
    {
        $this->assertNull(Locale::tryFromString(null));
    }

    public function testDefaultIsEnglish(): void
    {
        $this->assertSame('en', Locale::default()->code);
        $this->assertSame('en', (string) Locale::default());
    }
}
