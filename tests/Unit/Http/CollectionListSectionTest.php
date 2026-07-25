<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\EntryListings;
use Click\Cms\Application\Collection\EntryRouter;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The listing section — the design that makes a Journal page show the journal.
 *
 * Rendered against the *shipped* `config/sections/collection-list.json` and the
 * shipped collection definitions, so a change to either that breaks the listing
 * fails here rather than on somebody's site.
 *
 * Escaping gets more attention than the layout. An entry's title and summary are
 * editor input that reaches this markup straight from storage, and a listing
 * repeats them for every entry on the page — so one unescaped value is not one
 * stored XSS, it is one per entry.
 */
final class CollectionListSectionTest extends TestCase
{
    private string $base;
    private CollectionService $collections;
    private SectionRenderer $renderer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-listing-section-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o700, true);

        $root = dirname(__DIR__, 3);

        // The shipped collection definitions, unmodified: `post` is routed at
        // `blog`, `team-member` is not routed at all.
        $types = new JsonCollectionTypeRepository($root . '/config/collections');
        Publishable::register(array_map(static fn ($t): string => $t->id, $types->all()));

        $content = new ContentService(new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/versions'),
        ));
        $this->collections = new CollectionService($content, $types, new SectionValidator());

        $this->renderer = new SectionRenderer(
            new JsonSectionTypeRepository($root . '/config/sections'),
            null,
            '/api/media/file',
            null,
            new EntryListings($this->collections, new EntryRouter($types), Locale::default()),
        );
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        self::removeTree($this->base);
    }

    /** @param array<string, mixed> $values */
    private function publish(string $type, array $values): void
    {
        $user = ['username' => 'ed', 'role' => 'admin'];
        $result = $this->collections->create($type, ['values' => $values], $user);
        $this->assertNull($result['error'], json_encode($result['errors']));
        $this->collections->publish($type, $result['entry']->slug(), $user);
    }

    /** @param array<string, mixed> $values */
    private function render(array $values, ?Locale $locale = null): string
    {
        return $this->renderer->render(Content::create(
            ContentKey::page('journal', $locale ?? Locale::default()),
            ['title' => 'Journal', 'sections' => [['type' => 'collection-list', 'values' => $values]]],
        ));
    }

    /* ----------------------------------------------------------------- the job -- */

    public function testEachEntryIsRenderedAsALinkToItsOwnAddress(): void
    {
        $this->publish('post', [
            'title' => 'Why we stopped staining',
            'excerpt' => 'Stain hides the grain it is meant to flatter.',
            'body' => '<p>Prose.</p>',
        ]);

        $html = $this->render(['heading' => 'From the journal', 'collection' => 'post']);

        $this->assertStringContainsString('cms-section--collection-list', $html);
        $this->assertStringContainsString('From the journal', $html);
        $this->assertStringContainsString(
            '<a href="/blog/why-we-stopped-staining">Why we stopped staining</a>',
            $html
        );
        $this->assertStringContainsString('Stain hides the grain it is meant to flatter.', $html);
    }

    public function testTheCoverImageIsRenderedWithTheEntryTitleAsItsDescription(): void
    {
        $this->publish('post', [
            'title' => 'A table that outlives you',
            'coverImage' => 'tables.jpg',
            'body' => '<p>Prose.</p>',
        ]);

        $html = $this->render(['collection' => 'post']);

        $this->assertStringContainsString('<img src="/api/media/file/tables.jpg"', $html);
        $this->assertStringContainsString('alt="A table that outlives you"', $html);
    }

    public function testALimitAndAnOrderAreHonouredThroughTheSection(): void
    {
        $this->publish('post', ['title' => 'Older', 'date' => '2025-03-18', 'body' => '<p>a</p>']);
        $this->publish('post', ['title' => 'Newer', 'date' => '2025-05-02', 'body' => '<p>b</p>']);

        $both = $this->render(['collection' => 'post']);
        $this->assertLessThan(strpos($both, 'Older'), strpos($both, 'Newer'), 'the collection sorts by date, newest first');

        $one = $this->render(['collection' => 'post', 'limit' => 1]);
        $this->assertStringContainsString('Newer', $one);
        $this->assertStringNotContainsString('Older', $one);
    }

    public function testAnUnroutedCollectionIsListedWithoutLinks(): void
    {
        $this->publish('team-member', ['name' => 'Jun Park', 'bio' => 'Joiner.']);

        $html = $this->render(['collection' => 'team-member']);

        $this->assertStringContainsString('Jun Park', $html);
        $this->assertStringContainsString('Joiner.', $html);
        $this->assertStringNotContainsString('<a ', $html, 'there is no address to link to, so there is no link');
    }

    /* --------------------------------------------------------------- silence -- */

    public function testACollectionWithNothingPublishedRendersNoMarkupAtAll(): void
    {
        // Not even the heading: a title over an empty list is what an unpopulated
        // Journal page would otherwise show every visitor.
        $this->assertSame('', $this->render(['heading' => 'From the journal', 'collection' => 'post']));
    }

    public function testADraftEntryNeverReachesTheMarkup(): void
    {
        $user = ['username' => 'ed', 'role' => 'admin'];
        $this->collections->create(
            'post',
            ['values' => ['title' => 'THE UNPUBLISHED HEADLINE', 'body' => '<p>Draft.</p>']],
            $user
        );

        $this->assertStringNotContainsString('THE UNPUBLISHED HEADLINE', $this->render(['collection' => 'post']));
    }

    public function testASectionWithNoListingServiceRendersNothingRatherThanHalfAListing(): void
    {
        $bare = new SectionRenderer(new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'));

        $this->assertSame('', $bare->render(Content::create(
            ContentKey::page('journal'),
            ['sections' => [['type' => 'collection-list', 'values' => ['collection' => 'post']]]],
        )));
    }

    /* -------------------------------------------------------------- escaping -- */

    public function testAnEntryTitleCarryingMarkupIsEscaped(): void
    {
        $this->publish('post', [
            'title' => 'Kitchens <script>alert(1)</script>',
            'body' => '<p>Prose.</p>',
        ]);

        $html = $this->render(['collection' => 'post']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAnEntrySummaryCarryingMarkupIsEscaped(): void
    {
        // An excerpt is plain prose, not HTML. Emitting it raw would make every
        // listing on the site a stored XSS — the summary is why a listing exists.
        $this->publish('post', [
            'title' => 'Benches',
            'excerpt' => '"><img src=x onerror=alert(1)>',
            'body' => '<p>Prose.</p>',
        ]);

        $html = $this->render(['collection' => 'post']);

        // The attribute-breaking quote and the tag are both neutralised; the
        // handler text survives only as inert characters inside the paragraph,
        // which is what escaped means.
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;', $html);
    }

    public function testTheIntroductionIsSanitisedRatherThanEscapedOrTrusted(): void
    {
        $this->publish('post', ['title' => 'Benches', 'body' => '<p>Prose.</p>']);

        $html = $this->render([
            'collection' => 'post',
            'intro' => '<p>Things we have <strong>written</strong>.</p><script>alert(1)</script>',
        ]);

        // Rich text keeps its markup — that is the point of the field — but only
        // the allowlisted part of it.
        $this->assertStringContainsString('<strong>written</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
