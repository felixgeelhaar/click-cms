<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

/** One installation-wide barrier, acquired before the front controller autoloads. */
final class RequestGate
{
    /** @var resource|null */
    private static $request = null;
    private static ?string $root = null;
    private static bool $attempted = false;

    /** @param resource $handle */
    public static function adopt(string $root, $handle): void
    {
        self::$root = realpath($root) ?: $root;
        self::$request = $handle;
        self::$attempted = false;
    }

    public static function attempted(): bool
    {
        return self::$attempted;
    }

    /**
     * Never wait holding a shared lock: concurrent requests simply defer this
     * install. All callers, including CLI and manual updates, use the barrier.
     *
     * @template T
     * @param callable(): T $work
     * @return T|null Null means another request is still using this code.
     */
    public static function write(string $root, callable $work): mixed
    {
        $root = realpath($root) ?: $root;
        $owned = self::$root === $root && is_resource(self::$request);
        $handle = $owned ? self::$request : @fopen($root . '/data/updates/requests.lock', 'c');
        if (!is_resource($handle)) {
            return null;
        }
        if ($owned) {
            self::$attempted = true;
            flock($handle, LOCK_UN);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            if ($owned) {
                // Wait for any writer that won the race before allowing this
                // request to continue; no classes may load during its swap.
                flock($handle, LOCK_SH);
            } else {
                fclose($handle);
            }
            return null;
        }
        try {
            self::$attempted = true;
            return $work();
        } finally {
            if ($owned) {
                flock($handle, LOCK_SH);
            } else {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
