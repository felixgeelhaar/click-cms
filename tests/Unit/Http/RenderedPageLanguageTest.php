<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The `lang` attribute of a rendered page, and how a public URL names its
 * language.
 *
 * Reached through reflection because rendering is a private detail of the
 * kernel and booting one needs a filesystem, a plugin manager and a session
 * store. What is being pinned down is the output, not the plumbing.
 */
final class RenderedPageLanguageTest extends TestCase
{
    private function application(array $config = []): Application
    {
        $app = new Application(dirname(__DIR__, 3));

        $property = new ReflectionProperty(Application::class, 'config');
        $property->setValue($app, CoreConfig::fromArray($config));

        return $app;
    }

    private function render(Application $app, Content $page, ?Locale $locale = null): string
    {
        $method = new ReflectionMethod(Application::class, 'renderPageHtml');

        return $method->invoke($app, $page, $locale);
    }

    /**
     * @return array{0: string, 1: string} locale code, remaining path
     */
    private function split(Application $app, string $path): array
    {
        $method = new ReflectionMethod(Application::class, 'splitLocaleFromPath');
        [$locale, $rest] = $method->invoke($app, $path);

        return [$locale->code, $rest];
    }

    /* -------------------------------------------------------- lang="..." -- */

    public function testAPageDeclaresItsOwnLanguage(): void
    {
        $page = Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']);

        $this->assertStringContainsString('<html lang="de">', $this->render($this->application(), $page));
    }

    public function testAnEnglishPageStillSaysEnglish(): void
    {
        $page = Content::create(ContentKey::page('home'), ['title' => 'Home']);

        $this->assertStringContainsString('<html lang="en">', $this->render($this->application(), $page));
    }

    /**
     * The language served, not the one requested. A German URL showing English
     * prose because the translation is missing must still say `lang="en"` — a
     * screen reader pronouncing English with German phonemes is unintelligible,
     * and a fallback is exactly the case that produces it.
     */
    public function testAFallbackDeclaresTheLanguageActuallyServed(): void
    {
        $english = Content::create(ContentKey::page('home', 'en'), ['title' => 'Home']);

        $html = $this->render($this->application(), $english, Locale::fromString('en'));

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringNotContainsString('lang="de"', $html);
    }

    public function testARegionalLanguageSurvivesIntoTheAttribute(): void
    {
        $page = Content::create(ContentKey::page('sobre', 'pt-BR'), ['title' => 'Sobre']);

        $this->assertStringContainsString('<html lang="pt-BR">', $this->render($this->application(), $page));
    }

    /* --------------------------------------------------------- public URLs -- */

    public function testALanguagePrefixNamesTheLanguage(): void
    {
        $app = $this->application(['core' => ['languages' => ['default' => 'en', 'available' => ['en', 'de']]]]);

        $this->assertSame(['de', 'kontakt'], $this->split($app, 'de/kontakt'));
        $this->assertSame(['de', ''], $this->split($app, 'de'));
    }

    public function testAnUnprefixedUrlIsTheDefaultLanguage(): void
    {
        $app = $this->application(['core' => ['languages' => ['default' => 'de', 'available' => ['de', 'en']]]]);

        $this->assertSame(['de', 'kontakt'], $this->split($app, 'kontakt'));
    }

    /**
     * Only a *configured* language counts as a prefix, so a page about Germany
     * at `/de` on a monolingual site is still reachable rather than being read
     * as an empty German request.
     */
    public function testASlugThatLooksLikeALanguageIsNotOneUnlessConfigured(): void
    {
        $app = $this->application(['core' => ['languages' => ['default' => 'en', 'available' => ['en']]]]);

        $this->assertSame(['en', 'de'], $this->split($app, 'de'));
        $this->assertSame(['en', 'fr/vins'], $this->split($app, 'fr/vins'));
    }
}
