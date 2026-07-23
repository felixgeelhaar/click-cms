<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Content\Content;

/**
 * Pagination and simple field filtering for a delivery listing.
 *
 * The delivery endpoints return published content to a front end, and returned
 * every item — a blog with hundreds of posts forced the client to fetch and
 * discard. This parses `?limit`, `?offset` and `?filter[field]=value` from a
 * query array and applies them to a list of {@see Content}, in memory, after the
 * store has produced the published set.
 *
 * It is deliberately additive and backward-compatible: with none of these
 * parameters present it returns exactly what it was given, so a caller that
 * never paginated sees no change beyond a new `meta` block. `limit` is capped so
 * a single request cannot ask for an unbounded slice; an out-of-range or
 * malformed value falls back to the unpaginated default rather than erroring,
 * because a delivery API answering a typo with a 400 helps no one.
 *
 * Filtering is intentionally shallow — an exact match on a top-level `data`
 * field, or membership when that field holds a list (a tag, a category). Richer
 * querying is a store concern and a v2 conversation; this covers the common
 * "posts where status = featured" case a front end actually asks for.
 */
final class DeliveryQuery
{
    /** The most items one request may ask for, however large its `limit`. */
    public const MAX_LIMIT = 100;

    /**
     * @param int|null                $limit   null means "no explicit limit"
     * @param array<string, string>   $filters field name => required value
     */
    private function __construct(
        public readonly ?int $limit,
        public readonly int $offset,
        public readonly array $filters,
    ) {
    }

    /**
     * Parse the listing controls out of a query array (typically `$_GET`).
     * Reserved keys — `locale` — are left to the caller; everything read here is
     * scoped to `limit`, `offset` and `filter[...]`.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $limit = null;
        if (isset($query['limit']) && is_numeric($query['limit'])) {
            $value = (int) $query['limit'];
            if ($value >= 1) {
                $limit = min($value, self::MAX_LIMIT);
            }
        }

        $offset = 0;
        if (isset($query['offset']) && is_numeric($query['offset'])) {
            $offset = max(0, (int) $query['offset']);
        }

        $filters = [];
        if (isset($query['filter']) && is_array($query['filter'])) {
            foreach ($query['filter'] as $field => $value) {
                // Only scalar equality — an array or object filter value is not
                // something this shallow matcher can honour, so it is ignored
                // rather than half-applied.
                if (is_string($field) && $field !== '' && is_scalar($value)) {
                    $filters[$field] = (string) $value;
                }
            }
        }

        return new self($limit, $offset, $filters);
    }

    /**
     * Filter, then page, a list of content.
     *
     * @param  list<Content> $items
     * @return array{items: list<Content>, meta: array{total: int, count: int, limit: int|null, offset: int}}
     */
    public function paginate(array $items): array
    {
        $filtered = $this->filters === []
            ? array_values($items)
            : array_values(array_filter($items, fn (Content $item): bool => $this->matches($item)));

        $total = count($filtered);
        // A null limit slices to the end — the unpaginated default.
        $slice = array_slice($filtered, $this->offset, $this->limit);

        return [
            'items' => $slice,
            'meta' => [
                'total' => $total,
                'count' => count($slice),
                'limit' => $this->limit,
                'offset' => $this->offset,
            ],
        ];
    }

    /**
     * True when the entry satisfies every filter. A list-valued field matches on
     * membership (a tag among tags); any other field matches on exact string
     * equality. A field that is absent, or holds a non-scalar the filter cannot
     * compare, never matches.
     */
    private function matches(Content $item): bool
    {
        foreach ($this->filters as $field => $wanted) {
            $value = $item->data[$field] ?? null;

            if (is_array($value)) {
                $found = false;
                foreach ($value as $element) {
                    if (is_scalar($element) && (string) $element === $wanted) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
                continue;
            }

            if (!is_scalar($value) || (string) $value !== $wanted) {
                return false;
            }
        }

        return true;
    }
}
