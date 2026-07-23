<?php

declare(strict_types=1);

namespace Click\Cms\Application\Collection;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Collection\CollectionTypeRepository;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Turns a stored reference — the slug of another content item — into a compact
 * descriptor a client can display without a second round trip: what kind of
 * thing it points at, its slug, a human title, and whether it still resolves.
 *
 * A reference is stored as bare slug so that renaming the *title* of the target
 * never breaks the link, and so the reference survives the target being a draft.
 * Resolution is therefore a read-time step, and a deliberately forgiving one: a
 * reference to something that has been deleted comes back {@code exists: false}
 * with the slug as its title, rather than throwing — a dangling link is an
 * editorial fact to surface, not an error that should blank a whole entry.
 *
 * The published/working distinction is honoured: the editor resolves against
 * working copies so a title shows even for a target not yet published, while
 * delivery resolves against published content only, so a public reference never
 * exposes the existence of an unpublished item.
 */
final class ReferenceResolver
{
    public function __construct(
        private readonly ContentService $content,
        private readonly CollectionTypeRepository $types,
    ) {}

    /**
     * @return array{type: string, slug: string, title: string, exists: bool}
     */
    public function resolve(string $target, string $slug, string|Locale|null $locale, bool $publishedOnly): array
    {
        $title = $slug;
        $exists = false;

        if ($target === 'page') {
            $page = $publishedOnly
                ? $this->content->page($slug, $locale)
                : $this->content->draftPage($slug, $locale);
            if ($page !== null) {
                $exists = true;
                $title = $page->title() !== '' ? $page->title() : $slug;
            }
        } else {
            $key = ContentKey::for($target, $slug, $locale);
            $entry = $publishedOnly ? $this->content->get($key) : $this->content->draft($key);
            if ($entry !== null) {
                $exists = true;
                $collectionType = $this->types->find($target);
                $title = $collectionType !== null
                    ? $collectionType->titleOf($entry->data)
                    : (string) ($entry->data['title'] ?? $slug);
            }
        }

        return ['type' => $target, 'slug' => $slug, 'title' => $title, 'exists' => $exists];
    }
}
