<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\ValueObjects\ContentKey;
use InvalidArgumentException;

/**
 * What a content key is allowed to contain, for every storage backend.
 *
 * The rule originates in the flat-file backend, where type and slug become path
 * segments and so must not be able to escape the content directory. SQLite has
 * no such danger — it would happily store `page:../../etc/passwd`.
 *
 * It applies the rule anyway, on purpose. If each backend enforced only what its
 * own medium required, the set of legal keys would change when the backend
 * changed: content authored on SQLite could not be exported to files, and a slug
 * rejected before a migration would be accepted after it. A key space that
 * depends on where the bytes happen to live is not a key space. The strictest
 * backend therefore defines it for all of them.
 *
 * Reads and writes are deliberately asymmetric, and that asymmetry is the reason
 * this is two methods rather than one:
 *
 * - Reading an impossible key is an ordinary miss. Reads are reached straight
 *   from URLs, so throwing would turn every stray request into a 500.
 * - Writing one is a bug or an attack, and must be loud.
 */
final class ContentKeyRules
{
    private const SAFE_SEGMENT = '/^[A-Za-z0-9._-]+$/';

    public static function isSafe(ContentKey $key): bool
    {
        return self::isSafeSegment($key->type) && self::isSafeSegment($key->slug);
    }

    public static function isSafeSegment(string $segment): bool
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }

        return preg_match(self::SAFE_SEGMENT, $segment) === 1;
    }

    /** @throws InvalidArgumentException on the write path only. */
    public static function assertSafe(ContentKey $key): void
    {
        self::assertSafeSegment($key->type, 'type');
        self::assertSafeSegment($key->slug, 'slug');
    }

    private static function assertSafeSegment(string $segment, string $label): void
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new InvalidArgumentException("Content {$label} is not a valid path segment.");
        }

        if (preg_match(self::SAFE_SEGMENT, $segment) !== 1) {
            throw new InvalidArgumentException(
                "Content {$label} may only contain letters, digits, dot, dash and underscore."
            );
        }
    }
}
