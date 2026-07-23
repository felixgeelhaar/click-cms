<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\History\HistoryService;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Media\ImageSize;
use Click\Cms\Domain\Media\UploadPolicy;
use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Media\GdImageProcessor;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;

/**
 * API endpoints the CMS cannot function without.
 *
 * These are core rather than plugins because the admin UI stops working the
 * moment they are absent: an editor cannot choose a section design without the
 * schema endpoints, and cannot place an image without the media endpoints.
 * Anything a site can genuinely run without stays a plugin.
 *
 * Note the deliberate split from the `rest-api` plugin. That plugin is the
 * *public delivery* API — the one an external front end consumes — and is
 * optional, because a site that renders its own pages has no use for it. What
 * lives here is the *management* API, which is not optional.
 */
final class CoreApiRoutes
{
    private ?JsonSectionTypeRepository $sectionTypes = null;
    private ?MediaService $media = null;
    private ?MediaLibrary $mediaLibrary = null;
    private ?PageService $pages = null;
    private ?ContentService $contentService = null;
    private ?VersioningStorage $storage = null;
    private ?JsonVersionStore $versions = null;
    private ?PreviewLinks $previewLinks = null;

    public function __construct(
        private readonly string $basePath,
        private readonly ?ContentService $content = null,
        private ?HistoryService $history = null,
        private readonly ?CoreConfig $config = null,
    ) {}

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
            // Managing pages. Reading published content for a front end is the
            // delivery API's job and stays in a plugin; editing is management
            // and cannot be something a site is able to uninstall.
            'GET /api/pages' => [$this, 'listPages'],
            'GET /api/pages/:slug' => [$this, 'getPage'],
            'POST /api/pages' => [$this, 'createPage'],
            'PUT /api/pages/:slug' => [$this, 'updatePage'],
            'DELETE /api/pages/:slug' => [$this, 'deletePage'],

            // Publication. A POST because it is a thing that happens rather
            // than a field being set — and because being unsafe means it
            // carries the CSRF token, so no other site can put a page live by
            // getting an editor to load an image.
            'POST /api/pages/:slug/publish' => [$this, 'publishPage'],
            'POST /api/pages/:slug/unpublish' => [$this, 'unpublishPage'],

            // History. Nested under the page rather than a top-level
            // /api/versions, because a version has no meaning apart from the
            // document it is a version of, and the address should say so.
            'GET /api/pages/:slug/versions' => [$this, 'listPageVersions'],
            'GET /api/pages/:slug/versions/:id' => [$this, 'getPageVersion'],
            'POST /api/pages/:slug/versions/:id/restore' => [$this, 'restorePageVersion'],

            // Minting a preview link. A POST rather than a GET because it hands
            // back a credential: it is a thing that happens, not a thing that
            // is read, and being unsafe means it carries the CSRF token and can
            // never be triggered by another site embedding a URL.
            'POST /api/pages/:slug/preview' => [$this, 'createPreviewLink'],

            'GET /api/section-types' => [$this, 'listSectionTypes'],
            'GET /api/section-types/:id' => [$this, 'getSectionType'],

            'GET /api/media' => [$this, 'listMedia'],
            'POST /api/media' => [$this, 'uploadMedia'],
            'POST /api/media/bulk-delete' => [$this, 'bulkDeleteMedia'],
            'GET /api/media/capabilities' => [$this, 'mediaCapabilities'],
            'GET /api/media/file/:filename' => [$this, 'serveMediaFile'],
            'GET /api/media/:id' => [$this, 'getMedia'],
            'PUT /api/media/:id' => [$this, 'updateMedia'],
            'DELETE /api/media/:id' => [$this, 'deleteMedia'],
        ];
    }

    /* ------------------------------------------------------------- pages -- */

    /**
     * @return array<string, mixed>
     */
    public function listPages(): array
    {
        $locale = $this->requestedLocale();
        if ($locale['error'] !== null) {
            return ['status' => 400, 'error' => $locale['error']];
        }

        // Anonymous callers see published pages only.
        //
        // This endpoint is deliberately public — a headless front end reads it
        // with no account — but it must not be a way to read unpublished work,
        // which would make signed preview links pointless. That is now a choice
        // of which list to ask for rather than a filter applied afterwards: the
        // public list is what is in `content/`, and there is nothing in it that
        // needs excluding.
        $signedIn = $this->currentUser() !== [];

        $pages = $signedIn
            ? $this->pages()->all($locale['locale'])
            : $this->pages()->published($locale['locale']);

        // `?limit`, `?offset` and `?filter[field]=value` page and filter the
        // listing so a front end (or a large admin page list) need not fetch
        // every page. Absent those parameters this returns the whole list as it
        // always did; `meta` reports the total after filtering either way.
        $page = DeliveryQuery::fromQuery($_GET)->paginate($pages);

        return [
            'data' => array_map(
                // Publication state only for an editor. An anonymous reader is
                // looking at the live site by definition, and telling them which
                // pages have unpublished edits pending leaks the shape of work
                // in progress for no benefit.
                fn ($page): array => $signedIn
                    ? $page->toArray() + ['publication' => $this->publicationOf($page)]
                    : $page->toArray(),
                $page['items']
            ),
            'meta' => $page['meta'],
            // Echoed so a client can tell which language it is looking at
            // without having to remember what it asked for, and so the admin UI
            // can offer the rest.
            'locale' => $locale['locale']->code,
            'locales' => array_map(
                static fn ($l): string => $l->code,
                $this->config?->locales() ?? [$locale['locale']]
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPage(string $slug): array
    {
        $requested = $this->requestedLocale();
        if ($requested['error'] !== null) {
            return ['status' => 400, 'error' => $requested['error']];
        }

        $signedIn = $this->currentUser() !== [];

        // An editor gets the copy they are working on, in exactly the language
        // they asked for. No fallback on this path: the working copy of a
        // translation that does not exist is not the English one.
        $draft = $signedIn ? $this->pages()->find($slug, $requested['locale']) : null;

        // Read with fallback: a front end asking for a language that has no
        // translation should get the page rather than a 404. What it must not
        // get is the fallback without being told, so the served language and
        // the fact that it was a fallback are part of the response — a client
        // that shows German chrome around English prose is at least able to
        // know it is doing so.
        //
        // Anonymous callers only ever reach this branch, and it reads `content/`,
        // so an unpublished page is a 404 by construction rather than by a check
        // somebody could forget to write. Not found rather than forbidden was
        // always the intent: telling an anonymous caller that a page exists but
        // is unpublished leaks the thing being protected — which slugs are being
        // worked on — even while withholding the content.
        $resolved = $draft === null
            ? $this->pages()->resolve($slug, $requested['locale'])
            : new \Click\Cms\Domain\Content\ResolvedContent(
                $draft,
                $requested['locale'],
                $requested['locale']
            );

        if ($resolved === null) {
            return ['status' => 404, 'error' => 'Page not found'];
        }

        $page = $resolved->content;

        $response = ['data' => $page->toArray()] + $resolved->toArray();

        if ($signedIn) {
            $response['publication'] = $this->publicationOf($page);
        }

        // Which other languages this page exists in, so an editor sees at a
        // glance what is still untranslated.
        $response['availableLocales'] = array_map(
            static fn ($l): string => $l->code,
            $this->contentService()->translationsOf('page', $slug)
        );

        // Every image the page references, resolved.
        //
        // Sections store a media reference, and looking one up is a management
        // endpoint. Without this a front end reading the delivery API has an
        // identifier it cannot turn into a srcset — it would have to guess the
        // variant names, and guessing produces URLs for sizes that were never
        // generated, because an upload narrower than a rung is not scaled up.
        $media = $this->resolveMediaReferences($page->data['sections'] ?? []);
        if ($media !== []) {
            $response['media'] = $media;
        }

        return $response;
    }

    /**
     * Collect and resolve every media reference used by a page's sections.
     *
     * @param mixed $sections
     * @return array<string, array<string, mixed>>
     */
    private function resolveMediaReferences(mixed $sections): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $ids = [];
        array_walk_recursive(
            $sections,
            static function (mixed $value) use (&$ids): void {
                // Media identifiers are generated by this application and always
                // take this shape, so anything else is ordinary text.
                if (is_string($value) && preg_match('/^[a-z0-9][a-z0-9-]*-[a-f0-9]{8}$/', $value) === 1) {
                    $ids[$value] = true;
                }
            }
        );

        $resolved = [];
        foreach (array_keys($ids) as $id) {
            $item = $this->media()->find($id);
            if ($item === null) {
                continue;
            }

            $resolved[$id] = [
                'urls' => $item->urls(),
                'srcset' => $item->srcset(),
                'width' => $item->width,
                'height' => $item->height,
                'alt' => $item->alt,
            ];
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function createPage(): array
    {
        return $this->pageResponse(
            $this->pages()->create($this->jsonBody(), $this->currentUser(), $this->localeParam())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePage(string $slug): array
    {
        return $this->pageResponse(
            $this->pages()->update($slug, $this->jsonBody(), $this->currentUser(), $this->localeParam())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePage(string $slug): array
    {
        $locale = $this->localeParam();
        $result = $this->pages()->delete($slug, $this->currentUser(), $locale);

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        return ['data' => [
            'deleted' => true,
            'slug' => $slug,
            'locale' => $this->pages()->parseLocale($locale)['locale']?->code,
        ]];
    }

    /**
     * Put this page, in this language, in front of the public.
     *
     * @return array<string, mixed>
     */
    public function publishPage(string $slug): array
    {
        return $this->publicationResponse(
            $slug,
            $this->pages()->publish($slug, $this->currentUser(), $this->localeParam())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function unpublishPage(string $slug): array
    {
        return $this->publicationResponse(
            $slug,
            $this->pages()->unpublish($slug, $this->currentUser(), $this->localeParam())
        );
    }

    /**
     * @param array{page: ?\Click\Cms\Domain\Content\Content, error: ?string, status: int, errors: array<string, string>} $result
     * @return array<string, mixed>
     */
    private function publicationResponse(string $slug, array $result): array
    {
        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        // The state afterwards rather than a bare "done". The editor's next
        // question is always whether anything is still pending, and answering
        // it here saves a round trip that would otherwise race the write.
        return [
            'status' => $result['status'],
            'data' => [
                'page' => $result['page']->toArray(),
                'publication' => $this->pages()
                    ->publicationOf($slug, $this->localeParam())
                    ->toArray(),
            ],
        ];
    }

    /**
     * Where a page stands, as a plain array for a JSON response.
     *
     * @return array<string, mixed>
     */
    private function publicationOf(\Click\Cms\Domain\Content\Content $page): array
    {
        return $this->contentService()->publicationOf($page->key)->toArray();
    }

    /**
     * The `?locale=` of the current request, unparsed.
     *
     * Query string rather than a path segment because the management API is
     * addressed by slug and adding a segment would change every existing URL.
     */
    private function localeParam(): ?string
    {
        $locale = $_GET['locale'] ?? null;

        return is_string($locale) && trim($locale) !== '' ? $locale : null;
    }

    /**
     * @return array{locale: ?\Click\Cms\Domain\ValueObjects\Locale, error: ?string}
     */
    private function requestedLocale(): array
    {
        return $this->pages()->parseLocale($this->localeParam());
    }

    /**
     * Hand back a signed, expiring link that shows this page as it stands.
     *
     * The capability is checked here rather than in the kernel's path rules on
     * purpose. Those rules match on prefixes, and a check that lives next to
     * the operation cannot be missed by a route added later — which is the
     * failure the core docs single out as still open.
     *
     * @return array<string, mixed>
     */
    public function createPreviewLink(string $slug): array
    {
        $user = $this->currentUser();

        // Authentication has already run, but this endpoint is reached through
        // the `pages` prefix, which is otherwise readable without a session.
        // Not re-asserting it here would depend on a rule stated somewhere else.
        if ($user === []) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::PreviewContent)) {
            return ['status' => 403, 'error' => 'You do not have permission to share a preview of this page.'];
        }

        $locale = $this->requestedLocale();
        if ($locale['error'] !== null) {
            return ['status' => 400, 'error' => $locale['error']];
        }

        $key = ContentKey::page($slug, $locale['locale']);

        // The working copy, because that is what the link will render and an
        // unpublished page is the main thing anybody mints one for. Asking
        // whether the document is live would refuse a link to precisely the
        // work preview exists to show.
        //
        // Exactly this translation, with no fallback: a link minted for a German
        // page that does not exist yet would otherwise verify and then show the
        // English one, which is how a translation gets approved without anybody
        // having read it.
        if ($this->contentService()->draft($key) === null) {
            return ['status' => 404, 'error' => 'Page not found'];
        }

        $link = $this->previewLinks()->issue($key, $this->config?->defaultLocale());

        if ($link === null) {
            // Better to say so than to return a link that will not verify.
            return ['status' => 500, 'error' => 'A preview link could not be signed. Check that data/ is writable.'];
        }

        return ['data' => [
            'url' => $link['path'],
            'expiresAt' => $link['expiresAt'],
            'expiresInSeconds' => max(0, $link['expiresAt'] - time()),
        ]];
    }

    /**
     * @param array{page: mixed, error: ?string, status: int, errors: array<string, string>} $result
     * @return array<string, mixed>
     */
    private function pageResponse(array $result): array
    {
        if ($result['error'] !== null) {
            $response = ['status' => $result['status'], 'error' => $result['error']];

            // Field-level messages are keyed "<sectionIndex>.<fieldName>" so the
            // editor can put each one against the input that caused it.
            if ($result['errors'] !== []) {
                $response['errors'] = $result['errors'];
            }

            return $response;
        }

        return ['status' => $result['status'], 'data' => $result['page']->toArray()];
    }

    /* ----------------------------------------------------------- history -- */

    /**
     * @return array<string, mixed>
     */
    public function listPageVersions(string $slug): array
    {
        // Versions belong to one translation. Without the locale this answered
        // for the default language whatever was asked for, so a German page
        // showed English history — and, far worse, restoring from it rewound the
        // English working copy.
        $locale = $this->requestedLocale();
        if ($locale['error'] !== null) {
            return ['status' => 400, 'error' => $locale['error']];
        }

        $result = $this->history()->all(ContentKey::page($slug, $locale['locale']), $this->currentUser());

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        return ['data' => $result['versions']];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageVersion(string $slug, string $id): array
    {
        $locale = $this->requestedLocale();
        if ($locale['error'] !== null) {
            return ['status' => 400, 'error' => $locale['error']];
        }

        $result = $this->history()->get(ContentKey::page($slug, $locale['locale']), $id, $this->currentUser());

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        return ['data' => $result['version']->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function restorePageVersion(string $slug, string $id): array
    {
        $locale = $this->requestedLocale();
        if ($locale['error'] !== null) {
            return ['status' => 400, 'error' => $locale['error']];
        }

        $result = $this->history()->restore(
            ContentKey::page($slug, $locale['locale']),
            $id,
            $this->currentUser()
        );

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        // The page as it now stands, so the editor sees the result of the
        // restore rather than the version they asked for — those differ in
        // their timestamps, and showing the old one invites the reader to think
        // nothing happened. In the language that was restored, not the default
        // one, or a German restore would answer with the English page and look
        // as though it had done nothing.
        $page = $this->pages()->find($slug, $locale['locale']);

        return [
            'data' => [
                'restoredFrom' => $result['version']->summary(),
                'page' => $page?->toArray(),
                // What the restored content no longer fits in the current schema,
                // so the editor can fix it before publishing rather than after a
                // section silently fails to render on the live page. Empty when
                // everything still fits, which is the ordinary case.
                'warnings' => $result['warnings']?->toArray(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $decoded = json_decode(file_get_contents('php://input') ?: '[]', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentUser(): array
    {
        // Authentication has already run by the time a handler is reached; this
        // only needs the identity for ownership checks. Read through the store
        // so it is the caller's own session, not whichever happens to be on
        // disk.
        $store = new \Click\Cms\Application\Authentication\SessionStore(
            $this->basePath . '/data/sessions'
        );

        return $store->user() ?? [];
    }

    /* ------------------------------------------------------------ schema -- */

    /**
     * @return array<string, mixed>
     */
    public function listSectionTypes(): array
    {
        $repo = $this->sectionTypes();

        $response = [
            'data' => array_map(
                static fn (SectionType $type): array => $type->toArray(),
                $repo->all()
            ),
        ];

        // Surface malformed definitions rather than pretending they are absent,
        // so an author notices a typo instead of wondering where a type went.
        $errors = $repo->errors();
        if ($errors !== []) {
            $response['warnings'] = $errors;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSectionType(string $id): array
    {
        $type = $this->sectionTypes()->find($id);

        return $type === null
            ? ['status' => 404, 'error' => 'Section type not found']
            : ['data' => $type->toArray()];
    }

    /**
     * Validate a section payload against its declared type.
     *
     * @param array<string, mixed> $values
     * @return array{valid: bool, values: array<string, mixed>, errors: array<string, string>}
     */
    public function validateSection(string $typeId, array $values): array
    {
        $type = $this->sectionTypes()->find($typeId);

        if ($type === null) {
            return [
                'valid' => false,
                'values' => [],
                'errors' => ['type' => "Unknown section type \"{$typeId}\"."],
            ];
        }

        $result = (new SectionValidator())->validate($type, $values);

        return [
            'valid' => $result->isValid(),
            'values' => $result->values,
            'errors' => $result->errors,
        ];
    }

    /* ------------------------------------------------------------- media -- */

    /**
     * @return array<string, mixed>
     */
    public function mediaCapabilities(): array
    {
        return [
            'data' => [
                'acceptedMimeTypes' => UploadPolicy::acceptedMimeTypes(),
                'maxBytes' => UploadPolicy::MAX_BYTES,
                'resizingAvailable' => GdImageProcessor::isAvailable(),
                'variants' => array_map(
                    static fn (ImageSize $s): array => [
                        'name' => $s->value,
                        'label' => $s->label(),
                        'width' => $s->width(),
                    ],
                    ImageSize::ladder()
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMedia(): array
    {
        // Search, virtual-folder filtering, and the display-width verdict (an
        // image field declares the width it displays at, so the "too small for
        // this slot" wording comes from the domain rather than each client) all
        // live in MediaLibrary now, so the query string is handed straight to it.
        return $this->mediaLibrary()->list($_GET);
    }

    /**
     * Delete several media items at once. A management action — MediaLibrary
     * enforces the capability itself against the caller's role and reports every
     * requested id, so an already-gone id is a no-op rather than an error.
     */
    public function bulkDeleteMedia(): array
    {
        $ids = $this->jsonBody()['ids'] ?? [];

        return $this->mediaLibrary()->bulkDelete(is_array($ids) ? $ids : []);
    }

    private function mediaLibrary(): MediaLibrary
    {
        // The role is resolved per request rather than captured, so a handler
        // that runs after a session change still sees the current caller.
        return $this->mediaLibrary ??= new MediaLibrary(
            $this->media(),
            fn (): Role => Role::fromName($this->currentUser()['role'] ?? null),
        );
    }

    /**
     * The display width a client asked media to be judged against, if any.
     *
     * Anything unparseable or non-positive is treated as absent: a bad query
     * string should fall back to the general verdict, never to a wrong one.
     */
    private function requestedDisplayWidth(): ?int
    {
        $raw = $_GET['displayWidth'] ?? null;

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $width = (int) $raw;

        return $width > 0 ? $width : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMedia(string $id): array
    {
        $item = $this->media()->find($id);

        return $item === null
            ? ['status' => 404, 'error' => 'Media not found']
            : ['data' => $item->toArray($this->requestedDisplayWidth())];
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadMedia(): array
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return ['status' => 400, 'error' => 'No file was uploaded.'];
        }

        $result = $this->media()->store($_FILES['file']);

        if ($result['item'] === null) {
            return ['status' => 422, 'error' => $result['error']];
        }

        return ['status' => 201, 'data' => $result['item']->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateMedia(string $id): array
    {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        $body = is_array($body) ? $body : [];

        if ($this->media()->find($id) === null) {
            return ['status' => 404, 'error' => 'Media not found'];
        }

        // Each field is applied only when it is present, so a request that sets
        // just the focal point does not blank the alt text it did not mention,
        // and the reverse. Updating one thing must not erase another.
        $item = null;
        if (array_key_exists('alt', $body)) {
            $item = $this->media()->updateAlt($id, (string) $body['alt']);
        }

        if (isset($body['focalPoint']) && is_array($body['focalPoint'])) {
            $point = $body['focalPoint'];
            try {
                $item = $this->media()->updateFocalPoint(
                    $id,
                    (float) ($point['x'] ?? 0.5),
                    (float) ($point['y'] ?? 0.5)
                );
            } catch (\InvalidArgumentException $e) {
                // A focal point outside the image is a client bug, not a server
                // one — say what was wrong rather than storing nonsense.
                return ['status' => 422, 'error' => $e->getMessage()];
            }
        }

        // Nothing recognised to change: return the item as it stands rather than
        // treating an empty update as an error.
        $item ??= $this->media()->find($id);

        return ['data' => $item?->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteMedia(string $id): array
    {
        return $this->media()->delete($id)
            ? ['data' => ['deleted' => true]]
            : ['status' => 404, 'error' => 'Media not found'];
    }

    /**
     * Stream a stored file.
     *
     * Only names the library could itself have generated resolve to a path, so
     * a crafted filename cannot reach anything outside the media directory.
     *
     * @return array<string, mixed>
     */
    public function serveMediaFile(string $filename): array
    {
        $path = $this->media()->pathForFile($filename);

        if ($path === null) {
            return ['status' => 404, 'error' => 'File not found'];
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        $info = @getimagesize($path);
        $mimeType = is_array($info) ? ($info['mime'] ?? '') : '';

        // getimagesize cannot read SVG or video, so those resolve to an empty
        // mime and would 404. Their name was validated by pathForFile and their
        // type is fixed by the stored extension, so they are served as that
        // declared type rather than being sniffed.
        $isSvg = $mimeType === '' && $extension === 'svg';
        if ($isSvg) {
            $mimeType = 'image/svg+xml';
        }

        $isVideo = $mimeType === '' && UploadPolicy::isVideoExtension($extension);
        if ($isVideo) {
            $mimeType = $extension === 'webm' ? 'video/webm' : 'video/mp4';
        }

        if (!UploadPolicy::isAccepted($mimeType)) {
            return ['status' => 404, 'error' => 'File not found'];
        }

        header('Content-Type: ' . $mimeType);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        // Stored names carry random bytes and content never changes under a
        // given name, so this can be cached hard.
        header('Cache-Control: public, max-age=31536000, immutable');

        if ($isSvg) {
            // Defence in depth. The sanitiser is the real boundary, but an SVG
            // is a same-origin document when served inline, so a strict policy
            // makes any gap the sanitiser ever has non-executable rather than
            // trusting it alone.
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        // Video is served with byte-range support so a browser can seek and
        // start playback before the whole file arrives — Safari in particular
        // will not play a video that ignores its Range request.
        if ($isVideo) {
            $this->serveWithRanges($path);
            return ['raw' => true, 'html' => ''];
        }

        header('Content-Length: ' . (string) filesize($path));
        readfile($path);

        return ['raw' => true, 'html' => ''];
    }

    /**
     * Stream a file, honouring a single HTTP Range request with a 206 response
     * so a video can be sought and progressively played. A malformed or absent
     * Range falls back to the whole file.
     */
    private function serveWithRanges(string $path): void
    {
        $size = filesize($path) ?: 0;
        header('Accept-Ranges: bytes');

        $range = $_SERVER['HTTP_RANGE'] ?? '';
        $start = 0;
        $end = $size - 1;

        if (is_string($range) && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) === 1 && $size > 0) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }
            if ($m[2] !== '') {
                $end = (int) $m[2];
            }
            // An unsatisfiable range is answered as such rather than served wrong.
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                return;
            }
            $end = min($end, $size - 1);
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }

        $length = $end - $start + 1;
        header('Content-Length: ' . (string) $length);

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, (int) min(8192, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
    }

    /* ------------------------------------------------------------ wiring -- */

    private function sectionTypes(): JsonSectionTypeRepository
    {
        return $this->sectionTypes ??= new JsonSectionTypeRepository(
            $this->basePath . '/config/sections'
        );
    }

    private function pages(): PageService
    {
        return $this->pages ??= new PageService(
            $this->contentService(),
            $this->sectionTypes(),
            new SectionValidator(),
            $this->config?->locales() ?? [],
        );
    }

    /**
     * The content service used when nothing was injected.
     *
     * Built on the versioned storage rather than a bare backend, so a write that
     * reaches this fallback still leaves history behind — an undo that depends on
     * which of two construction paths ran is not an undo. It is told the default
     * locale for the same reason the boot path is: documents written before
     * languages carry no language of their own.
     */
    private function contentService(): ContentService
    {
        return $this->contentService ??= $this->content ?? new ContentService(
            $this->storage(),
            $this->config?->defaultLocale()
        );
    }

    private function history(): HistoryService
    {
        // The section types are passed so a restore can report content the
        // current schema no longer declares — a version restored verbatim may
        // hold a section whose design has since been removed, and the editor
        // needs telling before they publish it into a page that will not render.
        return $this->history ??= new HistoryService(
            $this->storage(),
            $this->versions(),
            $this->sectionTypes()
        );
    }

    /**
     * The storage used when nothing was injected.
     *
     * Versioning is applied here and not only in {@see \Click\Cms\Core\Application}
     * so that a write reaching this fallback still leaves history behind. An
     * undo that depends on which of two construction paths ran is not an undo.
     */
    private function storage(): VersioningStorage
    {
        return $this->storage ??= new VersioningStorage(
            new JsonStorage($this->basePath . '/content', $this->config?->defaultLocale()),
            $this->versions(),
        );
    }

    private function versions(): JsonVersionStore
    {
        return $this->versions ??= new JsonVersionStore(
            $this->basePath . '/data/versions',
            RetentionPolicy::keeping(
                CoreConfig::load($this->basePath . '/config/core.json')->historyRetainedVersions()
            ),
        );
    }

    private function previewLinks(): PreviewLinks
    {
        // Outside the document root, alongside sessions: holding this key means
        // being able to mint a link to any unpublished page.
        return $this->previewLinks ??= new PreviewLinks(
            $this->basePath . '/data/preview-secret'
        );
    }

    private function media(): MediaService
    {
        return $this->media ??= new MediaService(
            $this->basePath . '/content/media',
            crops: $this->config?->mediaCrops() ?? [],
        );
    }
}
