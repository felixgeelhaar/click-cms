<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * The language dimension of flat-file storage, and the upgrade path from the
 * layout that had none.
 */
final class JsonStorageLocaleTest extends TestCase
{
    private string $dir;
    private JsonStorage $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-locale-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->storage = new JsonStorage($this->dir);
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

    /**
     * Write a document in the pre-languages layout, exactly as a site upgrading
     * from an earlier version has it on disk.
     */
    private function writeLegacyDocument(string $type, string $slug, array $data): void
    {
        @mkdir($this->dir . '/' . $type, 0o775, true);

        file_put_contents(
            $this->dir . '/' . $type . '/' . $slug . '.json',
            json_encode([
                'key' => $type . ':' . $slug,
                'type' => $type,
                'slug' => $slug,
                'data' => $data,
                'createdAt' => '2024-01-01T00:00:00+00:00',
                'updatedAt' => '2024-01-01T00:00:00+00:00',
            ], JSON_PRETTY_PRINT)
        );
    }

    /* ------------------------------------------------- the new layout ----- */

    public function testDocumentsAreStoredUnderTheirLanguage(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']));

        $this->assertFileExists($this->dir . '/page/de/home.json');
    }

    public function testTwoLanguagesOfOnePageAreTwoDocuments(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home', 'en'), ['title' => 'Home']));
        $this->storage->save(Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']));

        $this->assertSame('Home', $this->storage->find(ContentKey::page('home', 'en'))?->title());
        $this->assertSame('Startseite', $this->storage->find(ContentKey::page('home', 'de'))?->title());
    }

    /**
     * Storage does not fall back. Fallback is a policy decision and lives one
     * layer up; a backend that quietly substituted another language would make
     * "does this translation exist?" unanswerable.
     */
    public function testAMissingTranslationIsSimplyAbsent(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home', 'en'), ['title' => 'Home']));

        $this->assertNull($this->storage->find(ContentKey::page('home', 'fr')));
        $this->assertFalse($this->storage->exists(ContentKey::page('home', 'fr')));
    }

    public function testDeletingOneTranslationLeavesTheOthersStanding(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home', 'en')));
        $this->storage->save(Content::create(ContentKey::page('home', 'de')));

        $this->assertTrue($this->storage->delete(ContentKey::page('home', 'de')));
        $this->assertNull($this->storage->find(ContentKey::page('home', 'de')));
        $this->assertNotNull($this->storage->find(ContentKey::page('home', 'en')));
    }

    public function testFindByTypeCanBeNarrowedToOneLanguage(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home', 'en')));
        $this->storage->save(Content::create(ContentKey::page('about', 'en')));
        $this->storage->save(Content::create(ContentKey::page('home', 'de')));

        $german = $this->storage->findByType('page', Locale::fromString('de'));

        $this->assertCount(1, $german);
        $this->assertSame('home', $german[0]->slug());
        $this->assertSame('de', $german[0]->locale()->code);
    }

    public function testFindByTypeWithoutALanguageReturnsEveryTranslation(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home', 'en')));
        $this->storage->save(Content::create(ContentKey::page('home', 'de')));

        $this->assertCount(2, $this->storage->findByType('page'));
    }

    /**
     * A stray directory under a type — anything a plugin has put there — must
     * not be reported as a language nobody configured.
     */
    public function testADirectoryThatIsNotALanguageIsIgnored(): void
    {
        mkdir($this->dir . '/page/variants', 0o775, true);
        file_put_contents($this->dir . '/page/variants/thing.json', '{}');

        $this->assertSame([], $this->storage->findByType('page'));
    }

    /* --------------------------------------------- the old layout ---------- */

    /**
     * The whole point of the compatibility path: a site with content written
     * before languages existed must still serve it after upgrading.
     */
    public function testADocumentInTheOldLayoutStillLoads(): void
    {
        $this->writeLegacyDocument('page', 'home', ['title' => 'Home', 'content' => 'Hello']);

        $found = $this->storage->find(ContentKey::page('home'));

        $this->assertNotNull($found);
        $this->assertSame('Home', $found->title());
        $this->assertSame('Hello', $found->content());
        $this->assertSame('en', $found->locale()->code);
        $this->assertTrue($this->storage->exists(ContentKey::page('home')));
    }

    public function testADocumentInTheOldLayoutAppearsInListings(): void
    {
        $this->writeLegacyDocument('page', 'home', ['title' => 'Home']);
        $this->writeLegacyDocument('page', 'about', ['title' => 'About']);

        $slugs = array_map(
            static fn (Content $c): string => $c->slug(),
            $this->storage->findByType('page')
        );

        $this->assertSame(['about', 'home'], $slugs);
    }

    /**
     * The site's configured default decides what the old files are in. A German
     * site's `content/page/home.json` is German, whatever the key inside it says
     * — the file predates anything being able to say otherwise.
     */
    public function testTheOldLayoutBelongsToTheConfiguredDefaultLanguage(): void
    {
        $german = new JsonStorage($this->dir, Locale::fromString('de'));
        $this->writeLegacyDocument('page', 'home', ['title' => 'Startseite']);

        $found = $german->find(ContentKey::page('home', 'de'));

        $this->assertNotNull($found);
        $this->assertSame('de', $found->locale()->code);
        $this->assertSame('page:de:home', $found->key->toString());
    }

    /**
     * An untranslated document must not answer for a language it was never
     * written in. Serving `content/page/home.json` for a French request would
     * hand a reader English prose labelled French.
     */
    public function testTheOldLayoutDoesNotAnswerForOtherLanguages(): void
    {
        $this->writeLegacyDocument('page', 'home', ['title' => 'Home']);

        $this->assertNull($this->storage->find(ContentKey::page('home', 'de')));
    }

    public function testSavingMigratesAnOldDocumentToTheNewLayout(): void
    {
        $this->writeLegacyDocument('page', 'home', ['title' => 'Home']);

        $page = $this->storage->find(ContentKey::page('home'));
        $page->update(['title' => 'Home, edited']);
        $this->storage->save($page);

        $this->assertFileExists($this->dir . '/page/en/home.json');
        $this->assertFileDoesNotExist($this->dir . '/page/home.json');
        $this->assertSame('Home, edited', $this->storage->find(ContentKey::page('home'))?->title());
    }

    /**
     * Half-migrated content is the state a site is in between the upgrade and
     * the next edit of each page. A listing must not show the same page twice.
     */
    public function testAPageIsListedOnceWhenBothLayoutsHoldACopy(): void
    {
        $this->writeLegacyDocument('page', 'home', ['title' => 'Stale']);
        @mkdir($this->dir . '/page/en', 0o775, true);
        file_put_contents(
            $this->dir . '/page/en/home.json',
            json_encode(['key' => 'page:en:home', 'data' => ['title' => 'Current']])
        );

        $pages = $this->storage->findByType('page');

        $this->assertCount(1, $pages);
        $this->assertSame('Current', $pages[0]->title());
    }

    public function testDeletingRemovesTheOldFileToo(): void
    {
        $this->writeLegacyDocument('page', 'home', ['title' => 'Home']);

        $this->assertTrue($this->storage->delete(ContentKey::page('home')));
        $this->assertFileDoesNotExist($this->dir . '/page/home.json');
        $this->assertNull($this->storage->find(ContentKey::page('home')));
    }
}
