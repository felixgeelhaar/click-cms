<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Media;

/**
 * A request to serve an image at a width it was not pre-generated at.
 *
 * The pre-built ladder (`-sm`/`-md`/`-lg`/`-xl`) covers the sizes a site knows
 * about at upload time. A front end that later needs 720px has three bad
 * options: download 1024 and let the browser shrink it, re-upload everything, or
 * ask the CMS. This is the third.
 *
 * ## Why widths snap instead of being honoured exactly
 *
 * Resizing on demand is a denial-of-service vector wearing a feature's clothes:
 * `?w=1`, `?w=2`, `?w=3` … is a few thousand cheap requests that each cost a
 * decode, a resample and a disk write, and the cache they fill is unbounded. So
 * a requested width is snapped up to the nearest of a fixed ladder. An attacker
 * asking for ten thousand widths gets the same handful of files a legitimate
 * caller would, and the derived cache can never hold more than
 * (images × ladder entries).
 *
 * Snapping *up* rather than to the nearest keeps the image at least as large as
 * asked for, so it is never upscaled by the browser afterwards.
 */
final class TransformRequest
{
    /**
     * The widths that may be produced. Chosen to cover common layout columns and
     * 2× versions of them without being so dense that the ladder stops bounding
     * anything.
     */
    public const WIDTHS = [160, 320, 480, 640, 768, 1024, 1280, 1536, 2048];

    private function __construct(public readonly int $width)
    {
    }

    /**
     * Parse a requested width, or null when none was asked for or it makes no
     * sense — in which case the original is served, exactly as before.
     */
    public static function fromQuery(mixed $requestedWidth): ?self
    {
        if ($requestedWidth === null || $requestedWidth === '' || !is_numeric($requestedWidth)) {
            return null;
        }

        $width = (int) $requestedWidth;
        if ($width < 1) {
            return null;
        }

        return new self(self::snap($width));
    }

    /**
     * The smallest allowed width that is at least as large as the one asked for.
     * Anything above the ladder's top is the top: a request for 8000px is a
     * request for the largest we make, not an instruction to invent pixels.
     */
    public static function snap(int $width): int
    {
        foreach (self::WIDTHS as $candidate) {
            if ($candidate >= $width) {
                return $candidate;
            }
        }

        return self::WIDTHS[count(self::WIDTHS) - 1];
    }

    /**
     * The stored name for this rendition of a file.
     *
     * Derived names live in their own directory rather than beside the original,
     * so the media library's listing — which reads the upload directory — is not
     * polluted by cache entries an editor never created and cannot manage.
     */
    public function cacheName(string $id, string $extension): string
    {
        return $id . '-w' . $this->width . '.' . $extension;
    }
}
