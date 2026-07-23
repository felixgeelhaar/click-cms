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

    /**
     * The largest side a square crop is written at. Never upscales past the
     * source's shorter edge — this is only a ceiling, so a crop is never bigger
     * than it usefully needs to be. A fixed box in a card or an avatar is well
     * served at 1024, which is 512 CSS pixels at the 2x density most screens draw.
     */
    private const SQUARE_MAX = 1024;

    // The longest edge an art-directed crop is scaled to. Larger than the square
    // because a wide crop's long edge carries the whole width of the subject,
    // where the square only ever holds the shorter dimension.
    private const CROP_MAX = 1600;

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
     * Write a square crop centred on a focal point.
     *
     * The variant ladder deliberately preserves the source aspect ratio, which
     * leaves a layout that needs a fixed box to crop with CSS and lose whatever
     * the browser's centre-crop cuts off. This produces an actual cropped file
     * instead, centred on the point the editor marked, so the subject survives.
     * It is additional to the ladder, never a replacement for it.
     *
     * The crop takes the largest square the source allows — its shorter edge —
     * and slides that square along the longer axis so the focal point sits at its
     * centre, clamped to stay within the image. The result is written at that
     * square's size or SQUARE_MAX, whichever is smaller, so it is only ever
     * scaled down: a crop of a small source is smaller, never invented.
     *
     * @param float $focalX Focal point across, 0..1.
     * @param float $focalY Focal point down, 0..1.
     * @return int|null The side length written, or null when nothing could be.
     */
    public function generateSquareCrop(
        string $sourcePath,
        string $targetDir,
        string $basename,
        string $extension,
        float $focalX,
        float $focalY
    ): ?int {
        if (!self::isAvailable()) {
            return null;
        }

        $info = $this->inspect($sourcePath);
        if ($info === null || !self::supports($info['mimeType'])) {
            return null;
        }

        $source = $this->load($sourcePath, $info['mimeType']);
        if ($source === null) {
            return null;
        }

        // The largest square the source can yield is its shorter edge.
        $region = min($info['width'], $info['height']);

        // Centre the square on the focal point, then clamp so it never runs off
        // the image — a focal point at the very edge pins the square to that edge
        // rather than sampling pixels that do not exist.
        $srcX = $this->clampOffset((int) round($focalX * $info['width'] - $region / 2), $info['width'], $region);
        $srcY = $this->clampOffset((int) round($focalY * $info['height'] - $region / 2), $info['height'], $region);

        // Never upscale: the output is the region's size, capped at SQUARE_MAX.
        $side = min($region, self::SQUARE_MAX);

        $target = imagecreatetruecolor($side, $side);
        if (!$target instanceof GdImage) {
            return null;
        }

        if ($info['mimeType'] !== 'image/jpeg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $side, $side, $transparent);
        }

        $ok = imagecopyresampled(
            $target, $source,
            0, 0, $srcX, $srcY,
            $side, $side,
            $region, $region
        );

        if (!$ok) {
            return null;
        }

        $path = $targetDir . '/' . $basename . '-square.' . $extension;

        return $this->write($target, $path, $info['mimeType']) ? $side : null;
    }

    /**
     * Cut a named crop of a fixed aspect ratio, focal-point-centred, from the
     * source. The largest rectangle of that ratio the source can yield is taken —
     * width-bound when the source is taller than the ratio, height-bound when it
     * is wider — then centred on the focal point and clamped so it never samples
     * off the edge, exactly as the square does. The output is never upscaled and
     * its longest edge is capped at CROP_MAX.
     *
     * @return array{width: int, height: int}|null Null for an unsupported or
     *         unreadable source, or when GD is absent — the caller then records
     *         no crop rather than a file that is not there.
     */
    public function generateCrop(
        string $sourcePath,
        string $targetDir,
        string $basename,
        string $extension,
        string $name,
        int $aspectWidth,
        int $aspectHeight,
        float $focalX,
        float $focalY
    ): ?array {
        if (!self::isAvailable() || $aspectWidth < 1 || $aspectHeight < 1) {
            return null;
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
            return null;
        }

        $info = $this->inspect($sourcePath);
        if ($info === null || !self::supports($info['mimeType'])) {
            return null;
        }

        $source = $this->load($sourcePath, $info['mimeType']);
        if ($source === null) {
            return null;
        }

        // The largest rectangle of the wanted ratio that fits inside the source.
        // Compare the source's ratio to the target's: a source wider than the
        // target is limited by its height, a taller one by its width.
        $targetRatio = $aspectWidth / $aspectHeight;
        $sourceRatio = $info['width'] / $info['height'];

        if ($sourceRatio > $targetRatio) {
            $regionHeight = $info['height'];
            $regionWidth = (int) round($regionHeight * $targetRatio);
        } else {
            $regionWidth = $info['width'];
            $regionHeight = (int) round($regionWidth / $targetRatio);
        }

        // Rounding can push the region a pixel past the source; keep it inside.
        $regionWidth = max(1, min($regionWidth, $info['width']));
        $regionHeight = max(1, min($regionHeight, $info['height']));

        $srcX = $this->clampOffset((int) round($focalX * $info['width'] - $regionWidth / 2), $info['width'], $regionWidth);
        $srcY = $this->clampOffset((int) round($focalY * $info['height'] - $regionHeight / 2), $info['height'], $regionHeight);

        // Never upscale: scale down only when the long edge exceeds CROP_MAX.
        $longEdge = max($regionWidth, $regionHeight);
        $scale = $longEdge > self::CROP_MAX ? self::CROP_MAX / $longEdge : 1.0;
        $outWidth = max(1, (int) round($regionWidth * $scale));
        $outHeight = max(1, (int) round($regionHeight * $scale));

        $target = imagecreatetruecolor($outWidth, $outHeight);
        if (!$target instanceof GdImage) {
            return null;
        }

        if ($info['mimeType'] !== 'image/jpeg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $outWidth, $outHeight, $transparent);
        }

        $ok = imagecopyresampled(
            $target, $source,
            0, 0, $srcX, $srcY,
            $outWidth, $outHeight,
            $regionWidth, $regionHeight
        );

        if (!$ok) {
            return null;
        }

        $path = $targetDir . '/' . $basename . '-crop-' . $name . '.' . $extension;

        return $this->write($target, $path, $info['mimeType'])
            ? ['width' => $outWidth, 'height' => $outHeight]
            : null;
    }

    /**
     * Keep a crop window of $window pixels inside an axis of $length pixels: the
     * offset is pinned into [0, length - window] so the square never samples off
     * the edge.
     */
    private function clampOffset(int $offset, int $length, int $window): int
    {
        return max(0, min($offset, $length - $window));
    }

    /**
     * Remove every variant of a base name, the square crop included.
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

        $square = $dir . '/' . $basename . '-square.' . $extension;
        if (is_file($square) && @unlink($square)) {
            $removed++;
        }

        // Every named crop, whatever the site declared, matched by the id-scoped
        // pattern so a removed item leaves nothing behind. glob escaping is not
        // needed: the basename is a validated slug.
        foreach (glob($dir . '/' . $basename . '-crop-*.' . $extension) ?: [] as $crop) {
            if (@unlink($crop)) {
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
