<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Backup;

/**
 * What an entry inside a backup archive is allowed to be called.
 *
 * A ZIP entry name is attacker-controlled the moment an archive can arrive from
 * anywhere — and a restore is exactly that: a file an administrator points at.
 * Extracting `../../public/index.php`, or `/etc/cron.d/anything`, is the Zip
 * Slip class of bug, and this repository has shipped one before in the
 * marketplace installer. So entry names are validated as *names*, before any
 * path is built from them, which is the only check that cannot be defeated by a
 * clever join.
 *
 * The rule is deliberately narrower than "does not escape": a name that would
 * stay inside the destination but relies on `.` or an empty segment to do so is
 * still refused, because nothing this codebase writes produces one and the
 * cheapest way to be sure a normalisation bug cannot exist is to have nothing to
 * normalise.
 *
 * Stated here in the domain and not in the extractor because two callers need
 * the same answer — verification refuses the archive, restore builds paths — and
 * a second copy of a rule like this is how the two drift apart.
 */
final class ArchivePath
{
    private function __construct() {}

    public static function isSafe(string $name): bool
    {
        // A NUL truncates the name in every C-level filesystem call, so
        // "safe.txt\0../../evil" would validate as one thing and open another.
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }

        // Absolute in the POSIX sense, and in the Windows senses: a drive
        // letter, and a backslash that is a separator there but an ordinary
        // character in the check below.
        if (str_starts_with($name, '/') || str_contains($name, '\\')) {
            return false;
        }
        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            return false;
        }

        foreach (explode('/', $name) as $segment) {
            // Empty catches a leading, trailing or doubled separator — including
            // the trailing slash a directory entry carries, which is why a
            // directory entry is never extracted: the restorer creates the
            // directories it needs itself.
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Join a validated entry name onto a destination directory.
     *
     * Returns null rather than a path when the name does not pass, so a caller
     * that forgets to check cannot accidentally get something extractable. The
     * containment assertion afterwards is belt and braces: `isSafe()` already
     * makes escape impossible, and this catches the case where it is one day
     * loosened.
     */
    public static function resolve(string $destination, string $name): ?string
    {
        if (!self::isSafe($name)) {
            return null;
        }

        $root = rtrim($destination, '/') . '/';
        $target = $root . $name;

        return str_starts_with($target, $root) ? $target : null;
    }
}
