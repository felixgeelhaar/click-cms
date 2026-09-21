<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Media\ImageSize;
use Click\Cms\Domain\Media\TransformRequest;
use Click\Cms\Domain\Media\UploadPolicy;
use Click\Cms\Infrastructure\Media\GdImageProcessor;

/**
 * Managing and serving the media library.
 *
 * These endpoints are core rather than a plugin because the admin UI cannot place
 * an image without them. Pulled out of {@see CoreApiRoutes} so media stops
 * accumulating beside pages and schema in one file — behaviour is unchanged.
 *
 * Listing and bulk delete reuse {@see MediaLibrary}. File serving stays public
 * (see {@see ApiGuard}); everything else is deny-by-default behind the kernel.
 */
final class MediaController
{
    private ?MediaService $media = null;
    private ?MediaLibrary $mediaLibrary = null;

    /**
     * @param string  $basePath    The site's root — media lives under content/media.
     * @param callable(): array<string, mixed> $currentUser Signed-in user, or [].
     */
    public function __construct(
        private readonly string $basePath,
        private readonly mixed $currentUser,
        private readonly ?CoreConfig $config = null,
        private readonly ?BasePath $urlBase = null,
    ) {
    }

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
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

    /** Where media files are served from, as this installation spells it. */
    private function mediaBaseUrl(): string
    {
        return ($this->urlBase ?? BasePath::root())->url('/api/media/file');
    }

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
        // Search, virtual-folder filtering, and the display-width verdict all
        // live in MediaLibrary, so the query string is handed straight to it.
        return $this->mediaLibrary()->list($_GET);
    }

    /**
     * Delete several media items at once. MediaLibrary enforces the capability
     * against the caller's role and reports every requested id.
     *
     * @return array<string, mixed>
     */
    public function bulkDeleteMedia(): array
    {
        $ids = $this->jsonBody()['ids'] ?? [];

        return $this->mediaLibrary()->bulkDelete(is_array($ids) ? $ids : []);
    }

    private function mediaLibrary(): MediaLibrary
    {
        return $this->mediaLibrary ??= new MediaLibrary(
            $this->media(),
            fn (): Role => Role::fromName(($this->user())['role'] ?? null),
            $this->mediaBaseUrl(),
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
            ? ApiFault::of(404, 'Media not found', 'not_found')
            : ['data' => $item->toArray($this->requestedDisplayWidth(), $this->mediaBaseUrl())];
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadMedia(): array
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return ApiFault::of(400, 'No file was uploaded.', 'bad_request');
        }

        $result = $this->media()->store($_FILES['file']);

        if ($result['item'] === null) {
            return ['status' => 422, 'error' => $result['error']];
        }

        return ['status' => 201, 'data' => $result['item']->toArray(null, $this->mediaBaseUrl())];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateMedia(string $id): array
    {
        $body = $this->jsonBody();

        if ($this->media()->find($id) === null) {
            return ApiFault::of(404, 'Media not found', 'not_found');
        }

        // Each field is applied only when it is present, so a request that sets
        // just the focal point does not blank the alt text it did not mention.
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
                return ['status' => 422, 'error' => $e->getMessage()];
            }
        }

        $item ??= $this->media()->find($id);

        return ['data' => $item?->toArray(null, $this->mediaBaseUrl())];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteMedia(string $id): array
    {
        return $this->media()->delete($id)
            ? ['data' => ['deleted' => true]]
            : ApiFault::of(404, 'Media not found', 'not_found');
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
        // `?w=` asks for a width the ladder may not hold. The width is snapped to
        // a fixed set before anything is rendered — an unbounded width parameter
        // is a denial-of-service vector.
        $transform = TransformRequest::fromQuery($_GET['w'] ?? null);
        $path = $this->media()->pathForFileAtWidth($filename, $transform);

        if ($path === null) {
            return ApiFault::of(404, 'File not found', 'not_found');
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        $info = @getimagesize($path);
        $mimeType = is_array($info) ? ($info['mime'] ?? '') : '';

        // getimagesize cannot read SVG or video, so those resolve to an empty
        // mime and would 404. Their name was validated by pathForFile and their
        // type is fixed by the stored extension.
        $isSvg = $mimeType === '' && $extension === 'svg';
        if ($isSvg) {
            $mimeType = 'image/svg+xml';
        }

        $isVideo = $mimeType === '' && UploadPolicy::isVideoExtension($extension);
        if ($isVideo) {
            $mimeType = $extension === 'webm' ? 'video/webm' : 'video/mp4';
        }

        if (!UploadPolicy::isAccepted($mimeType)) {
            return ApiFault::of(404, 'File not found', 'not_found');
        }

        header('Content-Type: ' . $mimeType);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        // Stored names carry random bytes and content never changes under a
        // given name, so this can be cached hard.
        header('Cache-Control: public, max-age=31536000, immutable');

        if ($isSvg) {
            // Defence in depth. The sanitiser is the real boundary, but an SVG
            // is a same-origin document when served inline.
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        // Video is served with byte-range support so a browser can seek and
        // start playback before the whole file arrives.
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
    private function user(): array
    {
        $user = ($this->currentUser)();

        return is_array($user) ? $user : [];
    }

    private function media(): MediaService
    {
        return $this->media ??= new MediaService(
            $this->basePath . '/content/media',
            crops: $this->config?->mediaCrops() ?? [],
        );
    }
}
