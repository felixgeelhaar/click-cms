<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Media\ImageSize;
use Click\Cms\Domain\Media\UploadPolicy;
use Click\Cms\Infrastructure\Media\GdImageProcessor;

/**
 * The media library.
 *
 * Uploads are stored outside the document root and streamed through this
 * plugin, so nothing uploaded is directly reachable — and therefore never
 * directly executable — whatever it turns out to contain.
 *
 * Every accepted image gets a ladder of responsive variants (-sm, -md, -lg,
 * -xl) generated once at upload time, so a front end can emit a srcset without
 * resizing on every request.
 */
class Plugin_media_library extends \Click\Cms\Application\Plugin\BasePlugin
{
    private ?MediaService $service = null;

    public function getPluginId(): string
    {
        return 'media-library';
    }

    public function getPluginName(): string
    {
        return 'Media Library';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function deactivate(): bool
    {
        $this->service = null;

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /**
     * @return array<string, callable>
     */
    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/media' => [$this, 'listMedia'],
            'POST /api/media' => [$this, 'uploadMedia'],
            'GET /api/media/capabilities' => [$this, 'capabilities'],
            'GET /api/media/file/:filename' => [$this, 'serveFile'],
            'GET /api/media/:id' => [$this, 'getMedia'],
            'PUT /api/media/:id' => [$this, 'updateMedia'],
            'DELETE /api/media/:id' => [$this, 'deleteMedia'],
        ];
    }

    /**
     * What the library accepts, so the UI can state its limits up front rather
     * than only after an upload has been rejected.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
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
                $this->service()->all()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMedia(string $id): array
    {
        $item = $this->service()->find($id);

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

        $result = $this->service()->store($_FILES['file']);

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

        $item = $this->service()->updateAlt($id, $alt);

        return $item === null
            ? ['status' => 404, 'error' => 'Media not found']
            : ['data' => $item->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteMedia(string $id): array
    {
        return $this->service()->delete($id)
            ? ['data' => ['deleted' => true]]
            : ['status' => 404, 'error' => 'Media not found'];
    }

    /**
     * Stream a stored file.
     *
     * Only names this library could itself have generated resolve to a path, so
     * a crafted filename cannot reach anything outside the media directory.
     *
     * @return array<string, mixed>
     */
    public function serveFile(string $filename): array
    {
        $path = $this->service()->pathForFile($filename);

        if ($path === null) {
            return ['status' => 404, 'error' => 'File not found'];
        }

        $info = @getimagesize($path);
        $mimeType = is_array($info) ? ($info['mime'] ?? '') : '';

        // Served only as a type the library itself accepts, and never sniffed
        // into something else by the browser.
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

    private function service(): MediaService
    {
        return $this->service ??= new MediaService(
            $this->pluginManager->getBasePath() . '/content/media'
        );
    }
}
