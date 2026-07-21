<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Media;

/**
 * Whether an image has the pixels to look sharp where it is used.
 *
 * This exists because the variant ladder fails quietly. Core never upscales, so
 * an upload narrower than a rung simply produces fewer variants and says
 * nothing. A real 1022-pixel upload during testing produced only `sm`; the
 * library displayed "sm" and left the person who uploaded it no way to learn
 * that the picture would be stretched and soft on any modern screen. The only
 * one who would ever notice was a visitor.
 *
 * So the judgement lives here, in the domain, next to the ladder that causes
 * it — not in a controller and not in a Vue component, both of which would have
 * to reimplement it and would drift.
 *
 * Deliberately not judged: compression artefacts, sharpness, or whether the
 * subject is any good. Those need heuristics that are wrong often enough to
 * erode trust in the warnings that are right. Pixel count is arithmetic, and
 * arithmetic does not guess.
 */
final class ImageQuality
{
    /**
     * Phones and laptops have drawn two device pixels per CSS pixel for over a
     * decade. An image shown in a 1200-pixel slot therefore needs about 2400
     * real pixels before it stops looking soft, and every recommendation below
     * is that doubling. Two rather than three: 3x screens exist, but demanding
     * 3x of every upload would warn about images that look fine to almost
     * everyone, and a warning that cries wolf gets ignored along with the ones
     * that matter.
     */
    public const DENSITY = 2;

    /**
     * @param list<ImageSize> $missingVariants Rungs the ladder could not produce.
     */
    private function __construct(
        public readonly ImageQualityLevel $level,
        public readonly int $sourceWidth,
        public readonly int $recommendedWidth,
        public readonly ?int $displayWidth,
        public readonly array $missingVariants,
        public readonly string $message,
    ) {}

    /**
     * The verdict at upload time, when nothing is known about where the image
     * will be used.
     *
     * With no slot declared the only honest reference is the ladder itself, so
     * this is deliberately conservative about warning: it says an image is too
     * small only when it is too small almost everywhere. A field that declares
     * its display width gets the specific answer from forSlot() instead.
     */
    public static function forUpload(int $sourceWidth): self
    {
        $recommended = ImageSize::ExtraLarge->width();

        // Below the `md` rung the picture cannot fill even a phone at 2x, which
        // is the case that produced this feature.
        $level = match (true) {
            $sourceWidth >= $recommended => ImageQualityLevel::Full,
            $sourceWidth >= ImageSize::Medium->width() => ImageQualityLevel::Adequate,
            default => ImageQualityLevel::Low,
        };

        return new self(
            level: $level,
            sourceWidth: $sourceWidth,
            recommendedWidth: $recommended,
            displayWidth: null,
            missingVariants: self::missingRungs($sourceWidth),
            message: match ($level) {
                ImageQualityLevel::Full => '',
                ImageQualityLevel::Adequate => sprintf(
                    'This picture is %d pixels wide. That is sharp in most places, but a '
                    . 'section that shows it across the full width of the page will look '
                    . 'soft. Supply about %d pixels to cover every use.',
                    $sourceWidth,
                    $recommended
                ),
                ImageQualityLevel::Low => sprintf(
                    'This picture is only %d pixels wide. Phones and laptops draw two screen '
                    . 'pixels for every one it has, so it will look soft wherever it is shown '
                    . 'large. Supply a version about %d pixels wide.',
                    $sourceWidth,
                    $recommended
                ),
            },
        );
    }

    /**
     * The verdict for a field that declares the width it displays images at.
     *
     * Only the section type knows whether an image lands in a four-column card
     * or a full-bleed header, and the same 1022-pixel file is fine in the first
     * and wrong in the second. Once that width is declared the vague warning
     * becomes an arithmetic one.
     */
    public static function forSlot(int $sourceWidth, int $displayWidth): self
    {
        $recommended = $displayWidth * self::DENSITY;
        $fits = $sourceWidth >= $recommended;

        return new self(
            level: $fits ? ImageQualityLevel::Full : ImageQualityLevel::Low,
            sourceWidth: $sourceWidth,
            recommendedWidth: $recommended,
            displayWidth: $displayWidth,
            missingVariants: self::missingRungs($sourceWidth),
            message: $fits ? '' : sprintf(
                'This image is %d pixels wide but is shown here at %d pixels. Phones and '
                . 'laptops draw two screen pixels for every one it has, so it will look '
                . 'soft. Supply a version about %d pixels wide.',
                $sourceWidth,
                $displayWidth,
                $recommended
            ),
        );
    }

    /** Whether there is anything worth showing the editor. */
    public function isWarning(): bool
    {
        return $this->level->isWarning();
    }

    /**
     * The largest slot this image stays sharp in.
     *
     * Exposed so a client can compare widths without reimplementing the density
     * rule above.
     */
    public function sharpUpToWidth(): int
    {
        return intdiv($this->sourceWidth, self::DENSITY);
    }

    /**
     * Rungs the ladder cannot produce for a source of this width.
     *
     * Mirrors GdImageProcessor, which skips any rung at or above the source
     * width because upscaling makes a bigger file that looks no better.
     *
     * @return list<ImageSize>
     */
    private static function missingRungs(int $sourceWidth): array
    {
        $missing = [];
        foreach (ImageSize::ladder() as $size) {
            if ($size->width() >= $sourceWidth) {
                $missing[] = $size;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'warning' => $this->isWarning(),
            'sourceWidth' => $this->sourceWidth,
            'recommendedWidth' => $this->recommendedWidth,
            'displayWidth' => $this->displayWidth,
            'sharpUpToWidth' => $this->sharpUpToWidth(),
            'missingVariants' => array_map(
                static fn (ImageSize $s): string => $s->value,
                $this->missingVariants
            ),
            'message' => $this->message,
        ];
    }
}
