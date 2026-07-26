<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * Reads a value the web server was told to set, under every name it may arrive
 * as.
 *
 * `SetEnv CLICK_CMS_ROOT /home/example/click-cms` in an .htaccess is how an
 * installation on shared hosting says where its own files are. Whether PHP sees
 * it under that name depends on the server: Apache prefixes variables with
 * `REDIRECT_` once a request has been through an internal redirect, and on a
 * `cgi-fcgi` SAPI — which is what a good deal of shared hosting runs — every
 * PHP request has been. There it arrives as `REDIRECT_CLICK_CMS_ROOT` and under
 * no other name at all.
 *
 * This was found on a live installation. `getenv()` alone returned nothing, the
 * site fell back to the directory above `public/`, could not find its own
 * `vendor/`, and answered 500 — a configured installation behaving exactly as
 * though it had never been configured. The setting was correct the whole time.
 *
 * Three sources, most direct first: the process environment, the request, then
 * the request's redirected forms.
 */
final class ServerEnvironment
{
    /** How many `REDIRECT_` prefixes to look through. Two is already unusual. */
    private const MAX_REDIRECTS = 3;

    /**
     * @param array<string, mixed>      $server The request's `$_SERVER`.
     * @param array<string, mixed>|null $env    The process environment; null reads the real one.
     */
    public static function lookup(string $name, array $server, ?array $env = null): ?string
    {
        $fromEnv = $env === null ? getenv($name) : ($env[$name] ?? false);
        $value = self::clean($fromEnv);
        if ($value !== null) {
            return $value;
        }

        // The unprefixed name first: if the server set it directly, that is the
        // operator's own value rather than one carried through a redirect.
        $value = self::clean($server[$name] ?? null);
        if ($value !== null) {
            return $value;
        }

        $prefix = '';
        for ($i = 0; $i < self::MAX_REDIRECTS; $i++) {
            $prefix .= 'REDIRECT_';
            $value = self::clean($server[$prefix . $name] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** An empty or non-string value is an absent one — nothing can use it. */
    private static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
