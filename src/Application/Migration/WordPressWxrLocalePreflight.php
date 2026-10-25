<?php

declare(strict_types=1);

namespace Click\Cms\Application\Migration;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Domain\ValueObjects\Locale;
use InvalidArgumentException;

/** Validates one import locale against the selected site's effective config. */
final class WordPressWxrLocalePreflight
{
    public static function validate(CoreConfig $config, string $requested): string
    {
        $locale = Locale::tryFromString($requested);
        if ($locale === null || !$config->supportsLocale($locale)) {
            throw new InvalidArgumentException(sprintf(
                'Locale "%s" is not configured for the selected site.',
                $requested,
            ));
        }

        return $locale->code;
    }
}
