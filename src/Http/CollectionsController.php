<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Collection\BackReferenceService;
use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\ReferenceResolver;
use Click\Cms\Application\History\HistoryService;
use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Schema\FieldType;
use Click\Cms\Domain\ValueObjects\ContentKey;

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
        // Version history is keyed purely by ContentKey, so the same service the
        // page endpoints use answers for a collection entry once handed the
        // entry's key. Optional so a controller built without it (an older test)
        // still constructs; the history routes then report the feature absent.
        private readonly ?HistoryService $history = null,
        // Signs preview links for an entry's draft. Optional for the same reason;
        // without it the preview endpoints report the feature absent.
        private readonly ?PreviewLinks $previewLinks = null,
        // Answers "what links here?" by scanning reference fields. Optional; the
        // back-reference route reports the feature absent without it.
        private readonly ?BackReferenceService $backReferences = null,
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

            // Preview delivery — the DRAFT entry as delivery JSON, gated by a
            // signed token (or a session), for a front-end preview environment.
            // The `preview` segment keeps it distinct from an entry slug.
            'GET /api/collections/:type/preview/:slug' => [$this, 'previewEntry'],

            'GET /api/collections/:type/entries' => [$this, 'listEntries'],
            'POST /api/collections/:type/entries' => [$this, 'createEntry'],
            'GET /api/collections/:type/entries/:slug' => [$this, 'getEntry'],
            'PUT /api/collections/:type/entries/:slug' => [$this, 'updateEntry'],
            'DELETE /api/collections/:type/entries/:slug' => [$this, 'deleteEntry'],
            'POST /api/collections/:type/entries/:slug/publish' => [$this, 'publishEntry'],
            'POST /api/collections/:type/entries/:slug/unpublish' => [$this, 'unpublishEntry'],

            // History, mirroring the page endpoints so an entry is as recoverable
            // as a page. Declared after the entry routes; the extra path segment
            // keeps them from colliding with an entry slug.
            'GET /api/collections/:type/entries/:slug/versions' => [$this, 'listEntryVersions'],
            'GET /api/collections/:type/entries/:slug/versions/:id' => [$this, 'getEntryVersion'],
            'POST /api/collections/:type/entries/:slug/versions/:id/restore' => [$this, 'restoreEntryVersion'],

            // What links here — the entries that reference this one, so an editor
            // can see an entry's incoming relations before changing or deleting it.
            'GET /api/collections/:type/entries/:slug/backreferences' => [$this, 'listBackReferences'],

            // Minting a preview link for an entry's draft. A POST, so it stays
            // authenticated — handing out a link to unpublished work is a
            // decision only a permitted account may make.
            'POST /api/collections/:type/entries/:slug/preview' => [$this, 'createEntryPreviewLink'],
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

        $response = ['data' => $this->entryView($collectionType, $entry, true)];

        // Which other languages this entry exists in, so the editor's language
        // switcher can show what is written and what is still untranslated —
        // exactly as the page editor does.
        $response['availableLocales'] = array_map(
            static fn ($l): string => $l->code,
            $this->collections->translationsOf($type, $slug)
        );

        return $response;
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

    /* ------------------------------------------------------------- history -- */

    public function listEntryVersions(string $type, string $slug): array
    {
        if ($this->collections->collectionType($type) === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }
        if ($this->history === null) {
            return ['status' => 501, 'error' => 'Version history is not available.'];
        }

        // Versions belong to one translation, so the key carries the locale the
        // rest of the entry API uses. Without it a German entry would show — and
        // restore from — English history, the exact bug the page endpoints fixed.
        $result = $this->history->all(
            ContentKey::for($type, $slug, $this->localeParam()),
            $this->user()
        );

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        return ['data' => $result['versions']];
    }

    public function getEntryVersion(string $type, string $slug, string $id): array
    {
        if ($this->collections->collectionType($type) === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }
        if ($this->history === null) {
            return ['status' => 501, 'error' => 'Version history is not available.'];
        }

        $result = $this->history->get(
            ContentKey::for($type, $slug, $this->localeParam()),
            $id,
            $this->user()
        );

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        return ['data' => $result['version']->toArray()];
    }

    public function restoreEntryVersion(string $type, string $slug, string $id): array
    {
        $collectionType = $this->collections->collectionType($type);
        if ($collectionType === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }
        if ($this->history === null) {
            return ['status' => 501, 'error' => 'Version history is not available.'];
        }

        $locale = $this->localeParam();
        $result = $this->history->restore(
            ContentKey::for($type, $slug, $locale),
            $id,
            $this->user()
        );

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        // The entry as it now stands, so the editor sees the result of the
        // restore rather than the version they asked for — in the language that
        // was restored, not the default one.
        $entry = $this->collections->find($type, $slug, $locale);

        return [
            'data' => [
                'restoredFrom' => $result['version']->summary(),
                'entry' => $entry !== null ? $this->entryView($collectionType, $entry, true) : null,
            ],
        ];
    }

    /* ------------------------------------------------------ back-references -- */

    /**
     * The entries that reference this one — "which posts point at this author?".
     * A read for a signed-in editor; it may surface drafts, so it is not public.
     */
    public function listBackReferences(string $type, string $slug): array
    {
        if ($this->collections->collectionType($type) === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }
        if ($this->backReferences === null) {
            return ['status' => 501, 'error' => 'Back-references are not available.'];
        }
        if ($this->user() === []) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        return ['data' => $this->backReferences->referencesTo($type, $slug, $this->localeParam())];
    }

    /* ------------------------------------------------------------- preview -- */

    /**
     * Mint a signed link that returns this entry's draft through the preview
     * delivery endpoint. A collection entry has no server-rendered page, so a
     * preview is the draft itself as delivery JSON — a front-end preview
     * environment points at the link and renders it as it would the published
     * entry. Authenticated and permission-gated, because a link to unpublished
     * work lets it out of the building.
     */
    public function createEntryPreviewLink(string $type, string $slug): array
    {
        if ($this->collections->collectionType($type) === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }
        if ($this->previewLinks === null) {
            return ['status' => 501, 'error' => 'Preview links are not available.'];
        }

        $user = $this->user();
        if ($user === []) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }
        if (!Role::fromName($user['role'] ?? null)->can(Capability::PreviewContent)) {
            return ['status' => 403, 'error' => 'You do not have permission to share a preview of this entry.'];
        }

        $locale = $this->localeParam();
        $key = ContentKey::for($type, $slug, $locale);

        // The working copy — the thing preview exists to show. Exactly this
        // language, no fallback: a link minted for a German draft that does not
        // exist must not verify and then show the English one.
        if ($this->collections->find($type, $slug, $locale) === null) {
            return ['status' => 404, 'error' => 'Entry not found.'];
        }

        $link = $this->previewLinks->issue($key);
        if ($link === null) {
            return ['status' => 500, 'error' => 'A preview link could not be signed. Check that data/ is writable.'];
        }

        // The link points at the entry's own preview delivery endpoint. The
        // language rides along only when it is not the default, mirroring the key
        // the token signed — the handler rebuilds the same key to verify.
        $url = '/api/collections/' . rawurlencode($type) . '/preview/' . rawurlencode($slug)
            . '?token=' . rawurlencode($link['token'])
            . ($locale !== null ? '&locale=' . rawurlencode($locale) : '');

        return ['data' => [
            'url' => $url,
            'expiresAt' => $link['expiresAt'],
            'expiresInSeconds' => max(0, $link['expiresAt'] - time()),
        ]];
    }

    /**
     * The draft entry as delivery JSON, for a preview environment. Reachable
     * anonymously, but only ever answers when a valid signed token is presented
     * — or the caller is signed in, so an editor clicking through need not mint a
     * link to look at their own work. A visitor with neither gets the same 404
     * the public delivery gives, so an unpublished entry's existence is not
     * disclosed either.
     */
    public function previewEntry(string $type, string $slug): array
    {
        $collectionType = $this->collections->collectionType($type);
        if ($collectionType === null) {
            return ['status' => 404, 'error' => 'Unknown collection.'];
        }

        $locale = $this->localeParam();
        $key = ContentKey::for($type, $slug, $locale);

        $token = $_GET['token'] ?? null;
        $bySignature = $this->previewLinks?->accepts($key, is_string($token) ? $token : null) ?? false;

        if (!$bySignature && $this->user() === []) {
            return ['status' => 404, 'error' => 'Entry not found.'];
        }

        // The working copy, in exactly this language with no fallback — the draft
        // is the thing a preview shows, and a preview of a missing translation
        // must be absent rather than quietly show another language.
        $entry = $this->collections->find($type, $slug, $locale);
        if ($entry === null) {
            return ['status' => 404, 'error' => 'Entry not found.'];
        }

        // The public delivery shape, with a marker that this is a draft preview
        // and must not be cached or treated as live.
        return [
            'data' => $this->entryView($collectionType, $entry, false),
            'preview' => true,
            'headers' => [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ],
        ];
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
