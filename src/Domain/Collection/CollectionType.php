<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Collection;

use Click\Cms\Domain\Schema\SectionType;

/**
 * A repeatable content type — a blog's posts, a team's members, a catalogue's
 * products — defined by the set of fields each of its entries carries.
 *
 * A collection type is, in schema terms, exactly a named set of fields, which is
 * what {@see SectionType} already models and {@see \Click\Cms\Domain\Schema\SectionValidator}
 * already validates. So rather than grow a parallel field system, a collection
 * type composes a SectionType for its fields and adds only what is collection-
 * specific: which field is the entry's display title (and the source of its
 * slug), and where — if anywhere — its entries live on the public site.
 * Everything else — draft/publish, history, audit, per-owner permissions — an
 * entry inherits by being an ordinary content document whose type is this
 * collection's id.
 */
final class CollectionType
{
    /**
     * A route is one or more slug-shaped segments: `blog`, `journal/notes`.
     * Uppercase, spaces, dots and traversal are all excluded, because this value
     * is matched against a request path and then used to build hrefs.
     */
    private const ROUTE_PATTERN = '#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#D';

    /**
     * Path prefixes the kernel answers before it ever looks at public content —
     * `/api`, `/admin`, `/preview/`, `/health/…`. A collection routed under one of
     * them would have entries that are simply unreachable, so the definition is
     * refused rather than accepted and quietly ignored: a route that does not
     * route is worse than an error message.
     */
    private const RESERVED_PREFIXES = ['api', 'admin', 'preview', 'health'];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $description,
        public readonly SectionType $schema,
        /** The field whose value titles an entry and seeds its slug. */
        public readonly string $titleField,
        /**
         * The field a listing is ordered by, or '' to fall back to recency
         * (most-recently-edited first). A blog wants its posts by date, not by
         * the accident of when each was created.
         */
        public readonly string $sortField,
        /** 'asc' or 'desc'. */
        public readonly string $sortDirection,
        /**
         * Where this collection's entries live on the public site — the path
         * prefix each entry's own address hangs off, without slashes at either
         * end (`blog` gives `/blog/why-we-stopped-staining`), or '' for a
         * collection with no public address at all.
         *
         * Core deliberately does not derive this from the id. A collection of
         * team members is real content that a site may never want a page for,
         * and a collection of posts may want to live at `journal` on one site and
         * `blog` on the next. Naming is the site's, so it is declared in the
         * site's own definition file; '' — the default — means admin-only, which
         * is what every collection written before this existed continues to be.
         */
        public readonly string $route = '',
    ) {}

    /**
     * @param array<string, mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        $id = (string) ($spec['id'] ?? '');
        $label = (string) ($spec['label'] ?? $id);
        $description = isset($spec['description']) && is_string($spec['description'])
            ? $spec['description']
            : null;
        $titleField = isset($spec['titleField']) && is_string($spec['titleField']) && $spec['titleField'] !== ''
            ? $spec['titleField']
            : 'title';

        // The field set is parsed by the same machinery a section uses, so field
        // types, defaults and validation behave identically wherever they appear.
        $schema = SectionType::fromArray([
            'id' => $id,
            'label' => $label,
            'fields' => is_array($spec['fields'] ?? null) ? $spec['fields'] : [],
        ]);

        $sort = is_array($spec['sort'] ?? null) ? $spec['sort'] : [];
        $sortField = isset($sort['field']) && is_string($sort['field']) ? $sort['field'] : '';
        // Anything that is not an explicit ascending request means descending —
        // the useful default for the common case, a newest-first date listing.
        $sortDirection = (isset($sort['direction']) && $sort['direction'] === 'asc') ? 'asc' : 'desc';

        return new self(
            $id,
            $label,
            $description,
            $schema,
            $titleField,
            $sortField,
            $sortDirection,
            self::parseRoute($spec['route'] ?? null, $id),
        );
    }

    /**
     * A declared route, validated, or '' when none is declared.
     *
     * Surrounding slashes are tolerated — `"/blog"` and `"blog/"` are the same
     * intent — and everything else is refused by throwing, which the repository
     * turns into a reported error against the file. Silently dropping a malformed
     * route would give a site a collection it believes is public and a set of
     * addresses that all 404.
     *
     * @throws \InvalidArgumentException
     */
    private static function parseRoute(mixed $raw, string $id): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (!is_string($raw)) {
            throw new \InvalidArgumentException(
                "Collection type \"{$id}\" has a \"route\" that is not a string."
            );
        }

        $route = trim(trim($raw), '/');
        if ($route === '') {
            return '';
        }

        if (preg_match(self::ROUTE_PATTERN, $route) !== 1) {
            throw new \InvalidArgumentException(
                "Collection type \"{$id}\" has route \"{$route}\", which must be lowercase "
                . 'path segments of letters, digits and dashes.'
            );
        }

        $first = explode('/', $route)[0];
        if (in_array($first, self::RESERVED_PREFIXES, true)) {
            throw new \InvalidArgumentException(
                "Collection type \"{$id}\" cannot be routed under \"{$first}\": that path "
                . 'belongs to the application itself.'
            );
        }

        return $route;
    }

    /** Whether entries of this type have an address a visitor can open. */
    public function hasPublicAddress(): bool
    {
        return $this->route !== '';
    }

    /**
     * The public path of one entry, relative to the site root and without a
     * language prefix — `blog/why-we-stopped-staining` — or null when this
     * collection has no public address.
     *
     * No leading slash and no locale: prefixing is the caller's job, because only
     * the caller knows which language is being served and what the site's default
     * language is.
     */
    public function pathFor(string $slug): ?string
    {
        if (!$this->hasPublicAddress() || $slug === '') {
            return null;
        }

        return $this->route . '/' . $slug;
    }

    /**
     * Order entries by this type's declared sort. A missing or empty sort value
     * sorts last regardless of direction, so entries yet to be given (say) a date
     * do not jump to the top of a newest-first list. Comparison is numeric when
     * both values are numeric and lexical otherwise, so dates (ISO 8601) and
     * numbers both order correctly. Ties fall back to most-recently-edited, and
     * a type with no sort field falls back to that entirely.
     *
     * @param list<\Click\Cms\Domain\Content\Content> $entries
     * @return list<\Click\Cms\Domain\Content\Content>
     */
    public function order(array $entries): array
    {
        usort($entries, function ($a, $b): int {
            if ($this->sortField !== '') {
                $va = $a->data[$this->sortField] ?? null;
                $vb = $b->data[$this->sortField] ?? null;
                $ea = $va === null || $va === '';
                $eb = $vb === null || $vb === '';
                if ($ea !== $eb) {
                    return $ea ? 1 : -1; // empties always last
                }
                if (!$ea) {
                    $cmp = (is_numeric($va) && is_numeric($vb))
                        ? ($va <=> $vb)
                        : strcmp((string) $va, (string) $vb);
                    if ($cmp !== 0) {
                        return $this->sortDirection === 'asc' ? $cmp : -$cmp;
                    }
                }
            }

            // Tie-break (and the no-sort-field default): newest edit first.
            return $b->updatedAt() <=> $a->updatedAt();
        });

        return $entries;
    }

    /**
     * @return list<\Click\Cms\Domain\Schema\FieldDefinition>
     */
    public function fields(): array
    {
        return $this->schema->fields;
    }

    /**
     * The display title for an entry's stored data — the value of the title
     * field, falling back to the slug so a listing is never blank.
     *
     * @param array<string, mixed> $data
     */
    public function titleOf(array $data): string
    {
        $value = $data[$this->titleField] ?? null;
        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return (string) ($data['slug'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'titleField' => $this->titleField,
            // Reported even when empty, so a client can tell "no public address"
            // from "this build does not know about routes".
            'route' => $this->route,
            'sort' => ['field' => $this->sortField, 'direction' => $this->sortDirection],
            'fields' => array_map(
                static fn ($field): array => $field->toArray(),
                $this->schema->fields
            ),
        ];
    }
}
