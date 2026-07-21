<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Media;

/**
 * The responsive variants generated for every uploaded image.
 *
 * A fixed ladder rather than arbitrary sizes: a site's templates have to name
 * these in their srcset, so they are part of the contract between the CMS and
 * whatever renders the page. Adding a rung is a deliberate change, not a
 * per-upload decision.
 *
 * Widths only — height follows from the source aspect ratio, because cropping
 * to a fixed box is an editorial decision the CMS should not make silently.
 */
enum ImageSize: string
{
    case Small = 'sm';
    case Medium = 'md';
    case Large = 'lg';
    case ExtraLarge = 'xl';

    /** Target width in pixels. */
    public function width(): int
    {
        return match ($this) {
            self::Small => 640,
            self::Medium => 1024,
            self::Large => 1536,
            self::ExtraLarge => 2048,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Small',
            self::Medium => 'Medium',
            self::Large => 'Large',
            self::ExtraLarge => 'Extra large',
        };
    }

    /**
     * Variants ordered smallest first, which is the order a srcset wants.
     *
     * @return list<self>
     */
    public static function ladder(): array
    {
        return [self::Small, self::Medium, self::Large, self::ExtraLarge];
    }

    /**
     * The filename for this variant of a given base name.
     *
     * `photo` + Small + jpg => `photo-sm.jpg`
     */
    public function filenameFor(string $basename, string $extension): string
    {
        return $basename . '-' . $this->value . '.' . $extension;
    }
}
