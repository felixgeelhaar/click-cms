<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentKeyTest extends TestCase
{
    public function testAKeyWithNoLocaleUsesTheDefault(): void
    {
        $key = ContentKey::page('home');

        $this->assertSame('page', $key->type);
        $this->assertSame('home', $key->slug);
        $this->assertSame('en', $key->locale->code);
        $this->assertSame('page:en:home', $key->toString());
    }

    public function testAnExplicitLocaleIsPartOfTheKey(): void
    {
        $key = ContentKey::page('home', 'de');

        $this->assertSame('de', $key->locale->code);
        $this->assertSame('page:de:home', (string) $key);
    }

    public function testAcceptsALocaleObject(): void
    {
        $key = ContentKey::page('home', Locale::fromString('fr'));

        $this->assertSame('page:fr:home', $key->toString());
    }

    /**
     * Keys written before languages existed are still on disk and in stored
     * documents. Refusing to parse them would orphan every one of them.
     */
    public function testTheTwoPartFormStillParsesAsTheDefaultLocale(): void
    {
        $key = ContentKey::fromString('page:home');

        $this->assertSame('page', $key->type);
        $this->assertSame('home', $key->slug);
        $this->assertSame('en', $key->locale->code);
    }

    public function testTheThreePartFormCarriesItsLocale(): void
    {
        $key = ContentKey::fromString('page:de:kontakt');

        $this->assertSame('page', $key->type);
        $this->assertSame('de', $key->locale->code);
        $this->assertSame('kontakt', $key->slug);
    }

    public function testTheStringFormRoundTrips(): void
    {
        foreach (['page:en:home', 'page:pt-BR:sobre', 'user:en:admin'] as $raw) {
            $this->assertSame($raw, ContentKey::fromString($raw)->toString());
        }
    }

    public function testWithLocaleNamesTheSameDocumentInAnotherLanguage(): void
    {
        $key = ContentKey::page('home')->withLocale('de');

        $this->assertSame('page:de:home', $key->toString());
        $this->assertSame('home', $key->slug);
    }

    public function testRejectsAMalformedKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContentKey::fromString('nocolon');
    }

    public function testRejectsAMiddleSegmentThatIsNotALanguage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContentKey::fromString('page:../..:home');
    }

    public function testRejectsAnEmptyTypeOrSlug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContentKey::fromString('page:de:');
    }
}
