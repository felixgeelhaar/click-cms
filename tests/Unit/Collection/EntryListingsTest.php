<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Collection;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\EntryListings;
use Click\Cms\Application\Collection\EntryRouter;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * What a listing section is given to show.
 *
 * The rule with teeth is the first test: a listing reads published entries only.
 * A draft appearing in a listing is unpublished work shown to every visitor — and
 * unlike a draft served at its own address, nobody would need a URL to find it.
 */
final class EntryListingsTest extends TestCase
{
    private string $base;
    private CollectionService $collections;
    private ContentService $content;
    private EntryListings $listings;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-listings-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/collections', 0o700, true);

        // A routed collection with a summary and a picture, and an unrouted one —
        // the two shapes the shipped site has.
        file_put_contents($this->base . '/collections/post.json', json_encode([
            'label' => 'Posts',
            'titleField' => 'title',
            'route' => 'blog',
            'sort' => ['field' => 'date', 'direction' => 'desc'],
            'fields' => [
                ['name' => 'title', 'type' => 'text', 'required' => true],
                ['name' => 'date', 'type' => 'date'],
                ['name' => 'excerpt', 'type' => 'textarea'],
                ['name' => 'coverImage', 'type' => 'image'],
                ['name' => 'body', 'type' => 'richtext'],
            ],
        ]));
        file_put_contents($this->base . '/collections/team-member.json', json_encode([
            'label' => 'Team',
            'titleField' => 'name',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'required' => true],
                ['name' => 'photo', 'type' => 'image'],
                ['name' => 'bio', 'type' => 'textarea'],
            ],
        ]));

        Publishable::register(['post', 'team-member']);

        $types = new JsonCollectionTypeRepository($this->base . '/collections');
        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/versions'),
        ));
        $this->collections = new CollectionService($this->content, $types, new SectionValidator());
        $this->listings = new EntryListings($this->collections, new EntryRouter($types), Locale::default());
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        self::removeTree($this->base);
    }

    /** @param array<string, mixed> $values */
    private function entry(string $type, array $values, bool $publish = true): void
    {
        $result = $this->collections->create($type, ['values' => $values], ['username' => 'ed', 'role' => 'admin']);
        $this->assertNull($result['error'], json_encode($result['errors']));

        if ($publish) {
            $this->collections->publish($type, $result['entry']->slug(), ['username' => 'ed', 'role' => 'admin']);
        }
    }

    /** @param array<string, mixed> $values */
    private function cards(array $values): array
    {
        return $this->listings->forSection($values, Locale::default());
    }

    /* ----------------------------------------------------------- publication -- */

    public function testADraftEntryIsNotListed(): void
    {
        $this->entry('post', ['title' => 'Published', 'date' => '2025-01-01']);
        $this->entry('post', ['title' => 'Still a draft', 'date' => '2025-06-01'], publish: false);

        $titles = array_column($this->cards(['collection' => 'post']), 'title');

        $this->assertSame(['Published'], $titles);
    }

    public function testAnUnpublishedEntryLeavesTheListing(): void
    {
        $this->entry('post', ['title' => 'Here for now']);
        $this->assertCount(1, $this->cards(['collection' => 'post']));

        $this->collections->unpublish('post', 'here-for-now', ['username' => 'ed', 'role' => 'admin']);

        $this->assertSame([], $this->cards(['collection' => 'post']));
    }

    /* ---------------------------------------------------------------- shape -- */

    public function testACardCarriesTitleSummaryPictureAndAddress(): void
    {
        $this->entry('post', [
            'title' => 'Why we stopped staining',
            'excerpt' => 'Stain hides the grain it is meant to flatter.',
            'coverImage' => 'media-workshop',
            'body' => '<p>Prose.</p>',
        ]);

        $this->assertSame([[
            'title' => 'Why we stopped staining',
            // The summary and the picture are read off the schema — first textarea,
            // first image — so a site gets a working listing from the field types it
            // was going to declare anyway.
            'excerpt' => 'Stain hides the grain it is meant to flatter.',
            'image' => 'media-workshop',
            'href' => '/blog/why-we-stopped-staining',
        ]], $this->cards(['collection' => 'post']));
    }

    public function testAnUnroutedCollectionListsEntriesWithNoAddress(): void
    {
        $this->entry('team-member', ['name' => 'Jun Park', 'bio' => 'Joiner.', 'photo' => 'media-jun']);

        $card = $this->cards(['collection' => 'team-member'])[0];

        $this->assertSame('Jun Park', $card['title']);
        $this->assertSame('Joiner.', $card['excerpt'], 'the bio is this type\'s first textarea');
        $this->assertNull($card['href'], 'team members have no public address, so there is nothing to link to');
    }

    public function testMissingSummaryAndPictureAreEmptyRatherThanAbsent(): void
    {
        $this->entry('post', ['title' => 'Bare']);

        $card = $this->cards(['collection' => 'post'])[0];

        $this->assertSame('', $card['excerpt']);
        $this->assertSame('', $card['image']);
    }

    /* ------------------------------------------------------ limit and order -- */

    public function testTheDefaultOrderIsTheOneTheCollectionDeclares(): void
    {
        $this->entry('post', ['title' => 'Older', 'date' => '2025-03-18']);
        $this->entry('post', ['title' => 'Newer', 'date' => '2025-05-02']);

        // The collection sorts by date descending, and the editor's list, the
        // delivery API and the page must not disagree about what comes first.
        $this->assertSame(['Newer', 'Older'], array_column($this->cards(['collection' => 'post']), 'title'));
        $this->assertSame(
            ['Newer', 'Older'],
            array_column($this->cards(['collection' => 'post', 'sort' => 'collection']), 'title')
        );
    }

    public function testAlphabeticalOrderIsAvailable(): void
    {
        $this->entry('post', ['title' => 'Beta', 'date' => '2025-05-02']);
        $this->entry('post', ['title' => 'alpha', 'date' => '2025-03-18']);

        $this->assertSame(
            ['alpha', 'Beta'],
            array_column($this->cards(['collection' => 'post', 'sort' => 'title']), 'title')
        );
    }

    public function testEditOrderIsAvailableInBothDirections(): void
    {
        // Written straight through storage with explicit timestamps: two entries
        // created in the same second are genuinely equally recent, and a test that
        // relied on creation order to distinguish them would be asserting the
        // sort's tie-break rather than the sort.
        $this->timestampedEntry('older', 'Older edit', '2024-01-01T00:00:00+00:00');
        $this->timestampedEntry('newer', 'Newer edit', '2025-09-09T00:00:00+00:00');

        $this->assertSame(
            ['Newer edit', 'Older edit'],
            array_column($this->cards(['collection' => 'post', 'sort' => 'newest']), 'title')
        );
        $this->assertSame(
            ['Older edit', 'Newer edit'],
            array_column($this->cards(['collection' => 'post', 'sort' => 'oldest']), 'title')
        );
    }

    private function timestampedEntry(string $slug, string $title, string $updatedAt): void
    {
        $key = \Click\Cms\Domain\ValueObjects\ContentKey::for('post', $slug);

        $this->content->save(\Click\Cms\Domain\Content\Content::create($key, [
            'title' => $title,
            'slug' => $slug,
            'updatedAt' => $updatedAt,
        ]));
        $this->content->publish($key);
    }

    public function testTheLimitIsHonoured(): void
    {
        foreach (['a', 'b', 'c'] as $i => $title) {
            $this->entry('post', ['title' => $title, 'date' => '2025-01-0' . ($i + 1)]);
        }

        $this->assertCount(2, $this->cards(['collection' => 'post', 'limit' => 2]));
        $this->assertCount(3, $this->cards(['collection' => 'post', 'limit' => 99]), 'the cap trims, it does not empty');
        $this->assertCount(3, $this->cards(['collection' => 'post']));
    }

    public function testAnUnusableLimitFallsBackRatherThanShowingNothing(): void
    {
        foreach (range(1, 8) as $n) {
            $this->entry('post', ['title' => 'post ' . $n]);
        }

        // Zero, negative and nonsense all mean "the editor did not say", and the
        // answer to that is the default — not an empty section on a live page.
        $this->assertCount(6, $this->cards(['collection' => 'post', 'limit' => 0]));
        $this->assertCount(6, $this->cards(['collection' => 'post', 'limit' => -5]));
        $this->assertCount(6, $this->cards(['collection' => 'post', 'limit' => 'lots']));
    }

    /* -------------------------------------------------------- misconfigured -- */

    public function testASectionNamingNoCollectionShowsNothing(): void
    {
        $this->entry('post', ['title' => 'Here']);

        $this->assertSame([], $this->cards([]));
        $this->assertSame([], $this->cards(['collection' => '']));
        $this->assertSame([], $this->cards(['collection' => 'products-we-never-declared']));
        $this->assertSame([], $this->cards(['collection' => ['post']]));
    }

    public function testEntriesInAnotherLanguageAreNotMixedIn(): void
    {
        $this->entry('post', ['title' => 'English post']);

        // Asking in German lists German entries — of which there are none. A
        // listing that fell back per entry would mix languages in one list, which
        // reads as a bug to every visitor.
        $this->assertSame([], $this->listings->forSection(['collection' => 'post'], Locale::fromString('de')));
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
