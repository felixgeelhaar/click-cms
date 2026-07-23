<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Media;

/**
 * An art-directed crop a site declares: a name, and the aspect ratio the crop is
 * cut to. It carries no pixel size — the processor derives that from each source,
 * never upscaling — so a `wide` crop is "16:9, focal-point-centred", whatever the
 * upload's dimensions.
 *
 * Focal points cover the common case (a front end that crops with `object-fit`
 * keeps the subject visible); a declared box is for the layout that needs the
 * bytes already cut, so the front end receives an image of exactly the right
 * shape rather than one it must crop itself.
 */
final class CropBox
{
    private function __construct(
        public readonly string $name,
        public readonly int $aspectWidth,
        public readonly int $aspectHeight,
    ) {
    }

    /**
     * Parse a declared crop, or null when it is malformed — a nameless or
     * non-slug crop, or one whose aspect has a non-positive side, is dropped
     * rather than allowed to name a file or divide by zero.
     *
     * @param array<string, mixed> $spec
     */
    public static function fromArray(array $spec): ?self
    {
        $name = strtolower(trim((string) ($spec['name'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
            return null;
        }

        $aspectWidth = (int) ($spec['aspectWidth'] ?? 0);
        $aspectHeight = (int) ($spec['aspectHeight'] ?? 0);
        if ($aspectWidth < 1 || $aspectHeight < 1) {
            return null;
        }

        return new self($name, $aspectWidth, $aspectHeight);
    }

    /** The crop's aspect ratio as width ÷ height. */
    public function ratio(): float
    {
        return $this->aspectWidth / $this->aspectHeight;
    }
}
