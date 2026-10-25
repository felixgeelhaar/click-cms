<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Content;

/** Opaque fingerprint of the complete persisted content state. */
final class ContentRevision
{
    public static function of(Content $content): string
    {
        $value = self::canonicalise($content->toArray());
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode(hash('sha256', $json, true)), '+/', '-_'), '=');
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalise($item);
        }

        return $value;
    }
}
