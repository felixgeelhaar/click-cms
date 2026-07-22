<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Media;

use InvalidArgumentException;

/**
 * The point in an image that must stay visible when it is cropped.
 *
 * The variant ladder preserves the source aspect ratio, but a responsive layout
 * often shows an image in a fixed box and crops the overflow — and the browser
 * crops around the centre by default, so a subject in the top-left is the first
 * thing lost. Marking a focal point lets the editor say which part matters; a
 * front end honours it with CSS `object-position` and never re-crops the stored
 * files, so this is metadata, not a second copy of the picture.
 *
 * Stored as fractions of the width and height (0..1) rather than pixels, so the
 * mark holds for the original and every variant at once — a point three-quarters
 * across is three-quarters across whatever the rendered width.
 */
final class FocalPoint
{
    private function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {}

    /**
     * The middle — the honest default when nothing is marked, and what a browser
     * does with object-position anyway, so an unmarked image renders unchanged.
     */
    public static function center(): self
    {
        return new self(0.5, 0.5);
    }

    /**
     * A focal point at the given fractions of the width and height.
     *
     * A value outside 0..1 is not a point on the image, so it is refused rather
     * than clamped: a caller that computed one has a bug, and silently moving
     * the mark somewhere it did not ask for would hide it.
     */
    public static function at(float $x, float $y): self
    {
        self::guard('x', $x);
        self::guard('y', $y);

        return new self($x, $y);
    }

    private static function guard(string $axis, float $value): void
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException(
                "Focal point {$axis} must be between 0 and 1, got {$value}."
            );
        }
    }

    /**
     * Read a focal point from stored metadata.
     *
     * Lenient on purpose: a record written before focal points existed, or one
     * missing a coordinate, reads back as centred rather than failing the whole
     * media item. An out-of-range stored value is still refused, because it can
     * only be corruption and centring silently would hide it.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        if (!isset($row['x']) && !isset($row['y'])) {
            return self::center();
        }

        return self::at(
            (float) ($row['x'] ?? 0.5),
            (float) ($row['y'] ?? 0.5),
        );
    }

    public function isCenter(): bool
    {
        return $this->x === 0.5 && $this->y === 0.5;
    }

    /**
     * The CSS `object-position` value that keeps this point visible, e.g.
     * "50% 50%". A front end sets it on an <img> that uses object-fit and the
     * crop closes in on the subject instead of the middle.
     */
    public function toCss(): string
    {
        return $this->percent($this->x) . ' ' . $this->percent($this->y);
    }

    /**
     * A fraction as a percentage with no trailing-zero noise: 0.5 -> "50%",
     * 0.333 -> "33.3%".
     */
    private function percent(float $fraction): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $fraction * 100), '0'), '.');

        return ($formatted === '' ? '0' : $formatted) . '%';
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
