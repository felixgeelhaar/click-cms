<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\ReferenceResolver;
use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Schema\FieldType;

/**
 * The management and delivery API for collections and their entries.
 *
 * Management (authenticated) mirrors the page endpoints: list the declared
 * collection types, then create, read, update, delete and publish the entries of
 * one. Delivery (`/published`) is the public read a headless front end makes, and
 * like `/api/pages` it can only ever return what is live — a draft or a taken-
 * down entry is not in the published list, so there is nothing to leak.
 *
 * The controller is thin: every rule about validation, ownership and publication
 * lives in {@see CollectionService}. Here we only read the request, pick the list
 * an editor-or-anonymous caller may see, and shape the response.
 */
final class CollectionsController
{
    /**
     * @param callable(): array<string, mixed> $currentUser Resolves the signed-in
     *        user for the current request, or [] when anonymous.
     */
    public function __construct(
        private readonly CollectionService $collections,
        private readonly ReferenceResolver $references,
        private readonly mixed $currentUser,
    ) {}

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
            'GET /api/collections' => [$this, 'listTypes'],
            'GET /api/collections/:type' => [$this, 'getType'],

            // Delivery — the public read. Declared before the management entry
            // routes so `/published` is never mistaken for an entry slug.
            'GET /api/collections/:type/published' => [$this, 'listPublished'],
            'GET /api/collections/:type/published/:slug' => [$this, 'getPublished'],

            'GET /api/collections/:type/entries' => [$this, 'listEntries'],
            'POST /api/collections/:type/entries' => [$this, 'createEntry'],
            'GET /api/collections/:type/entries/:slug' => [$this, 'getEntry'],
            'PUT /api/collections/:type/entries/:slug' => [$this, 'updateEntry'],
            'DELETE /api/collections/:type/entries/:slug' => [$this, 'deleteEntry'],
            'POST /api/collections/:type/entries/:slug/publish' => [$this, 'publishEntry'],
            'POST /api/collections/:type/entries/:slug/unpublish' => [$this, 'unpublishEntry'],
        ];
    }

    /* --------------------------------------------------------------- types -- */

    public function listTypes(): array
    {
        return ['data' => array_map(
            fn (CollectionType $type): array => $type->toArray() + [
                'entryCount' => count($this->collections->all($type->id, $this->localeParam())),
            ],
            $this->collections->collectionTypes()
        )];
    }

    public function getType(string $type): array
    {
        $found = $this->collections->collectionType($type);
        if ($found === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }

        return ['data' => $found->toArray()];
    }

    /* ------------------------------------------------------------- entries -- */

    public function listEntries(string $type): array
    {
        $collectionType = $this->collections->collectionType($type);
        if ($collectionType === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }

        $locale = $this->localeParam();

        return [
            'data' => array_map(
                fn (Content $entry): array => $this->entryView($collectionType, $entry, true),
                $this->collections->all($type, $locale)
            ),
        ];
    }

    public function getEntry(string $type, string $slug): array
    {
        $collectionType = $this->collections->collectionType($type);
        if ($collectionType === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }

        $entry = $this->collections->find($type, $slug, $this->localeParam());
        if ($entry === null) {
            return ['status' => 404, 'error' => 'Entry not found.'];
        }

        return ['data' => $this->entryView($collectionType, $entry, true)];
    }

    public function createEntry(string $type): array
    {
        return $this->writeResult(
            $type,
            $this->collections->create($type, $this->jsonBody(), $this->user(), $this->localeParam())
        );
    }

    public function updateEntry(string $type, string $slug): array
    {
        return $this->writeResult(
            $type,
            $this->collections->update($type, $slug, $this->jsonBody(), $this->user(), $this->localeParam())
        );
    }

    public function deleteEntry(string $type, string $slug): array
    {
        $result = $this->collections->delete($type, $slug, $this->user(), $this->localeParam());
        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        return ['data' => null];
    }

    public function publishEntry(string $type, string $slug): array
    {
        return $this->writeResult(
            $type,
            $this->collections->publish($type, $slug, $this->user(), $this->localeParam())
        );
    }

    public function unpublishEntry(string $type, string $slug): array
    {
        return $this->writeResult(
            $type,
            $this->collections->unpublish($type, $slug, $this->user(), $this->localeParam())
        );
    }

    /* ------------------------------------------------------------ delivery -- */

    public function listPublished(string $type): array
    {
        $collectionType = $this->collections->collectionType($type);
        if ($collectionType === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }

        // Filter and page the published set before rendering views, so a blog
        // with hundreds of entries is not fetched whole. `meta` reports the total
        // after filtering so a client can build its own pager; with no limit,
        // offset or filter present the result is every entry, as before.
        $page = DeliveryQuery::fromQuery($_GET)->paginate(
            $this->collections->published($type, $this->localeParam())
        );

        return [
            'data' => array_map(
                fn (Content $entry): array => $this->entryView($collectionType, $entry, false),
                $page['items']
            ),
            'meta' => $page['meta'],
        ];
    }

    public function getPublished(string $type, string $slug): array
    {
        $collectionType = $this->collections->collectionType($type);
        if ($collectionType === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }

        $entry = $this->collections->findPublished($type, $slug, $this->localeParam());
        if ($entry === null) {
            return ['status' => 404, 'error' => 'Entry not found.'];
        }

        return ['data' => $this->entryView($collectionType, $entry, false)];
    }

    /* -------------------------------------------------------------- shared -- */

    /**
     * @param array{entry: ?Content, error: ?string, status: int, errors: array<string, string>} $result
     */
    private function writeResult(string $type, array $result): array
    {
        if ($result['error'] !== null) {
            return [
                'status' => $result['status'],
                'error' => $result['error'],
                'errors' => $result['errors'],
            ];
        }

        $collectionType = $this->collections->collectionType($type);
        $view = $collectionType !== null && $result['entry'] !== null
            ? $this->entryView($collectionType, $result['entry'], true)
            : null;

        return ['status' => $result['status'], 'data' => $view];
    }

    /**
     * The shape an entry is returned in: its stored fields, a title resolved
     * through the type's title field, and — for an editor — its publication
     * state, so a listing can show what is live, drafted or changed. Publication
     * is withheld from the public delivery view, which has no business knowing an
     * entry has unpublished changes.
     */
    private function entryView(CollectionType $type, Content $entry, bool $includePublication): array
    {
        $view = [
            'slug' => $entry->slug(),
            'locale' => $entry->locale()->code,
            'title' => $type->titleOf($entry->data),
            'data' => $entry->data,
            'updatedAt' => $entry->updatedAt()->format(DATE_ATOM),
        ];

        $resolvedRefs = $this->resolveReferences($type, $entry, $includePublication);
        if ($resolvedRefs !== []) {
            // References are expanded alongside the raw data, not into it: `data`
            // keeps the bare slugs it was saved with, and `references` carries the
            // resolved titles a client shows. A public delivery view resolves
            // published targets only.
            $view['references'] = $resolvedRefs;
        }

        if ($includePublication) {
            $state = $this->collections->publicationOf($type->id, $entry->slug(), $entry->locale());
            $view['publication'] = [
                'published' => $state->published,
                'hasUnpublishedChanges' => $state->hasUnpublishedChanges,
                'neverPublished' => $state->neverPublished,
                'publishedAt' => $state->publishedAt,
            ];
        }

        return $view;
    }

    /**
     * Resolve each of the type's reference fields for one entry. Editors resolve
     * against working copies (so a title shows for a target not yet published);
     * a delivery view resolves published targets only.
     *
     * @return array<string, array{type: string, slug: string, title: string, exists: bool}>
     */
    private function resolveReferences(CollectionType $type, Content $entry, bool $editorView): array
    {
        $out = [];
        foreach ($type->fields() as $field) {
            if ($field->type !== FieldType::Reference || $field->references === null) {
                continue;
            }
            $value = $entry->data[$field->name] ?? null;
            $resolve = fn (string $slug): array => $this->references->resolve(
                $field->references,
                $slug,
                $entry->locale(),
                !$editorView,
            );

            if ($field->multiple) {
                // A list of descriptors, in the order the slugs were stored, so a
                // client can render "featured posts" without a second lookup.
                if (is_array($value) && $value !== []) {
                    $out[$field->name] = array_values(array_map(
                        $resolve,
                        array_filter($value, static fn ($s): bool => is_string($s) && $s !== '')
                    ));
                }
            } elseif (is_string($value) && $value !== '') {
                $out[$field->name] = $resolve($value);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function user(): array
    {
        return ($this->currentUser)();
    }

    private function localeParam(): ?string
    {
        $locale = $_GET['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
