<?php

declare(strict_types=1);

namespace Click\Cms\Application\Media;

use Click\Cms\Domain\Media\CropBox;
use Click\Cms\Domain\Media\FocalPoint;
use Click\Cms\Domain\Media\MediaItem;
use Click\Cms\Domain\Media\SvgSanitizer;
use Click\Cms\Domain\Media\UploadPolicy;
use Click\Cms\Infrastructure\Media\GdImageProcessor;
use RuntimeException;

/**
 * Stores uploads and generates their responsive variants.
 *
 * Files live outside the document root and are served through the application,
 * so nothing uploaded is ever directly reachable — and therefore never directly
 * executable — regardless of what it turns out to contain.
 */
final class MediaService
{
    /**
     * @param list<CropBox> $crops The art-directed crops this site declares, cut
     *        focal-point-aware at upload and recut when the point moves. Empty by
     *        default, so a site that declares none keeps just the ladder and the
     *        square.
     */
    public function __construct(
        private readonly string $mediaDir,
        private readonly GdImageProcessor $processor = new GdImageProcessor(),
        private readonly SvgSanitizer $svgSanitizer = new SvgSanitizer(),
        private readonly array $crops = [],
    ) {}

    /**
     * Cut every declared crop from the stored original around a focal point, and
     * return the boxes actually produced, keyed by crop name. Shared by upload
     * (centre focal) and a focal-point move (the new point), so the two can never
     * drift apart. A crop the processor cannot cut — an SVG, or GD absent —
     * simply does not appear, keeping the metadata honest.
     *
     * @return array<string, array{width: int, height: int}>
     */
    private function cutCrops(string $sourcePath, string $id, string $extension, float $focalX, float $focalY): array
    {
        $out = [];
        foreach ($this->crops as $crop) {
            $dims = $this->processor->generateCrop(
                $sourcePath,
                $this->mediaDir,
                $id,
                $extension,
                $crop->name,
                $crop->aspectWidth,
                $crop->aspectHeight,
                $focalX,
                $focalY
            );
            if ($dims !== null) {
                $out[$crop->name] = $dims;
            }
        }

        return $out;
    }

    /**
     * Accept an uploaded file.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array{item: ?MediaItem, error: ?string}
     */
    public function store(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['item' => null, 'error' => $this->describeUploadError((int) ($file['error'] ?? -1))];
        }

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_readable($tmp)) {
            return ['item' => null, 'error' => 'The upload could not be read.'];
        }

        $bytes = (int) ($file['size'] ?? 0);
        if ($bytes <= 0) {
            return ['item' => null, 'error' => 'The file is empty.'];
        }
        // The absolute ceiling (video's), so a huge file is rejected early; the
        // precise per-type limit is applied once the content type is known.
        if ($bytes > UploadPolicy::MAX_VIDEO_BYTES) {
            $limit = (int) (UploadPolicy::MAX_VIDEO_BYTES / 1024 / 1024);
            return ['item' => null, 'error' => "Files must be smaller than {$limit} MB."];
        }

        // SVG is handled before the raster path because getimagesize() cannot
        // read one, and because it is the one upload that can carry executable
        // script — it is accepted only through the sanitiser, and what is stored
        // is the sanitiser's output, never the raw upload.
        $raw = @file_get_contents($tmp);
        if ($raw !== false && SvgSanitizer::looksLikeSvg($raw)) {
            if ($bytes > UploadPolicy::MAX_BYTES) {
                return ['item' => null, 'error' => $this->sizeError('image/svg+xml')];
            }
            return $this->storeSvg($raw, (string) ($file['name'] ?? ''));
        }

        // Video is content-detected and stored as-is — no getimagesize, no
        // variant ladder, no crop. It gets its own (larger) size ceiling.
        $detected = $this->detectMime($tmp);
        if (UploadPolicy::isVideo($detected)) {
            if ($bytes > UploadPolicy::maxBytesFor($detected)) {
                return ['item' => null, 'error' => $this->sizeError($detected)];
            }
            return $this->storeVideo($tmp, $detected, (string) ($file['name'] ?? ''), $bytes);
        }

        // Type comes from the file's content. The browser-supplied type and the
        // filename are both attacker-controlled and are never trusted.
        $info = $this->processor->inspect($tmp);
        if ($info === null) {
            return ['item' => null, 'error' => 'That file is not a readable image.'];
        }

        $mimeType = $info['mimeType'];
        if (!UploadPolicy::isAccepted($mimeType)) {
            return ['item' => null, 'error' => UploadPolicy::refusalReason($mimeType)];
        }
        if ($bytes > UploadPolicy::maxBytesFor($mimeType)) {
            return ['item' => null, 'error' => $this->sizeError($mimeType)];
        }

        $extension = UploadPolicy::extensionFor($mimeType);
        if ($extension === null) {
            return ['item' => null, 'error' => UploadPolicy::refusalReason($mimeType)];
        }

        // The stored name is generated: a readable slug for humans plus random
        // bytes for uniqueness. Nothing the uploader chose reaches the path.
        $id = UploadPolicy::slugFor((string) ($file['name'] ?? '')) . '-' . bin2hex(random_bytes(4));

        $this->ensureDir();
        $target = $this->mediaDir . '/' . $id . '.' . $extension;

        if (!$this->moveUpload($tmp, $target)) {
            return ['item' => null, 'error' => 'The file could not be saved.'];
        }

        // Uploaded files are data, never programs.
        @chmod($target, 0o644);

        $variants = $this->processor->generateVariants($target, $this->mediaDir, $id, $extension);

        // A focal-point-centred square crop rides alongside the ladder for a
        // layout that needs a fixed box. Generated with the default centre focal
        // point at upload; recut when the editor moves the point. Additional to
        // the ladder, never a replacement — the ladder above is untouched.
        $squareCrop = $this->processor->generateSquareCrop(
            $target, $this->mediaDir, $id, $extension, 0.5, 0.5
        );

        // Every declared art-directed crop, cut around the default centre focal
        // point at upload and recut when the editor moves it.
        $crops = $this->cutCrops($target, $id, $extension, 0.5, 0.5);

        $item = MediaItem::create(
            id: $id,
            extension: $extension,
            mimeType: $mimeType,
            originalName: (string) ($file['name'] ?? ''),
            bytes: filesize($target) ?: $bytes,
            width: $info['width'],
            height: $info['height'],
            variants: $variants,
            squareCrop: $squareCrop,
            crops: $crops,
        );

        $this->writeMetadata($item);

        return ['item' => $item, 'error' => null];
    }

    /**
     * Store an SVG, but only its sanitised form.
     *
     * The bytes are run through the sanitiser first; an SVG that cannot be made
     * safe is refused rather than stored, exactly as a raster that fails
     * inspection is. What reaches disk is the cleaned markup, so even the served
     * original carries no script. An SVG is resolution-independent, so there is
     * no dimension to read, no variant ladder to build and no crop to cut — it
     * serves as-is.
     *
     * @return array{item: ?MediaItem, error: ?string}
     */
    private function storeSvg(string $raw, string $originalName): array
    {
        $clean = $this->svgSanitizer->sanitize($raw);
        if ($clean === null) {
            return ['item' => null, 'error' => UploadPolicy::svgRefusalReason()];
        }

        $id = UploadPolicy::slugFor($originalName) . '-' . bin2hex(random_bytes(4));

        $this->ensureDir();
        $target = $this->mediaDir . '/' . $id . '.svg';

        if (@file_put_contents($target, $clean, LOCK_EX) === false) {
            return ['item' => null, 'error' => 'The file could not be saved.'];
        }

        // Data, never a program — the same posture every stored upload gets.
        @chmod($target, 0o644);

        $item = MediaItem::create(
            id: $id,
            extension: 'svg',
            mimeType: 'image/svg+xml',
            originalName: $originalName,
            bytes: strlen($clean),
            // No raster dimensions: an SVG scales without them, and leaving them
            // null is what keeps the pixel-counting quality warning silent.
            width: null,
            height: null,
            variants: [],
        );

        $this->writeMetadata($item);

        return ['item' => $item, 'error' => null];
    }

    /**
     * Store a video: moved to disk verbatim, with no variant ladder, crop or
     * raster dimensions, because the CMS does not transcode. It is served back
     * as-is under its declared type. The name is generated like every other
     * upload, so nothing the uploader chose reaches the path.
     *
     * @return array{item: ?MediaItem, error: ?string}
     */
    private function storeVideo(string $tmp, string $mimeType, string $originalName, int $bytes): array
    {
        $extension = UploadPolicy::extensionFor($mimeType);
        if ($extension === null) {
            return ['item' => null, 'error' => UploadPolicy::refusalReason($mimeType)];
        }

        $id = UploadPolicy::slugFor($originalName) . '-' . bin2hex(random_bytes(4));

        $this->ensureDir();
        $target = $this->mediaDir . '/' . $id . '.' . $extension;

        if (!$this->moveUpload($tmp, $target)) {
            return ['item' => null, 'error' => 'The file could not be saved.'];
        }
        @chmod($target, 0o644);

        $item = MediaItem::create(
            id: $id,
            extension: $extension,
            mimeType: $mimeType,
            originalName: $originalName,
            bytes: filesize($target) ?: $bytes,
            // A video has no raster dimensions to read here and no variant ladder,
            // so these stay null/empty, exactly as an SVG's do.
            width: null,
            height: null,
            variants: [],
        );

        $this->writeMetadata($item);

        return ['item' => $item, 'error' => null];
    }

    /** Content-based MIME detection, used to spot video before the raster path. */
    private function detectMime(string $path): string
    {
        if (!class_exists(\finfo::class)) {
            return '';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? $mime : '';
    }

    /** The size-limit message for a type, in whole megabytes. */
    private function sizeError(string $mimeType): string
    {
        $limit = (int) (UploadPolicy::maxBytesFor($mimeType) / 1024 / 1024);
        $noun = UploadPolicy::isVideo($mimeType) ? 'Videos' : 'Files';

        return "{$noun} must be smaller than {$limit} MB.";
    }

    /**
     * @return list<MediaItem>
     */
    public function all(): array
    {
        if (!is_dir($this->mediaDir)) {
            return [];
        }

        $files = glob($this->mediaDir . '/*.json') ?: [];
        // Newest first: the thing just uploaded is the thing being looked for.
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $items = [];
        foreach ($files as $file) {
            $item = $this->readMetadata($file);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function find(string $id): ?MediaItem
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1) {
            return null;
        }

        return $this->readMetadata($this->mediaDir . '/' . $id . '.json');
    }

    public function updateAlt(string $id, string $alt): ?MediaItem
    {
        $item = $this->find($id);
        if ($item === null) {
            return null;
        }

        $updated = $item->withAlt($alt);
        $this->writeMetadata($updated);

        return $updated;
    }

    /**
     * Mark the point of the image that must stay visible when it is cropped.
     *
     * The focal point drives two things now. It is still metadata a front end
     * honours with CSS `object-position` on the uncropped ladder — that is why
     * validation lives in FocalPoint, so an out-of-range value throws rather than
     * being quietly moved. It also re-cuts the server-side square crop around the
     * new subject, so the fixed-box file always matches the point last marked. A
     * resolution-independent SVG has no crop to recut, so the processor simply
     * reports none for it.
     */
    public function updateFocalPoint(string $id, float $x, float $y): ?MediaItem
    {
        $item = $this->find($id);
        if ($item === null) {
            return null;
        }

        $updated = $item->withFocalPoint(FocalPoint::at($x, $y));

        // Recut the square crop from the stored original around the new point.
        // Returns null for an SVG or when GD is unavailable, which correctly
        // clears the crop rather than leaving a stale one.
        $squareCrop = $this->processor->generateSquareCrop(
            $this->mediaDir . '/' . $item->filename(),
            $this->mediaDir,
            $item->id,
            $item->extension,
            $x,
            $y
        );
        $updated = $updated->withSquareCrop($squareCrop);

        // Recut every declared crop around the new point, so an art-directed box
        // tracks the subject the same way the square does.
        $updated = $updated->withCrops(
            $this->cutCrops($this->mediaDir . '/' . $item->filename(), $item->id, $item->extension, $x, $y)
        );

        $this->writeMetadata($updated);

        return $updated;
    }

    public function delete(string $id): bool
    {
        $item = $this->find($id);
        if ($item === null) {
            return false;
        }

        $this->processor->deleteVariants($this->mediaDir, $item->id, $item->extension);
        @unlink($this->mediaDir . '/' . $item->filename());
        @unlink($this->mediaDir . '/' . $item->id . '.json');

        return true;
    }

    /**
     * Resolve a requested filename to a path on disk.
     *
     * Only names this service could itself have generated are resolvable, so a
     * crafted name cannot reach anything outside the media directory.
     */
    public function pathForFile(string $filename): ?string
    {
        $pattern = '/^(?<id>[a-z0-9][a-z0-9-]*?)(?:-(?:sm|md|lg|xl|square))?\.(?<ext>[a-z0-9]{1,5})$/';

        if (preg_match($pattern, $filename, $m) !== 1) {
            return null;
        }

        if (!in_array($m['ext'], UploadPolicy::acceptedExtensions(), true)) {
            return null;
        }

        $path = $this->mediaDir . '/' . $filename;

        return is_file($path) ? $path : null;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->mediaDir) && !@mkdir($this->mediaDir, 0o775, true) && !is_dir($this->mediaDir)) {
            throw new RuntimeException("Unable to create media directory: {$this->mediaDir}");
        }
    }

    /**
     * move_uploaded_file() only accepts genuine uploads, which is the desired
     * behaviour in production. Tests exercise the same path with ordinary
     * files, so fall back to a rename when there is no upload to move.
     */
    private function moveUpload(string $tmp, string $target): bool
    {
        if (is_uploaded_file($tmp)) {
            return move_uploaded_file($tmp, $target);
        }

        return @rename($tmp, $target) || @copy($tmp, $target);
    }

    private function writeMetadata(MediaItem $item): void
    {
        $this->ensureDir();

        file_put_contents(
            $this->mediaDir . '/' . $item->id . '.json',
            json_encode($item->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function readMetadata(string $path): ?MediaItem
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $row = json_decode($raw, true);
        if (!is_array($row)) {
            return null;
        }

        try {
            return MediaItem::fromArray($row);
        } catch (\InvalidArgumentException) {
            // A corrupt record must not break the whole library listing.
            return null;
        }
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the file.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by the server.',
            default => 'The upload failed.',
        };
    }
}
