<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\DeliveryQuery;
use PHPUnit\Framework\TestCase;

/**
 * Pagination and simple filtering for the delivery listings. Pure over a list of
 * content, so it is pinned down here without a booted kernel.
 */
final class DeliveryQueryTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function entry(string $slug, array $data = []): Content
    {
        return Content::create(ContentKey::page($slug), ['title' => $slug] + $data);
    }

    /** @return list<Content> */
    private function entries(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = $this->entry("post-{$i}");
        }

        return $out;
    }

    /** @return list<string> */
    private function slugs(array $items): array
    {
        return array_map(static fn (Content $c): string => $c->slug(), $items);
    }

    public function testNoParametersReturnsEverythingUnchanged(): void
    {
        $result = DeliveryQuery::fromQuery([])->paginate($this->entries(3));

        $this->assertSame(['post-1', 'post-2', 'post-3'], $this->slugs($result['items']));
        $this->assertSame(
            ['total' => 3, 'count' => 3, 'limit' => null, 'offset' => 0],
            $result['meta'],
        );
    }

    public function testLimitAndOffsetSliceTheList(): void
    {
        $result = DeliveryQuery::fromQuery(['limit' => '2', 'offset' => '1'])->paginate($this->entries(5));

        $this->assertSame(['post-2', 'post-3'], $this->slugs($result['items']));
        $this->assertSame(5, $result['meta']['total']);
        $this->assertSame(2, $result['meta']['count']);
        $this->assertSame(2, $result['meta']['limit']);
        $this->assertSame(1, $result['meta']['offset']);
    }

    public function testOffsetPastTheEndYieldsNothingButKeepsTheTotal(): void
    {
        $result = DeliveryQuery::fromQuery(['offset' => '10'])->paginate($this->entries(3));

        $this->assertSame([], $result['items']);
        $this->assertSame(3, $result['meta']['total']);
        $this->assertSame(0, $result['meta']['count']);
    }

    public function testLimitIsCappedSoOneRequestCannotAskForEverything(): void
    {
        $query = DeliveryQuery::fromQuery(['limit' => '5000']);

        $this->assertSame(DeliveryQuery::MAX_LIMIT, $query->limit);
    }

    public function testMalformedControlsFallBackToTheUnpaginatedDefault(): void
    {
        $query = DeliveryQuery::fromQuery(['limit' => 'lots', 'offset' => '-4']);

        $this->assertNull($query->limit);
        $this->assertSame(0, $query->offset);
    }

    public function testAFieldFilterMatchesExactly(): void
    {
        $items = [
            $this->entry('a', ['status' => 'featured']),
            $this->entry('b', ['status' => 'normal']),
            $this->entry('c', ['status' => 'featured']),
        ];

        $result = DeliveryQuery::fromQuery(['filter' => ['status' => 'featured']])->paginate($items);

        $this->assertSame(['a', 'c'], $this->slugs($result['items']));
        $this->assertSame(2, $result['meta']['total']);
    }

    public function testFilteringHappensBeforePaging(): void
    {
        $items = [
            $this->entry('a', ['status' => 'featured']),
            $this->entry('b', ['status' => 'normal']),
            $this->entry('c', ['status' => 'featured']),
            $this->entry('d', ['status' => 'featured']),
        ];

        $result = DeliveryQuery::fromQuery([
            'filter' => ['status' => 'featured'],
            'limit' => '2',
        ])->paginate($items);

        // Total is the count of the filtered set (3), not the whole list (4).
        $this->assertSame(3, $result['meta']['total']);
        $this->assertSame(['a', 'c'], $this->slugs($result['items']));
    }

    public function testAListValuedFieldMatchesOnMembership(): void
    {
        $items = [
            $this->entry('a', ['tags' => ['php', 'cms']]),
            $this->entry('b', ['tags' => ['design']]),
        ];

        $result = DeliveryQuery::fromQuery(['filter' => ['tags' => 'cms']])->paginate($items);

        $this->assertSame(['a'], $this->slugs($result['items']));
    }

    public function testAnAbsentFieldNeverMatches(): void
    {
        $items = [$this->entry('a', ['status' => 'featured']), $this->entry('b')];

        $result = DeliveryQuery::fromQuery(['filter' => ['status' => 'featured']])->paginate($items);

        $this->assertSame(['a'], $this->slugs($result['items']));
    }

    public function testMultipleFiltersAreAnded(): void
    {
        $items = [
            $this->entry('a', ['status' => 'featured', 'lang' => 'en']),
            $this->entry('b', ['status' => 'featured', 'lang' => 'de']),
        ];

        $result = DeliveryQuery::fromQuery([
            'filter' => ['status' => 'featured', 'lang' => 'de'],
        ])->paginate($items);

        $this->assertSame(['b'], $this->slugs($result['items']));
    }
}
