<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Media;

use Click\Cms\Domain\Media\ImageSize;
use GdImage;
use RuntimeException;

/**
 * Generates responsive variants with GD.
 *
 * GD rather than Imagick because it is compiled into almost every PHP build,
 * and this has to work on shared hosting where installing an extension is not
 * an option.
 *
 * Variants are only ever scaled down. Enlarging a small upload wastes bytes and
 * produces a blurry image that looks like a bug, so a rung wider than the
 * source is skipped and the caller learns which sizes actually exist.
 */
final class GdImageProcessor
{
    /** Formats that can be both read and written. */
    private const SUPPORTED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly int $jpegQuality = 82) {}

    public static function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    public static function supports(string $mimeType): bool
    {
        return isset(self::SUPPORTED[$mimeType]);
    }

    public static function extensionFor(string $mimeType): ?string
    {
        return self::SUPPORTED[$mimeType] ?? null;
    }

    /**
     * Read an image's true dimensions and MIME type.
     *
     * Uses the file's actual content, never its name, because an extension is
     * attacker-controlled and says nothing about what a file really is.
     *
     * @return array{width: int, height: int, mimeType: string}|null
     */
    public function inspect(string $path): ?array
    {
        $info = @getimagesize($path);
        if ($info === false || !isset($info[0], $info[1], $info['mime'])) {
            return null;
        }

        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'mimeType' => (string) $info['mime'],
        ];
    }

    /**
     * Write every applicable variant of $sourcePath into $targetDir.
     *
     * @return list<ImageSize> The sizes actually written, smallest first.
     */
    public function generateVariants(
        string $sourcePath,
        string $targetDir,
        string $basename,
        string $extension
    ): array {
        if (!self::isAvailable()) {
            // Not fatal: the original is already stored and usable. The caller
            // records that no variants exist rather than failing the upload.
            return [];
        }

        $info = $this->inspect($sourcePath);
        if ($info === null || !self::supports($info['mimeType'])) {
            return [];
        }

        $source = $this->load($sourcePath, $info['mimeType']);
        if ($source === null) {
            return [];
        }

        // GdImage instances are released by the garbage collector; imagedestroy()
        // has had no effect since PHP 8.0 and is deprecated in 8.5.
        $written = [];

        foreach (ImageSize::ladder() as $size) {
            // Never upscale.
            if ($size->width() >= $info['width']) {
                continue;
            }

            $height = (int) round($info['height'] * ($size->width() / $info['width']));
            $resized = $this->resize($source, $size->width(), max(1, $height), $info['mimeType']);

            if ($resized === null) {
                continue;
            }

            $target = $targetDir . '/' . $size->filenameFor($basename, $extension);

            if ($this->write($resized, $target, $info['mimeType'])) {
                $written[] = $size;
            }
        }

        return $written;
    }

    /**
     * Remove every variant of a base name.
     *
     * @return int How many files were removed.
     */
    public function deleteVariants(string $dir, string $basename, string $extension): int
    {
        $removed = 0;

        foreach (ImageSize::ladder() as $size) {
            $path = $dir . '/' . $size->filenameFor($basename, $extension);
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function load(string $path, string $mimeType): ?GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        return $image instanceof GdImage ? $image : null;
    }

    private function resize(GdImage $source, int $width, int $height, string $mimeType): ?GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        if (!$target instanceof GdImage) {
            return null;
        }

        // PNG, GIF and WebP can carry transparency, which imagecreatetruecolor
        // fills with opaque black unless told otherwise.
        if ($mimeType !== 'image/jpeg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        }

        $ok = imagecopyresampled(
            $target, $source,
            0, 0, 0, 0,
            $width, $height,
            imagesx($source), imagesy($source)
        );

        if (!$ok) {
            return null;
        }

        return $target;
    }

    private function write(GdImage $image, string $path, string $mimeType): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create image directory: {$dir}");
        }

        return match ($mimeType) {
            'image/jpeg' => @imagejpeg($image, $path, $this->jpegQuality),
            'image/png' => @imagepng($image, $path, 6),
            'image/gif' => @imagegif($image, $path),
            'image/webp' => @imagewebp($image, $path, $this->jpegQuality),
            default => false,
        };
    }
}
