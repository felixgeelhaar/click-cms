<?php

declare(strict_types=1);

namespace Click\Cms\Application\Media;

use Click\Cms\Domain\Media\FocalPoint;
use Click\Cms\Domain\Media\MediaItem;
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
    public function __construct(
        private readonly string $mediaDir,
        private readonly GdImageProcessor $processor = new GdImageProcessor(),
    ) {}

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
        if ($bytes > UploadPolicy::MAX_BYTES) {
            $limit = (int) (UploadPolicy::MAX_BYTES / 1024 / 1024);
            return ['item' => null, 'error' => "Files must be smaller than {$limit} MB."];
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

        $item = MediaItem::create(
            id: $id,
            extension: $extension,
            mimeType: $mimeType,
            originalName: (string) ($file['name'] ?? ''),
            bytes: filesize($target) ?: $bytes,
            width: $info['width'],
            height: $info['height'],
            variants: $variants,
        );

        $this->writeMetadata($item);

        return ['item' => $item, 'error' => null];
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
     * Metadata only, exactly like alt text: the stored files are never re-cropped
     * — a front end honours the point with CSS. Coordinates are fractions 0..1;
     * validation lives in FocalPoint, so an out-of-range value throws rather than
     * being quietly moved.
     */
    public function updateFocalPoint(string $id, float $x, float $y): ?MediaItem
    {
        $item = $this->find($id);
        if ($item === null) {
            return null;
        }

        $updated = $item->withFocalPoint(FocalPoint::at($x, $y));
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
        $pattern = '/^(?<id>[a-z0-9][a-z0-9-]*?)(?:-(?:sm|md|lg|xl))?\.(?<ext>[a-z0-9]{1,5})$/';

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
