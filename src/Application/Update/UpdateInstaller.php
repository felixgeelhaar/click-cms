<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

use Click\Cms\Domain\Update\Release;
use ZipArchive;

/**
 * Applies a release to the installation on disk.
 *
 * This replaces the running application's own code, so it is written to fail
 * safe at every step rather than to succeed elegantly:
 *
 *  1. **Verify before touching anything.** The package is downloaded to a temp
 *     file and its SHA-256 checked against the signed release entry. A mismatch
 *     stops here, with nothing on disk changed.
 *  2. **Never overwrite the site's own data.** `content/`, `data/` and `config/`
 *     are the site — its pages, its uploads, its settings — and a release
 *     package has no business writing them. They are excluded from extraction
 *     outright, so a malicious or careless package cannot replace a page or
 *     rewrite the admin's password hash.
 *  3. **Keep a way back.** The directories about to be replaced are moved aside,
 *     not deleted. If anything fails mid-way they are moved back, and the
 *     install ends where it started.
 *  4. **Validate every archive entry.** Same rules as the plugin installer: no
 *     absolute paths, no `..`, no backslashes, a bounded entry count and total
 *     size. A release package is signed, but a signature proves origin, not
 *     good behaviour.
 *
 * What it deliberately does not do: run migrations, or restart PHP. A release
 * that needs either says so in its notes and is not a candidate for unattended
 * installation.
 */
class UpdateInstaller
{
    /** Directories a release package replaces. Everything else is left alone. */
    private const REPLACEABLE = ['src', 'public', 'plugins', 'vendor', 'bin'];

    /** Never written by an update: this is the site, not the software. */
    private const PROTECTED_PATHS = ['content', 'data', 'config'];

    /**
     * Files inside a replaced directory that belong to the site rather than the
     * release, and so survive an update with the incoming version left beside
     * them as `<name>.dist`.
     *
     * `public/.htaccess` is the whole list, and it earns its place: it is where
     * an installation says where its own code lives (`SetEnv CLICK_CMS_ROOT`)
     * and what URL prefix it answers on (`RewriteBase`). Both of those exist
     * *because* an update replaces `public/` — so replacing that file too took
     * the site down, and did it from an unattended security update, which is the
     * worst way to learn about it: the site could no longer find its own
     * vendor/, so it answered 500, and every clean URL 404'd.
     */
    private const SITE_OWNED_FILES = ['public/.htaccess'];

    private const MAX_ENTRIES = 20000;
    private const MAX_TOTAL_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly string $basePath,
        /** Where backups of a replaced install are kept. */
        private readonly ?string $backupPath = null,
    ) {
    }

    /**
     * Download, verify and apply a release.
     *
     * @return array{success: bool, error: ?string, backup: ?string}
     */
    public function install(Release $release, ?string $localPackage = null): array
    {
        $package = $localPackage ?? $this->download($release->packageUrl);
        if ($package === null) {
            return $this->failure('The release package could not be downloaded.');
        }

        // Verified before a single byte of the installation is touched.
        $actual = hash_file('sha256', $package);
        if (!hash_equals($release->sha256, (string) $actual)) {
            if ($localPackage === null) {
                @unlink($package);
            }
            return $this->failure('The release package does not match its published checksum, so it was not installed.');
        }

        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            return $this->failure('The release package could not be opened.');
        }

        $staging = $this->basePath . '/.update-staging-' . bin2hex(random_bytes(6));
        if (!@mkdir($staging, 0o755, true) && !is_dir($staging)) {
            $zip->close();
            return $this->failure('A staging directory could not be created.');
        }

        $extractError = $this->safeExtract($zip, $staging);
        $zip->close();
        if ($extractError !== null) {
            $this->removeTree($staging);
            return $this->failure($extractError);
        }

        // A package may wrap everything in a single top-level directory.
        $root = $this->resolveRoot($staging);

        $backup = ($this->backupPath ?? $this->basePath . '/data/updates')
            . '/backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($backup, 0o755, true) && !is_dir($backup)) {
            $this->removeTree($staging);
            return $this->failure('A backup directory could not be created; nothing was changed.');
        }

        $moved = [];
        foreach (self::REPLACEABLE as $dir) {
            $incoming = "$root/$dir";
            if (!is_dir($incoming)) {
                continue; // the release does not ship this directory
            }

            $live = $this->basePath . '/' . $dir;
            if (is_dir($live) && !$this->move($live, "$backup/$dir")) {
                $unrestored = $this->rollback($moved, $backup);
                $this->removeTree($staging);
                return $this->swapFailure($dir, $unrestored, $backup);
            }

            if (!$this->move($incoming, $live)) {
                // Put back what was just moved, including this one. Its own
                // restore counts towards what could not be put back.
                $unrestored = $this->move("$backup/$dir", $live) ? [] : [$dir];
                $unrestored = array_merge($unrestored, $this->rollback($moved, $backup));
                $this->removeTree($staging);
                return $this->swapFailure($dir, $unrestored, $backup);
            }

            $moved[] = $dir;
        }

        $this->preserveSiteOwnedFiles($backup);

        $this->removeTree($staging);

        if ($localPackage === null) {
            @unlink($package);
        }

        return ['success' => true, 'error' => null, 'backup' => $backup];
    }

    /**
     * Restore the files a site owns inside the directories an update replaced.
     *
     * Done after the swap rather than by filtering the incoming tree, because
     * the swap is a directory rename — there is no per-file step to hook into,
     * and adding one would trade an atomic move for a recursive copy.
     *
     * The shipped version is kept as `<name>.dist` so a change to it is
     * discoverable: rewrite rules do occasionally gain a line, and an operator
     * who never sees the new one cannot merge it.
     */
    private function preserveSiteOwnedFiles(string $backup): void
    {
        foreach (self::SITE_OWNED_FILES as $relative) {
            $previous = "$backup/$relative";
            if (!is_file($previous)) {
                continue; // the site had none; the shipped file stands
            }

            $live = $this->basePath . '/' . $relative;
            if (is_file($live)) {
                @copy($live, $live . '.dist');
            }

            @copy($previous, $live);
        }
    }

    /**
     * Put a backed-up installation back. Used when an update is applied and the
     * site then fails to serve — the administrator gets one command back.
     */
    public function restore(string $backup): array
    {
        if (!is_dir($backup)) {
            return $this->failure('That backup does not exist.');
        }

        foreach (self::REPLACEABLE as $dir) {
            $saved = "$backup/$dir";
            if (!is_dir($saved)) {
                continue;
            }
            $live = $this->basePath . '/' . $dir;
            if (is_dir($live)) {
                $this->removeTree($live);
            }
            if (!@rename($saved, $live)) {
                return $this->failure("Could not restore \"$dir\".");
            }
        }

        return ['success' => true, 'error' => null, 'backup' => $backup];
    }

    /**
     * Extract one validated entry at a time.
     *
     * Returns an error message, or null on success. Nothing is written for an
     * entry that does not pass, so one bad entry aborts the whole update.
     */
    private function safeExtract(ZipArchive $zip, string $dest): ?string
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return 'The release package has too many entries.';
        }

        $root = rtrim($dest, '/') . '/';
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return 'The release package has an unreadable entry.';
            }
            $name = $stat['name'];

            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')
                || str_contains($name, "\0") || preg_match('#^[A-Za-z]:#', $name) === 1) {
                return 'The release package has an entry with an unsafe path.';
            }
            $parts = explode('/', $name);
            if (in_array('..', $parts, true) || in_array('.', $parts, true)) {
                return 'The release package has an entry with an unsafe path.';
            }

            // The site's own data is never written by an update, whatever the
            // package contains. Checked on the first path segment, and also one
            // level in, so `click-cms-1.2.0/content/…` is caught as well.
            if ($this->isProtected($parts)) {
                continue;
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_TOTAL_BYTES) {
                return 'The release package is too large when extracted.';
            }

            $target = $root . $name;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                return 'A directory could not be created while extracting.';
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                return 'The release package has an unreadable entry.';
            }
            $bytes = stream_get_contents($stream);
            fclose($stream);
            if ($bytes === false || file_put_contents($target, $bytes) === false) {
                return 'A file could not be written while extracting.';
            }
            @chmod($target, 0o644);
        }

        return null;
    }

    /** @param list<string> $parts */
    private function isProtected(array $parts): bool
    {
        if (in_array($parts[0], self::PROTECTED_PATHS, true)) {
            return true;
        }

        return count($parts) > 1 && in_array($parts[1], self::PROTECTED_PATHS, true);
    }

    /**
     * A package usually wraps its files in one top-level directory. When staging
     * contains exactly one directory and nothing else, that is the real root.
     */
    private function resolveRoot(string $staging): string
    {
        $entries = array_values(array_diff(scandir($staging) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir("$staging/{$entries[0]}")) {
            return "$staging/{$entries[0]}";
        }

        return $staging;
    }

    /** @param list<string> $moved */
    /**
     * Move a directory, reporting whether it worked.
     *
     * Protected, and the only reason is that the failure path below cannot
     * otherwise be tested: a half-applied update is the one outcome that must
     * behave correctly, and it is not reachable by feeding the installer bad
     * input. A test overrides this to fail one specific move.
     */
    protected function move(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    /**
     * Put back what was moved, and say what could not be put back.
     *
     * The results used to be discarded, and the caller then reported "the update
     * was rolled back" whether or not any of it had been. That was found on a
     * real installation: the swap failed partway, the message said everything
     * was restored, and the site was left with new code in src/ and no public/
     * at all. An operator reading that message stops looking, which is the worst
     * thing a wrong message can cause.
     *
     * @param list<string> $moved
     * @return list<string> Directories that could NOT be restored.
     */
    private function rollback(array $moved, string $backup): array
    {
        $failed = [];

        foreach ($moved as $dir) {
            $live = $this->basePath . '/' . $dir;
            if (is_dir($live)) {
                $this->removeTree($live);
            }
            if (!$this->move("$backup/$dir", $live)) {
                $failed[] = $dir;
            }
        }

        return $failed;
    }

    /**
     * The failure message for a swap that could not be completed, told
     * accurately.
     *
     * When everything went back, an operator can stop worrying. When it did not,
     * they need three things in one sentence: that the installation is
     * half-applied, which parts, and where the backup is — because finishing the
     * job is now a manual act.
     *
     * @param list<string> $unrestored
     * @return array{success: bool, error: string, backup: ?string}
     */
    private function swapFailure(string $dir, array $unrestored, string $backup): array
    {
        if ($unrestored === []) {
            return $this->failure("Could not install \"$dir\"; the update was rolled back.");
        }

        return [
            'success' => false,
            'error' => "Could not install \"$dir\", and the installation could not be put back: "
                . implode(', ', $unrestored)
                . " could not be restored. The site is part-updated and may not serve. "
                . "A copy of what was replaced is in $backup — restore it before trying again.",
            'backup' => $backup,
        ];
    }

    private function download(string $url): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clickcms-update-');
        if ($tmp === false) {
            return null;
        }

        $context = stream_context_create(['http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'max_redirects' => 3,
            'user_agent' => 'ClickCMS-Updater',
        ]]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false || @file_put_contents($tmp, $data) === false) {
            @unlink($tmp);
            return null;
        }

        return $tmp;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return array{success: bool, error: string, backup: null} */
    private function failure(string $error): array
    {
        return ['success' => false, 'error' => $error, 'backup' => null];
    }
}
