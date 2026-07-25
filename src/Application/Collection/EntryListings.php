<?php

declare(strict_types=1);

namespace Click\Cms\Application\Collection;

use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Schema\FieldType;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * What a listing section shows: the published entries of one collection, reduced
 * to the handful of values a card needs.
 *
 * Kept out of the renderer because the two questions are different in kind. Which
 * entries, in what order, how many, and where each lives is application logic
 * that needs storage and the router; turning that into escaped markup is
 * {@see \Click\Cms\Http\SectionRenderer}'s job. Splitting them is also what makes
 * this testable without a single byte of HTML.
 *
 * ## Only published entries, and that is structural
 *
 * The read is {@see CollectionService::published()}, which reads `content/` —
 * where published documents live and drafts do not. There is no `status` field
 * consulted and no filter to forget: a draft cannot appear in a listing because
 * this never asks for one. That property is the whole reason the read is not the
 * more convenient `all()`.
 */
final class EntryListings
{
    /** How many entries a listing shows when the section does not say. */
    private const DEFAULT_LIMIT = 6;

    /**
     * A ceiling regardless of what a section asks for. A listing is rendered on
     * every request for its page, and each entry costs a document read plus a
     * media lookup; an editor typing 5000 into a number field should get a long
     * page, not an outage.
     */
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly CollectionService $collections,
        private readonly EntryRouter $router,
        private readonly Locale $defaultLocale,
    ) {}

    /**
     * The cards for one listing section.
     *
     * @param array<string, mixed> $values The section's stored field values.
     * @param Locale $locale The language actually being served. Entries are read
     *        in that language and not resolved through the default one: a listing
     *        that fell back per entry would mix languages in one list, which reads
     *        as a bug to every visitor. A German page that falls back to the
     *        English document is *served* in English and so lists English entries,
     *        which is consistent rather than mixed.
     *
     * @return list<array{title: string, excerpt: string, image: string, href: ?string}>
     *         `href` is null when the collection has no public address, so the
     *         renderer can show the entry without linking it.
     */
    public function forSection(array $values, Locale $locale): array
    {
        $typeId = $values['collection'] ?? null;
        if (!is_string($typeId) || $typeId === '') {
            return [];
        }

        $type = $this->collections->collectionType($typeId);
        if ($type === null) {
            // A section pointing at a collection the site no longer declares
            // renders nothing, the same way a section of an undeclared type is
            // skipped rather than guessed at.
            return [];
        }

        $entries = $this->sort($type, $this->collections->published($typeId, $locale), $values['sort'] ?? null);
        $entries = array_slice($entries, 0, $this->limit($values['limit'] ?? null));

        $excerptField = $this->firstFieldOfType($type, FieldType::Textarea);
        $imageField = $this->firstFieldOfType($type, FieldType::Image);

        $cards = [];

        foreach ($entries as $entry) {
            $cards[] = [
                'title' => $type->titleOf($entry->data),
                'excerpt' => $this->stringValue($entry, $excerptField),
                'image' => $this->stringValue($entry, $imageField),
                'href' => $this->router->hrefFor($type, $entry->slug(), $entry->locale(), $this->defaultLocale),
            ];
        }

        return $cards;
    }

    /**
     * @param list<Content> $entries Already in the collection's declared order.
     * @return list<Content>
     */
    private function sort(CollectionType $type, array $entries, mixed $sort): array
    {
        // 'collection' — the default — means the order the type itself declares,
        // which CollectionService has already applied. Honouring it by doing
        // nothing is what keeps one answer to "what comes first" across the
        // editor's list, the delivery API and the rendered page.
        if (!is_string($sort) || $sort === '' || $sort === 'collection') {
            return $entries;
        }

        if ($sort === 'title') {
            usort(
                $entries,
                static fn (Content $a, Content $b): int
                    => strcasecmp($type->titleOf($a->data), $type->titleOf($b->data)),
            );

            return $entries;
        }

        if ($sort === 'newest' || $sort === 'oldest') {
            usort(
                $entries,
                static fn (Content $a, Content $b): int => $sort === 'newest'
                    ? $b->updatedAt() <=> $a->updatedAt()
                    : $a->updatedAt() <=> $b->updatedAt(),
            );
        }

        return $entries;
    }

    private function limit(mixed $limit): int
    {
        if (!is_numeric($limit)) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int) $limit;

        return $limit < 1 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);
    }

    /**
     * Which field supplies a card's summary, and which its picture.
     *
     * Read off the collection's own schema rather than configured a second time:
     * the first textarea field is the summary and the first image field the
     * picture. That is `excerpt` and `coverImage` on a post, `bio` and `photo` on a
     * team member, with no extra declaration on either — and a site that adds a
     * collection gets a working listing from the field types it was going to
     * declare anyway. A collection with neither simply shows titles.
     */
    private function firstFieldOfType(CollectionType $type, FieldType $wanted): ?string
    {
        foreach ($type->fields() as $field) {
            if ($field->type === $wanted) {
                return $field->name;
            }
        }

        return null;
    }

    private function stringValue(Content $entry, ?string $field): string
    {
        if ($field === null) {
            return '';
        }

        $value = $entry->data[$field] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
