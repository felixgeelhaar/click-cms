<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Collection;

use Click\Cms\Application\Collection\EntryRouter;
use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Domain\Collection\CollectionTypeRepository;
use Click\Cms\Domain\ValueObjects\Locale;
use PHPUnit\Framework\TestCase;

/**
 * The mapping between a request path and an entry, in both directions.
 *
 * The reason both live in one class is the reason they are tested together: a
 * listing that spells an address one way while the router reads it another is a
 * site full of links to its own 404s, and nothing notices until a reader clicks.
 */
final class EntryRouterTest extends TestCase
{
    private function router(CollectionType ...$types): EntryRouter
    {
        return new EntryRouter(new class ($types) implements CollectionTypeRepository {
            /** @param list<CollectionType> $types */
            public function __construct(private readonly array $types) {}

            public function all(): array
            {
                return $this->types;
            }

            public function find(string $id): ?CollectionType
            {
                foreach ($this->types as $type) {
                    if ($type->id === $id) {
                        return $type;
                    }
                }

                return null;
            }

            public function errors(): array
            {
                return [];
            }
        });
    }

    private function type(string $id, string $route): CollectionType
    {
        return CollectionType::fromArray([
            'id' => $id,
            'route' => $route,
            'fields' => [['name' => 'title', 'type' => 'text']],
        ]);
    }

    /* ------------------------------------------------------------ matching -- */

    public function testARoutedCollectionClaimsRouteSlashSlug(): void
    {
        $address = $this->router($this->type('post', 'blog'))->match('blog/why-we-stopped-staining');

        $this->assertNotNull($address);
        $this->assertSame('post', $address->type->id);
        $this->assertSame('why-we-stopped-staining', $address->slug);
    }

    public function testACollectionWithNoRouteClaimsNothing(): void
    {
        // Team members are exactly this case, and must stay it: an admin-only
        // collection that gained public pages nobody asked for would be a
        // disclosure decision made by an upgrade.
        $router = $this->router($this->type('team-member', ''));

        $this->assertNull($router->match('team-member/jun-park'));
        $this->assertNull($router->match('team/jun-park'));
        $this->assertSame([], $router->routedTypes());
    }

    /**
     * The route on its own is a *page's* address — the Journal page that carries
     * the listing — and never an entry's. This is what keeps a page whose slug
     * equals a route reachable.
     */
    public function testTheBareRouteIsNotAnEntryAddress(): void
    {
        $router = $this->router($this->type('post', 'blog'));

        $this->assertNull($router->match('blog'));
        $this->assertNull($router->match('blog/'));
        $this->assertNull($router->match(''));
    }

    public function testAPathDeeperThanRoutePlusSlugIsNotAnEntry(): void
    {
        // `blog/2025/x` names no entry. Treating its first segment as one would
        // invent an address the site never declared and serve one entry at many
        // URLs, which is a duplicate-content problem as well as a wrong answer.
        $this->assertNull($this->router($this->type('post', 'blog'))->match('blog/2025/x'));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileSlugs(): iterable
    {
        yield 'traversal' => ['blog/..'];
        yield 'traversal deeper' => ['blog/../../config/core.json'];
        yield 'a dot' => ['blog/.'];
        yield 'uppercase' => ['blog/Secret'];
        yield 'a nul byte' => ["blog/x\0y"];
        yield 'a space' => ['blog/two words'];
        yield 'markup' => ['blog/<script>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileSlugs')]
    public function testASlugThatIsNotSlugShapedIsRefusedBeforeAKeyIsBuilt(string $path): void
    {
        // Refused here rather than trusted to fail further down: a path that
        // reaches storage as a key is a path that has to be safe as a filename.
        $this->assertNull($this->router($this->type('post', 'blog'))->match($path));
    }

    public function testTheLongestMatchingRouteWins(): void
    {
        $router = $this->router(
            $this->type('post', 'journal'),
            $this->type('note', 'journal/notes'),
        );

        $this->assertSame('note', $router->match('journal/notes/first')?->type->id);
        $this->assertSame('post', $router->match('journal/first')?->type->id);
    }

    public function testTwoCollectionsClaimingOneRouteResolveDeterministically(): void
    {
        // No right answer exists, so the requirement is only that the answer does
        // not depend on the day: the first as the repository orders them takes it.
        $router = $this->router($this->type('post', 'blog'), $this->type('note', 'blog'));

        $this->assertSame('post', $router->match('blog/x')?->type->id);
        $this->assertSame('post', $router->match('blog/x')?->type->id);
    }

    public function testALeadingOrTrailingSlashOnThePathIsToleratedNotSignificant(): void
    {
        $router = $this->router($this->type('post', 'blog'));

        $this->assertSame('x', $router->match('/blog/x')?->slug);
        $this->assertSame('x', $router->match('blog/x/')?->slug);
    }

    /* --------------------------------------------------------------- hrefs -- */

    public function testTheDefaultLanguageCarriesNoPrefix(): void
    {
        $type = $this->type('post', 'blog');
        $router = $this->router($type);

        $this->assertSame(
            '/blog/x',
            $router->hrefFor($type, 'x', Locale::fromString('en'), Locale::fromString('en'))
        );
    }

    public function testAnotherLanguageIsPrefixedTheWayPagesAre(): void
    {
        $type = $this->type('post', 'blog');
        $router = $this->router($type);

        $this->assertSame(
            '/de/blog/x',
            $router->hrefFor($type, 'x', Locale::fromString('de'), Locale::fromString('en'))
        );
    }

    public function testAnUnroutedCollectionHasNoHrefAtAll(): void
    {
        // Null rather than a guess: a listing needs to know to render the entry as
        // plain text, and any invented href would be a link to a 404.
        $type = $this->type('team-member', '');

        $this->assertNull(
            $this->router($type)->hrefFor($type, 'jun-park', Locale::default(), Locale::default())
        );
    }

    /**
     * The property the whole design rests on: every href a listing renders is a
     * path the router answers.
     */
    public function testEveryHrefItBuildsIsAPathItMatches(): void
    {
        $type = $this->type('post', 'journal/notes');
        $router = $this->router($type);

        $href = $router->hrefFor($type, 'a-table-that-outlives-you', Locale::default(), Locale::default());
        $this->assertNotNull($href);

        $address = $router->match(trim((string) $href, '/'));
        $this->assertSame('post', $address?->type->id);
        $this->assertSame('a-table-that-outlives-you', $address?->slug);
    }
}
