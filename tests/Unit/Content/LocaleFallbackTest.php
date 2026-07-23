<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Content;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * What happens when a translation does not exist.
 *
 * The behaviour under test is not only "something is served" but "the caller is
 * told which language it got". A silent fallback is indistinguishable, from the
 * outside, from a translation that exists and happens to be identical.
 */
final class LocaleFallbackTest extends TestCase
{
    private string $dir;
    private ContentService $content;
    private PageService $pages;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-fallback-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->content = new ContentService(new JsonStorage($this->dir));
        $this->pages = new PageService(
            $this->content,
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'),
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    private static function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? self::removeTree($entry) : @unlink($entry);
        }

        @rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        return ['username' => 'boss', 'role' => 'admin'];
    }

    public function testATranslationThatExistsIsServedAsItself(): void
    {
        $this->content->save(Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']));

        $resolved = $this->content->resolvePage('home', 'de');

        $this->assertNotNull($resolved);
        $this->assertSame('Startseite', $resolved->content->title());
        $this->assertSame('de', $resolved->served->code);
        $this->assertSame('de', $resolved->requested->code);
        $this->assertFalse($resolved->isFallback());
    }

    public function testAMissingTranslationFallsBackToTheDefaultLanguage(): void
    {
        $this->content->save(Content::create(ContentKey::page('home', 'en'), ['title' => 'Home']));

        $resolved = $this->content->resolvePage('home', 'de');

        $this->assertNotNull($resolved);
        $this->assertSame('Home', $resolved->content->title());
    }

    /**
     * The reason the fallback is a result object rather than just a document:
     * without this a front end cannot set `lang` honestly, and cannot tell an
     * editor that the German page has never been written.
     */
    public function testAFallbackSaysWhichLanguageWasActuallyServed(): void
    {
        $this->content->save(Content::create(ContentKey::page('home', 'en'), ['title' => 'Home']));

        $resolved = $this->content->resolvePage('home', 'de');

        $this->assertSame('de', $resolved->requested->code);
        $this->assertSame('en', $resolved->served->code);
        $this->assertTrue($resolved->isFallback());
        $this->assertSame(
            ['requestedLocale' => 'de', 'locale' => 'en', 'fallback' => true],
            $resolved->toArray()
        );
    }

    public function testADocumentInNoLanguageAtAllIsStillNothing(): void
    {
        $this->assertNull($this->content->resolvePage('nowhere', 'de'));
        $this->assertNull($this->content->resolvePage('nowhere'));
    }

    /**
     * Fallback goes to the default language and stops. Chaining through every
     * configured language would serve a reader whichever translation happened
     * to be written first, which is not a language they asked for either.
     */
    public function testFallbackDoesNotWanderIntoAThirdLanguage(): void
    {
        $this->content->save(Content::create(ContentKey::page('home', 'fr'), ['title' => 'Accueil']));

        $this->assertNull($this->content->resolvePage('home', 'de'));
    }

    public function testTheDefaultLanguageIsConfigurable(): void
    {
        $german = new ContentService(
            new JsonStorage($this->dir, Locale::fromString('de')),
            Locale::fromString('de')
        );
        $german->save(Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']));

        $resolved = $german->resolvePage('home', 'fr');

        $this->assertNotNull($resolved);
        $this->assertSame('de', $resolved->served->code);
        $this->assertTrue($resolved->isFallback());
    }

    /* ---------------------------------------------------- the write path -- */

    /**
     * Reading falls back; editing must not. An editor handed the English page
     * when they asked for the German one writes German into the English
     * document and only finds out when a reader complains.
     */
    public function testEditingDoesNotFallBackToAnotherLanguage(): void
    {
        $this->pages->create(['title' => 'Home'], $this->admin());

        $result = $this->pages->update('home', ['title' => 'Startseite'], $this->admin(), 'de');

        $this->assertSame(404, $result['status']);
        $this->assertSame('Home', $this->content->page('home')?->title());
    }

    public function testCreatingWritesTheLanguageAskedFor(): void
    {
        $result = $this->pages->create(['title' => 'Startseite', 'slug' => 'home'], $this->admin(), 'de');

        $this->assertSame(201, $result['status']);
        $this->assertSame('de', $result['page']->locale()->code);
        $this->assertNull($this->content->page('home', 'en'));
        $this->assertSame('Startseite', $this->content->page('home', 'de')?->title());
    }

    public function testTheLanguageMayTravelInTheBody(): void
    {
        $result = $this->pages->create(['title' => 'Startseite', 'slug' => 'home', 'locale' => 'de'], $this->admin());

        $this->assertSame('de', $result['page']->locale()->code);
        // And is not left lying in the payload as an editable field.
        $this->assertArrayNotHasKey('locale', $result['page']->data);
    }

    /**
     * Translations share an address on purpose: `/kontakt` should be the German
     * `/contact`. A slug already taken in one language must not block another.
     */
    public function testTheSameAddressMayExistInEveryLanguage(): void
    {
        $this->pages->create(['title' => 'Home', 'slug' => 'home'], $this->admin(), 'en');
        $result = $this->pages->create(['title' => 'Startseite', 'slug' => 'home'], $this->admin(), 'de');

        $this->assertSame(201, $result['status']);
    }

    public function testCreatingTheSameAddressTwiceInOneLanguageStillConflicts(): void
    {
        $this->pages->create(['title' => 'Home', 'slug' => 'home'], $this->admin(), 'de');
        $result = $this->pages->create(['title' => 'Again', 'slug' => 'home'], $this->admin(), 'de');

        $this->assertSame(409, $result['status']);
    }

    public function testEditingOneTranslationLeavesTheOtherAlone(): void
    {
        $this->pages->create(['title' => 'Home', 'slug' => 'home'], $this->admin(), 'en');
        $this->pages->create(['title' => 'Startseite', 'slug' => 'home'], $this->admin(), 'de');

        $this->pages->update('home', ['title' => 'Startseite, neu'], $this->admin(), 'de');

        $this->assertSame('Home', $this->content->page('home', 'en')?->title());
        $this->assertSame('Startseite, neu', $this->content->page('home', 'de')?->title());
    }

    public function testDeletingOneTranslationLeavesTheOtherAlone(): void
    {
        $this->pages->create(['title' => 'Home', 'slug' => 'home'], $this->admin(), 'en');
        $this->pages->create(['title' => 'Startseite', 'slug' => 'home'], $this->admin(), 'de');

        $result = $this->pages->delete('home', $this->admin(), 'de');

        $this->assertSame(200, $result['status']);
        $this->assertNull($this->content->page('home', 'de'));
        $this->assertNotNull($this->content->page('home', 'en'));
    }

    public function testAListingShowsOneLanguageAtATime(): void
    {
        $this->pages->create(['title' => 'Home', 'slug' => 'home'], $this->admin(), 'en');
        $this->pages->create(['title' => 'About', 'slug' => 'about'], $this->admin(), 'en');
        $this->pages->create(['title' => 'Startseite', 'slug' => 'home'], $this->admin(), 'de');

        $this->assertCount(2, $this->pages->all('en'));
        $this->assertCount(1, $this->pages->all('de'));
        $this->assertCount(2, $this->pages->all());
    }

    public function testTranslationsOfReportsWhichLanguagesExist(): void
    {
        $this->pages->create(['title' => 'Home', 'slug' => 'home'], $this->admin(), 'en');
        $this->pages->create(['title' => 'Startseite', 'slug' => 'home'], $this->admin(), 'de');

        $codes = array_map(
            static fn (Locale $l): string => $l->code,
            $this->content->translationsOf('page', 'home')
        );
        sort($codes);

        $this->assertSame(['de', 'en'], $codes);
    }

    /* ------------------------------------------------- refusing nonsense -- */

    public function testALocaleThatIsNotALanguageTagIsRefused(): void
    {
        $result = $this->pages->create(['title' => 'X', 'slug' => 'x'], $this->admin(), '../../etc');

        $this->assertSame(400, $result['status']);
        $this->assertNotNull($result['error']);
    }

    /**
     * A typo in a locale would otherwise create a document nothing will ever
     * read, in a directory nobody meant to make, and say so to no one.
     */
    public function testALanguageTheSiteDoesNotPublishInIsRefused(): void
    {
        $restricted = new PageService(
            $this->content,
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'),
            new \Click\Cms\Domain\Schema\SectionValidator(),
            [Locale::fromString('en'), Locale::fromString('de')],
        );

        $result = $restricted->create(['title' => 'X', 'slug' => 'x'], $this->admin(), 'ed');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('ed', (string) $result['error']);
        $this->assertSame(201, $restricted->create(['title' => 'X', 'slug' => 'x'], $this->admin(), 'de')['status']);
    }
}
