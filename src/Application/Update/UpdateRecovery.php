<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

/**
 * Dependency-free recovery for a process killed during UpdateInstaller's
 * directory swap. This file is copied to data/updates before a swap so the
 * front controller can require it before vendor/autoload.php.
 */
final class UpdateRecovery
{
    /** Persist the tiny bootstrap helper outside the replaceable source tree. */
    public static function installBootstrapCopy(string $basePath): bool
    {
        $directory = rtrim($basePath, '/') . '/data/updates';
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            return false;
        }
        if (is_link($directory)) {
            return false;
        }
        $target = $directory . '/bootstrap-recovery.php';
        $temp = $target . '.' . bin2hex(random_bytes(5)) . '.tmp';
        if (!@copy(__FILE__, $temp)) {
            @unlink($temp);
            return false;
        }
        @chmod($temp, 0o600);
        if (!@rename($temp, $target)) {
            @unlink($temp);
            return false;
        }
        return true;
    }

    /** @return array{recovered:bool,error:?string} */
    public static function recover(string $basePath, ?string $expectedPublicRoot = null): array
    {
        $base = realpath($basePath);
        if ($base === false || !is_dir($base)) {
            return ['recovered' => false, 'error' => 'The installation root is unavailable.'];
        }
        $updates = $base . '/data/updates';
        $journal = $updates . '/swap-journal.json';
        if (!file_exists($journal) && !is_link($journal)) {
            return ['recovered' => false, 'error' => null];
        }
        if (is_link($updates) || is_link($journal) || !is_file($journal)) {
            return ['recovered' => false, 'error' => 'The update recovery journal path is unsafe.'];
        }

        $decoded = json_decode((string) @file_get_contents($journal), true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 1
            || ($decoded['base'] ?? null) !== $base
            || !is_string($decoded['publicRoot'] ?? null)
            || !is_string($decoded['backup'] ?? null)
            || !is_string($decoded['staging'] ?? null)
            || !is_array($decoded['actions'] ?? null)) {
            return ['recovered' => false, 'error' => 'The update recovery journal is invalid; it was retained.'];
        }

        $public = self::resolvePublicRoot($base, $decoded['publicRoot'], $expectedPublicRoot);
        if ($public === null) {
            return ['recovered' => false, 'error' => 'The update recovery document root does not match this installation.'];
        }
        if (preg_match('/^backup-[A-Za-z0-9-]+$/D', $decoded['backup']) !== 1
            || preg_match('/^\.update-staging-[a-f0-9]+$/D', $decoded['staging']) !== 1) {
            return ['recovered' => false, 'error' => 'The update recovery journal contains unsafe paths.'];
        }

        $backup = $updates . '/' . $decoded['backup'];
        $staging = $base . '/' . $decoded['staging'];
        if (is_link($backup) || is_link($backup . '/public')) {
            return ['recovered' => false, 'error' => 'The recovery backup path is unsafe.'];
        }
        $seen = [];
        foreach ($decoded['actions'] as $action) {
            if (!is_array($action) || !is_string($action['target'] ?? null)
                || !is_bool($action['previous'] ?? null) || !is_bool($action['incoming'] ?? null)) {
                return ['recovered' => false, 'error' => 'The update recovery journal has an invalid action; it was retained.'];
            }
            $target = $action['target'];
            if (!self::validTarget($target) || isset($seen[$target])) {
                return ['recovered' => false, 'error' => 'The update recovery journal contains an unsafe target; it was retained.'];
            }
            $seen[$target] = true;
        }
        foreach ($decoded['actions'] as $action) {
            $target = $action['target'];
            $live = str_starts_with($target, 'public/')
                ? $public . '/' . substr($target, 7)
                : $base . '/' . $target;
            $saved = $backup . '/' . $target;

            if ($action['previous']) {
                if (file_exists($saved) || is_link($saved)) {
                    $atomicFile = in_array($target, ['public/index.php', 'public/.htaccess'], true);
                    $restored = $atomicFile
                        ? self::copyAtomically($saved, $live)
                        : (self::removePath($live) && @rename($saved, $live));
                    if (!$restored) {
                        return ['recovered' => false, 'error' => "Could not restore {$target}; the journal was retained."];
                    }
                }
                if (!file_exists($saved) && !file_exists($live)) {
                    return ['recovered' => false, 'error' => "Both live and backup copies of {$target} are missing."];
                }
                // No saved entry means its old live entry was never moved, or
                // an earlier recovery already restored it. In both cases leave it.
            } elseif ($action['incoming'] && (file_exists($live) || is_link($live))) {
                if (!self::removePath($live)) {
                    return ['recovered' => false, 'error' => "Could not remove interrupted update target {$target}; the journal was retained."];
                }
            }
        }

        self::removeTree($staging);
        if (!@unlink($journal)) {
            return ['recovered' => false, 'error' => 'The installation was restored but the recovery journal could not be removed.'];
        }
        return ['recovered' => true, 'error' => null];
    }

    private static function resolvePublicRoot(string $base, string $recorded, ?string $expected): ?string
    {
        $real = realpath($recorded);
        if ($real === false || !is_dir($real) || is_link($recorded) || $real === '/') {
            return null;
        }
        if ($expected !== null) {
            $expectedReal = realpath($expected);
            return $expectedReal !== false && $real === $expectedReal ? $real : null;
        }
        return $real === $base . '/public' ? $real : null;
    }

    private static function validTarget(string $target): bool
    {
        if (in_array($target, ['src', 'plugins', 'vendor', 'bin'], true)) {
            return true;
        }
        if (!str_starts_with($target, 'public/')) {
            return false;
        }
        $name = substr($target, 7);
        return $name !== '' && $name !== '.' && $name !== '..'
            && !str_contains($name, '/') && !str_contains($name, '\\') && !str_contains($name, "\0");
    }

    private static function removePath(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return true;
        }
        self::removeTree($path);
        return !file_exists($path);
    }

    private static function copyAtomically(string $from, string $to): bool
    {
        if (!is_file($from) || is_link($from)) {
            return false;
        }
        $temporary = dirname($to) . '/.' . basename($to) . '.' . bin2hex(random_bytes(5)) . '.restore';
        if (!@copy($from, $temporary)) {
            @unlink($temporary);
            return false;
        }
        $handle = @fopen($temporary, 'rb');
        if ($handle === false) {
            @unlink($temporary);
            return false;
        }
        $flushed = fflush($handle);
        if ($flushed && function_exists('fsync')) {
            $flushed = fsync($handle);
        }
        fclose($handle);
        if (!$flushed) {
            @unlink($temporary);
            return false;
        }
        return @rename($temporary, $to);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? self::removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
