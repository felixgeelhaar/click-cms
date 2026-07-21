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

        $pages = $this->pages()->all($locale['locale']);

        // Anonymous callers see published pages only.
        //
        // This endpoint is deliberately public — a headless front end reads it
        // with no account — but it was returning drafts to anyone who asked,
        // which made every unpublished page world-readable and would have made
        // signed preview links pointless. Editing is unaffected: the admin UI
        // has a session and still sees everything.
        if ($this->currentUser() === []) {
            $pages = array_values(array_filter(
                $pages,
                static fn ($page): bool => $page->isPublished()
            ));
        }

        return [
            'data' => array_map(
                static fn ($page): array => $page->toArray(),
                $pages
            ),
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

        // Read with fallback: a front end asking for a language that has no
        // translation should get the page rather than a 404. What it must not
        // get is the fallback without being told, so the served language and
        // the fact that it was a fallback are part of the response — a client
        // that shows German chrome around English prose is at least able to
        // know it is doing so.
        $resolved = $this->pages()->resolve($slug, $requested['locale']);

        if ($resolved === null) {
            return ['status' => 404, 'error' => 'Page not found'];
        }

        $page = $resolved->content;

        // Not found rather than forbidden: telling an anonymous caller that a
        // page exists but is unpublished leaks the thing being protected —
        // which slugs are being worked on — even while withholding the content.
        if (!$page->isPublished() && $this->currentUser() === []) {
            return ['status' => 404, 'error' => 'Page not found'];
        }

        $response = ['data' => $page->toArray()] + $resolved->toArray();

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

        if ($this->pages()->find($slug) === null) {
            return ['status' => 404, 'error' => 'Page not found'];
        }

        $link = $this->previewLinks()->issue($slug);

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
        $result = $this->history()->all(ContentKey::page($slug), $this->currentUser());

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
        $result = $this->history()->get(ContentKey::page($slug), $id, $this->currentUser());

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
        $result = $this->history()->restore(ContentKey::page($slug), $id, $this->currentUser());

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        // The page as it now stands, so the editor sees the result of the
        // restore rather than the version they asked for — those differ in
        // their timestamps, and showing the old one invites the reader to think
        // nothing happened.
        $page = $this->pages()->find($slug);

        return [
            'data' => [
                'restoredFrom' => $result['version']->summary(),
                'page' => $page?->toArray(),
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
        // An image field that declares the width it displays at asks for the
        // library through this parameter, so the "too small for this slot"
        // verdict and its wording come from the domain rather than being
        // recomputed — and worded differently — in every client.
        $displayWidth = $this->requestedDisplayWidth();

        return [
            'data' => array_map(
                static fn ($item): array => $item->toArray($displayWidth),
                $this->media()->all()
            ),
        ];
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
        $alt = is_array($body) ? (string) ($body['alt'] ?? '') : '';

        $item = $this->media()->updateAlt($id, $alt);

        return $item === null
            ? ['status' => 404, 'error' => 'Media not found']
            : ['data' => $item->toArray()];
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

        $info = @getimagesize($path);
        $mimeType = is_array($info) ? ($info['mime'] ?? '') : '';

        if (!UploadPolicy::isAccepted($mimeType)) {
            return ['status' => 404, 'error' => 'File not found'];
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        // Stored names carry random bytes and content never changes under a
        // given name, so this can be cached hard.
        header('Cache-Control: public, max-age=31536000, immutable');

        readfile($path);

        return ['raw' => true, 'html' => ''];
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
        return $this->history ??= new HistoryService($this->storage(), $this->versions());
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
        return $this->media ??= new MediaService($this->basePath . '/content/media');
    }
}
