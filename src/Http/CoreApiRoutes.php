<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Media\ImageSize;
use Click\Cms\Domain\Media\UploadPolicy;
use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Media\GdImageProcessor;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

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
    private ?PreviewLinks $previewLinks = null;

    public function __construct(
        private readonly string $basePath,
        private readonly ?ContentService $content = null,
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
        $pages = $this->pages()->all();

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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPage(string $slug): array
    {
        $page = $this->pages()->find($slug);

        if ($page === null) {
            return ['status' => 404, 'error' => 'Page not found'];
        }

        // Not found rather than forbidden: telling an anonymous caller that a
        // page exists but is unpublished leaks the thing being protected —
        // which slugs are being worked on — even while withholding the content.
        if (!$page->isPublished() && $this->currentUser() === []) {
            return ['status' => 404, 'error' => 'Page not found'];
        }

        $response = ['data' => $page->toArray()];

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
            $this->pages()->create($this->jsonBody(), $this->currentUser())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePage(string $slug): array
    {
        return $this->pageResponse(
            $this->pages()->update($slug, $this->jsonBody(), $this->currentUser())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePage(string $slug): array
    {
        $result = $this->pages()->delete($slug, $this->currentUser());

        if ($result['error'] !== null) {
            return ['status' => $result['status'], 'error' => $result['error']];
        }

        return ['data' => ['deleted' => true, 'slug' => $slug]];
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
        return [
            'data' => array_map(
                static fn ($item): array => $item->toArray(),
                $this->media()->all()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMedia(string $id): array
    {
        $item = $this->media()->find($id);

        return $item === null
            ? ['status' => 404, 'error' => 'Media not found']
            : ['data' => $item->toArray()];
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
            $this->content ?? new ContentService(
                new \Click\Cms\Infrastructure\Storage\JsonStorage($this->basePath . '/content')
            ),
            $this->sectionTypes()
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
