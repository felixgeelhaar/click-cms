<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * A local image, resolved: where the page should point at it, and how big it is.
 *
 * The width and height are the file's own, read out of its header, and they are
 * nullable on purpose. A format this build cannot measure gets no `width` and no
 * `height` rather than a guess — a wrong box reflows the page exactly as badly
 * as no box at all, and it lies about the picture as well.
 */
final class ImageAsset
{
    /**
     * @param string $src Plain (unescaped) URL for the `src` attribute,
     *        relative to the document that referenced the image.
     */
    public function __construct(
        public readonly string $src,
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
    }
}
