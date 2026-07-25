<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Backup;

/**
 * The name a media file has once it is in the shared pool: `pool/<sha256>.<ext>`.
 *
 * Content-addressed, so the name *is* the assertion about the bytes. Two
 * consequences follow, and both are the point of the pool:
 *
 *  - Seven nightly backups of a site whose pictures did not change store those
 *    pictures once, because seven runs compute the same digest and write the
 *    same name.
 *  - "Is the pool entry this manifest asks for the file it was promised?" is
 *    answerable by hashing it, with nothing else to consult.
 *
 * The extension is carried along purely so a human poking at `data/backups/pool`
 * can tell a PNG from a video. Nothing reads it to decide anything — the digest
 * is the identity — so an entry with no extension is legal too.
 *
 * A reference arrives from a manifest, which is a file on disk that may have
 * been tampered with, so it is validated as a string before it is ever turned
 * into a path.
 */
final class PoolReference
{
    public const DIRECTORY = 'pool';

    private function __construct() {}

    /** Build the reference for these bytes. */
    public static function for(string $sha256, string $extension): string
    {
        $extension = self::normaliseExtension($extension);

        return self::DIRECTORY . '/' . $sha256 . ($extension === '' ? '' : '.' . $extension);
    }

    public static function isValid(string $reference): bool
    {
        return preg_match(
            '#^' . self::DIRECTORY . '/[a-f0-9]{64}(?:\.[a-z0-9]{1,8})?$#',
            $reference
        ) === 1;
    }

    /** The digest a valid reference claims, or null when it is not one. */
    public static function digestOf(string $reference): ?string
    {
        if (!self::isValid($reference)) {
            return null;
        }

        $name = substr($reference, strlen(self::DIRECTORY) + 1);
        $dot = strpos($name, '.');

        return $dot === false ? $name : substr($name, 0, $dot);
    }

    /**
     * Lower-cased, letters and digits only, and bounded.
     *
     * The extension comes from an uploaded file's name, so it is not trusted to
     * be a word: anything else is dropped rather than sanitised into something
     * that still resembles it.
     */
    private static function normaliseExtension(string $extension): string
    {
        $extension = strtolower(ltrim($extension, '.'));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : '';
    }
}
