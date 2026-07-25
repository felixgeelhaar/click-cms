<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a collection type's `route` may say, and what it means when it says
 * nothing.
 *
 * The route is where a collection's entries live on the public site, and it is
 * declared per site rather than derived from the id — core owns the structure, a
 * site owns its naming. Two properties matter enough to pin down here: a
 * collection that declares no route stays admin-only (which is every collection
 * written before routes existed, and the shipped team members by intent), and a
 * route that cannot work is refused loudly rather than accepted and quietly
 * ignored.
 */
final class CollectionRouteTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function type(array $extra = []): CollectionType
    {
        return CollectionType::fromArray(array_merge([
            'id' => 'post',
            'label' => 'Posts',
            'titleField' => 'title',
            'fields' => [['name' => 'title', 'type' => 'text', 'label' => 'Title']],
        ], $extra));
    }

    /* ---------------------------------------------------------- opting in -- */

    public function testACollectionWithNoRouteHasNoPublicAddress(): void
    {
        $type = $this->type();

        $this->assertSame('', $type->route);
        $this->assertFalse($type->hasPublicAddress());
        $this->assertNull($type->pathFor('anything'));
    }

    public function testADeclaredRouteBecomesTheEntrysPathPrefix(): void
    {
        $type = $this->type(['route' => 'blog']);

        $this->assertTrue($type->hasPublicAddress());
        $this->assertSame('blog/why-we-stopped-staining', $type->pathFor('why-we-stopped-staining'));
    }

    public function testSurroundingSlashesAreTheSameIntent(): void
    {
        foreach (['/blog', 'blog/', '/blog/', ' blog '] as $written) {
            $this->assertSame('blog', $this->type(['route' => $written])->route, $written);
        }
    }

    public function testARouteMayBeSeveralSegmentsDeep(): void
    {
        $this->assertSame('journal/notes', $this->type(['route' => 'journal/notes'])->route);
    }

    public function testAnEmptyRouteIsTheSameAsNone(): void
    {
        $this->assertSame('', $this->type(['route' => ''])->route);
        $this->assertSame('', $this->type(['route' => '/'])->route);
    }

    public function testTheRouteIsReportedToClients(): void
    {
        // The admin has no other way to tell "no public address" from "this build
        // predates routes", and a "view on the site" link needs the answer.
        $this->assertSame('blog', $this->type(['route' => 'blog'])->toArray()['route']);
        $this->assertSame('', $this->type()->toArray()['route']);
    }

    /* --------------------------------------------------------- refusals --- */

    /** @return iterable<string, array{string}> */
    public static function unusableRoutes(): iterable
    {
        yield 'uppercase' => ['Blog'];
        yield 'a space' => ['my blog'];
        yield 'traversal' => ['..'];
        yield 'traversal in a segment' => ['blog/../secrets'];
        yield 'a dot segment' => ['blog/./x'];
        yield 'an empty segment' => ['blog//x'];
        yield 'a query string' => ['blog?x=1'];
        yield 'an underscore' => ['my_blog'];
        yield 'a full url' => ['https://example.com/blog'];
    }

    #[DataProvider('unusableRoutes')]
    public function testAnUnusableRouteIsRefused(string $route): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->type(['route' => $route]);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedRoutes(): iterable
    {
        // Every prefix the kernel answers before it ever looks at public content.
        yield 'the api' => ['api'];
        yield 'the admin' => ['admin'];
        yield 'previews' => ['preview'];
        yield 'health checks' => ['health'];
        yield 'a path under one of them' => ['api/posts'];
    }

    #[DataProvider('reservedRoutes')]
    public function testARouteTheApplicationItselfAnswersIsRefused(string $route): void
    {
        // Accepting these would hand a site a collection it believes is public and
        // a set of addresses that all 404 — the failure nobody thinks to test.
        $this->expectException(InvalidArgumentException::class);

        $this->type(['route' => $route]);
    }

    public function testARouteThatIsNotEvenAStringIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->type(['route' => ['blog']]);
    }

    /**
     * A bad route must not be able to take the admin down, which is the whole
     * reason the loader collects errors rather than letting them out.
     */
    public function testAMalformedRouteIsReportedAgainstItsFileRatherThanThrown(): void
    {
        $dir = sys_get_temp_dir() . '/click-cms-route-errors-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o775, true);

        file_put_contents($dir . '/good.json', json_encode([
            'label' => 'Good',
            'route' => 'blog',
            'fields' => [['name' => 'title', 'type' => 'text']],
        ]));
        file_put_contents($dir . '/bad.json', json_encode([
            'label' => 'Bad',
            'route' => 'ADMIN PANEL',
            'fields' => [['name' => 'title', 'type' => 'text']],
        ]));

        $repo = new JsonCollectionTypeRepository($dir);

        $this->assertArrayHasKey('bad.json', $repo->errors());
        $this->assertNotNull($repo->find('good'), 'one bad definition must not take the others with it');
        $this->assertNull($repo->find('bad'));

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    /* ----------------------------------------------------- what ships ----- */

    public function testTheShippedCollectionsOptInAsDocumented(): void
    {
        $repo = new JsonCollectionTypeRepository(dirname(__DIR__, 3) . '/config/collections');

        $this->assertSame([], $repo->errors());

        // Posts are what a visitor is meant to read; team members are content a
        // site puts on an About page through a listing, not a directory of
        // personal pages nobody asked for. If that ever changes it should change
        // deliberately, which is what this test makes it.
        $this->assertSame('blog', $repo->find('post')?->route);
        $this->assertSame('', $repo->find('team-member')?->route);
    }
}
