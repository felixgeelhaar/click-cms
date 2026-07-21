<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Media;

/**
 * How well an uploaded image will hold up where it is shown.
 *
 * Three levels rather than a boolean, because "big enough" is not one question.
 * An image can be too small for a full-bleed header and perfectly sharp in a
 * card in the same grid, and an editor told only "too small" learns nothing
 * they can act on.
 *
 * Nothing here refuses an upload. A logo that must ship today beats a warning
 * that blocks it, so every level is a report and none is a veto.
 */
enum ImageQualityLevel: string
{
    /** Enough pixels for every rung of the ladder, at any size on any screen. */
    case Full = 'full';

    /** Sharp in most places; soft only when shown across the whole page. */
    case Adequate = 'adequate';

    /** Will look soft on the phones and laptops most visitors actually use. */
    case Low = 'low';

    /** Whether there is anything worth telling the editor. */
    public function isWarning(): bool
    {
        return $this !== self::Full;
    }
}
