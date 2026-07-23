<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The search delivery plugin, exercised as itself.
 *
 * These tests pin the one boundary that makes it safe to answer anonymously,
 * the same boundary the GraphQL plugin was rewritten to hold: search reads
 * PUBLISHED pages only. A search endpoint that surfaced an unpublished draft
 * would leak an editor's unannounced work to the public — exactly the class of
 * bug (the GraphQL account/password leak) this project already fixed — so a
 * draft appearing in results is a security regression, not a ranking quirk.
 */
final class SearchDeliveryTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private object $plugin;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-search-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        );
        $this->content = new ContentService($storage);

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService($this->content);

        require_once dirname(__DIR__, 3) . '/plugins/search/bootstrap.php';
        $this->plugin = new \Plugin_search_api($manager);

        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /**
     * Save then publish, so the page lands in `content/` — the same source the
     * public site renders from, and the only source search is allowed to read.
     */
    private function seedPublished(string $slug, array $data, ?string $locale = null): void
    {
        $key = ContentKey::page($slug, $locale);
        $this->content->save(Content::create($key, $data));
        $storage = (new \ReflectionProperty($this->content, 'storage'))->getValue($this->content);
        $storage->publish($key);
    }

    private function search(string $q, ?string $locale = null): array
    {
        $_GET['q'] = $q;
        if ($locale !== null) {
            $_GET['locale'] = $locale;
        }
        return $this->plugin->handleSearch();
    }

    private function slugs(array $result): array
    {
        return array_column($result['results'], 'slug');
    }

    /* ------------------------------------------------- matching content -- */

    public function testMatchesByTitle(): void
    {
        $this->seedPublished('about', ['title' => 'About our company', 'sections' => []]);
        $this->seedPublished('contact', ['title' => 'Contact us', 'sections' => []]);

        $result = $this->search('company');

        $this->assertSame(['about'], $this->slugs($result));
    }

    public function testMatchesByBodyText(): void
    {
        $this->seedPublished('mission', [
            'title' => 'Home',
            'sections' => [
                ['type' => 'rich-text', 'values' => ['body' => '<p>We build sustainable turbines.</p>']],
            ],
        ]);

        $result = $this->search('turbines');

        $this->assertSame(['mission'], $this->slugs($result));
    }

    public function testMatchesTextInsideRepeaterRows(): void
    {
        // Card titles and body live one level deeper, inside a repeater's rows.
        $this->seedPublished('services', [
            'title' => 'Services',
            'sections' => [
                ['type' => 'card-grid', 'values' => [
                    'heading' => 'What we do',
                    'cards' => [
                        ['title' => 'Maintenance', 'body' => 'Scheduled inspections.'],
                        ['title' => 'Repair', 'body' => 'Emergency turbine repair.'],
                    ],
                ]],
            ],
        ]);

        $this->assertSame(['services'], $this->slugs($this->search('inspections')));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->seedPublished('about', ['title' => 'About our COMPANY', 'sections' => []]);

        $result = $this->search('company');

        $this->assertSame(['about'], $this->slugs($result));
    }

    /* ------------------------------------------------------ empty query -- */

    public function testEmptyQueryReturnsNothing(): void
    {
        $this->seedPublished('about', ['title' => 'About', 'sections' => []]);

        $this->assertSame([], $this->search('')['results']);
        $this->assertSame([], $this->search('   ')['results']);
    }

    public function testMissingQueryReturnsNothing(): void
    {
        $this->seedPublished('about', ['title' => 'About', 'sections' => []]);

        // No `q` at all — must not fall through to "return everything".
        $result = $this->plugin->handleSearch();

        $this->assertSame([], $result['results']);
    }

    /* ----------------------------------------------- published only -- */

    public function testAnUnpublishedDraftIsNotReturned(): void
    {
        // Saved but never published: a working copy the public must not see.
        $this->content->save(Content::create(
            ContentKey::page('secret'),
            ['title' => 'Secret unannounced product', 'sections' => []]
        ));

        $result = $this->search('unannounced');

        $this->assertSame([], $result['results']);
    }

    /* ---------------------------------------------- excerpt is plain text -- */

    public function testRichTextHtmlDoesNotLeakIntoExcerptAsMarkup(): void
    {
        $this->seedPublished('home', [
            'title' => 'Home',
            'sections' => [
                ['type' => 'rich-text', 'values' => [
                    'body' => '<p>Our <strong>mission</strong> is <a href="/x">clear</a>.</p>',
                ]],
            ],
        ]);

        $result = $this->search('mission');
        $excerpt = $result['results'][0]['excerpt'];

        $this->assertStringContainsString('mission', $excerpt);
        $this->assertStringNotContainsString('<strong>', $excerpt);
        $this->assertStringNotContainsString('<a', $excerpt);
        $this->assertStringNotContainsString('<p>', $excerpt);
    }

    /* --------------------------------------------------------- ranking -- */

    public function testTitleMatchRanksAboveBodyMatch(): void
    {
        // 'gearbox' in the body here...
        $this->seedPublished('parts', [
            'title' => 'Parts catalogue',
            'sections' => [
                ['type' => 'rich-text', 'values' => ['body' => '<p>Includes the gearbox.</p>']],
            ],
        ]);
        // ...and in the title here. The title hit must come first.
        $this->seedPublished('gearbox', ['title' => 'Gearbox overview', 'sections' => []]);

        $result = $this->search('gearbox');

        $this->assertSame(['gearbox', 'parts'], $this->slugs($result));
    }

    /* ---------------------------------------------------------- locale -- */

    public function testRespectsRequestedLocale(): void
    {
        $this->seedPublished('home', ['title' => 'Wind energy', 'sections' => []], 'en');
        $this->seedPublished('home', ['title' => 'Windenergie', 'sections' => []], 'de');

        $en = $this->search('energy', 'en');
        $de = $this->search('Windenergie', 'de');

        $this->assertSame(['home'], $this->slugs($en));
        $this->assertSame('en', $en['results'][0]['locale']);

        $this->assertSame(['home'], $this->slugs($de));
        $this->assertSame('de', $de['results'][0]['locale']);
        // The English page must not answer a German-scoped search.
        $this->assertSame([], $this->search('energy', 'de')['results']);
    }
}
