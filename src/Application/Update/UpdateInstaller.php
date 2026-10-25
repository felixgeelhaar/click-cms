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
    /** Canonical root captured before a swap so rollback can target a missing path. */
    private ?string $activePublicRoot = null;
    /** Directories a release package replaces. Everything else is left alone. */
    private const REPLACEABLE = ['src', 'public', 'plugins', 'vendor', 'bin'];

    /** Never written by an update: this is the site, not the software. */
    private const PROTECTED_PATHS = ['content', 'data', 'config'];

    private const MAX_ENTRIES = 20000;
    private const MAX_TOTAL_BYTES = 200 * 1024 * 1024;
    private const LOCK_FILE = 'install.lock';

    public function __construct(
        private readonly string $basePath,
        /** Where backups of a replaced install are kept. */
        private readonly ?string $backupPath = null,
        /** Detached document root, when the installation uses a split root. */
        private readonly ?string $publicRoot = null,
    ) {
    }

    /**
     * Download, verify and apply a release.
     *
     * @return array{success: bool, error: ?string, backup: ?string}
     */
    public function install(Release $release, ?string $localPackage = null): array
    {
        if (\Click\Cms\Application\Config\DeploymentMode::managed()) {
            return $this->failure('Code installation is controlled by the deployment.');
        }
        $lock = $this->acquireInstallLock();
        if ($lock === false) {
            return $this->failure('Another update or restore is already in progress.');
        }

        try {
            return $this->installLocked($release, $localPackage);
        } finally {
            $this->activePublicRoot = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{success: bool, error: ?string, backup: ?string} */
    private function installLocked(Release $release, ?string $localPackage = null): array
    {
        if (file_exists($this->manifestPath()) || is_link($this->manifestPath())) {
            return $this->failure('An earlier update needs recovery before another can start.');
        }
        $this->activePublicRoot = $this->resolvePublicRoot();
        if ($this->activePublicRoot === null) {
            return $this->failure('The configured public root is missing, unsafe, or not an absolute directory; nothing was changed.');
        }
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

        $hasReplaceableDirectory = false;
        foreach (self::REPLACEABLE as $dir) {
            if (is_dir("$root/$dir")) {
                $hasReplaceableDirectory = true;
                break;
            }
        }
        if (!$hasReplaceableDirectory) {
            $this->removeTree($staging);
            return $this->failure('The release package contains no installable software directories.');
        }

        $backup = ($this->backupPath ?? $this->basePath . '/data/updates')
            . '/backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $updatesRoot = realpath($this->basePath . '/data/updates');
        $backupRoot = realpath($this->backupPath ?? $this->basePath . '/data/updates');
        if ($updatesRoot === false || $backupRoot !== $updatesRoot) {
            $this->removeTree($staging);
            return $this->failure('Update backups must be stored under data/updates for crash recovery.');
        }
        if (!@mkdir($backup, 0o755, true) && !is_dir($backup)) {
            $this->removeTree($staging);
            return $this->failure('A backup directory could not be created; nothing was changed.');
        }

        $result = RequestGate::write($this->basePath, function () use ($root, $backup, $staging, $localPackage, $package): array {
        $liveHtaccess = $this->livePath('public') . '/.htaccess';
        if (is_file($liveHtaccess) && is_file($root . '/public/.htaccess')
            && (file_exists($root . '/public/.htaccess.dist') || is_link($root . '/public/.htaccess.dist'))) {
            $this->removeTree($staging);
            return $this->failure('The release package conflicts with the reserved public/.htaccess.dist site file.');
        }
        $actions = $this->swapActions($root);
        if ($actions === []) {
            $this->removeTree($staging);
            return $this->failure('The release package contains no installable software directories.');
        }
        $manifest = $this->manifestPath();
        if (file_exists($manifest) || is_link($manifest)) {
            $this->removeTree($staging);
            return $this->failure('An earlier update needs recovery before another can start.');
        }
        if (!UpdateRecovery::installBootstrapCopy($this->basePath)) {
            $this->removeTree($staging);
            return $this->failure('The update recovery bootstrap could not be saved; nothing was changed.');
        }
        if (!$this->writeManifest($manifest, $this->activePublicRoot, basename($backup), basename($staging), $actions)) {
            $this->removeTree($staging);
            return $this->failure('The update journal could not be written; nothing was changed.');
        }

        $moved = [];
        foreach ($actions as $action) {
            $dir = $action['target'];
            $live = $this->actionLivePath($dir);
            $saved = "$backup/$dir";
            $incoming = "$root/" . ($action['source'] ?? $dir);

            if ($action['previous'] && !is_dir(dirname($saved))
                && !@mkdir(dirname($saved), 0o755, true) && !is_dir(dirname($saved))) {
                $unrestored = $this->rollbackActions($moved, $backup);
                $this->finishJournalAfterRollback($unrestored, $manifest, $staging);
                return $this->swapFailure($dir, $unrestored, $backup);
            }

            if ($action['previous'] && $this->isAtomicPublicFile($dir)) {
                if (!$this->copyAtomically($live, $saved)) {
                    $unrestored = $this->rollbackActions($moved, $backup);
                    $this->finishJournalAfterRollback($unrestored, $manifest, $staging);
                    return $this->swapFailure($dir, $unrestored, $backup);
                }
            } elseif ($action['previous'] && !$this->move($live, $saved)) {
                $unrestored = $this->rollbackActions($moved, $backup);
                $this->finishJournalAfterRollback($unrestored, $manifest, $staging);
                return $this->swapFailure($dir, $unrestored, $backup);
            }

            if ($action['incoming'] && !$this->move($incoming, $live)) {
                $failed = [];
                if ($action['previous'] && !$this->move($saved, $live)) {
                    $failed[] = $dir;
                }
                $unrestored = array_merge($failed, $this->rollbackActions($moved, $backup));
                $this->finishJournalAfterRollback($unrestored, $manifest, $staging);
                return $this->swapFailure($dir, $unrestored, $backup);
            }
            $moved[] = $action;
        }

        $this->removeTree($staging);
        if (!@unlink($manifest)) {
            return $this->failure('The update journal could not be cleared; recovery is required before serving the site.');
        }

        if ($localPackage === null) {
            @unlink($package);
        }

        return ['success' => true, 'error' => null, 'backup' => $backup];

        });
        if ($result === null) {
            $this->removeTree($staging);
            if ($localPackage === null) {
                @unlink($package);
            }
            return $this->failure('Active requests prevented the update; it will be retried.');
        }
        return $result;
    }

    /** @return list<array{target:string,previous:bool,incoming:bool}> */
    private function swapActions(string $stagedRoot): array
    {
        $actions = [];
        foreach (self::REPLACEABLE as $dir) {
            if ($dir === 'public') {
                // Keep the live document-root directory itself in place. In
                // particular, public/index.php must remain addressable so a
                // killed updater can enter the pre-autoload recovery bootstrap.
                $liveRoot = $this->livePath('public');
                $incomingRoot = "$stagedRoot/public";
                if (!is_dir($incomingRoot)) {
                    continue;
                }
                $names = array_unique(array_merge(
                    array_values(array_diff(scandir($liveRoot) ?: [], ['.', '..'])),
                    array_values(array_diff(scandir($incomingRoot) ?: [], ['.', '..'])),
                ));
                sort($names);
                foreach ($names as $name) {
                    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, "\\")) {
                        continue;
                    }
                    $target = "public/$name";
                    $oldExists = file_exists("$liveRoot/$name") || is_link("$liveRoot/$name");
                    $newExists = file_exists("$incomingRoot/$name") || is_link("$incomingRoot/$name");
                    if ($name === '.htaccess' && $oldExists) {
                        // Keep host routing/root configuration live throughout
                        // the swap. The shipped version is kept for review as
                        // .htaccess.dist; the previous file remains in backup.
                        $actions[] = ['target' => $target, 'previous' => true, 'incoming' => false];
                        if ($newExists) {
                            $dist = '.htaccess.dist';
                            $actions[] = [
                                'target' => 'public/' . $dist,
                                'previous' => file_exists("$liveRoot/$dist") || is_link("$liveRoot/$dist"),
                                'incoming' => true,
                                'source' => 'public/.htaccess',
                            ];
                        }
                        continue;
                    }
                    if ($name === '.htaccess.dist' && file_exists("$liveRoot/.htaccess")
                        && file_exists("$incomingRoot/.htaccess")) {
                        continue; // already represented by the .htaccess special case above
                    }
                    $actions[] = [
                        'target' => $target,
                        'previous' => $oldExists,
                        'incoming' => $newExists,
                    ];
                }
                continue;
            }

            $incoming = "$stagedRoot/$dir";
            if (!is_dir($incoming)) {
                continue;
            }
            $live = $this->basePath . '/' . $dir;
            $actions[] = [
                'target' => $dir,
                'previous' => is_dir($live) || is_link($live),
                'incoming' => true,
            ];
        }

        return $actions;
    }

    private function actionLivePath(string $target): string
    {
        if (str_starts_with($target, 'public/')) {
            return $this->activePublicRoot . '/' . substr($target, strlen('public/'));
        }
        return $this->basePath . '/' . $target;
    }

    private function isAtomicPublicFile(string $target): bool
    {
        return in_array($target, ['public/index.php', 'public/.htaccess'], true);
    }

    private function manifestPath(): string
    {
        return $this->basePath . '/data/updates/swap-journal.json';
    }

    /** @param list<array{target:string,previous:bool,incoming:bool}> $actions */
    private function writeManifest(string $path, string $publicRoot, string $backupName, string $stagingName, array $actions): bool
    {
        $payload = [
            'format' => 1,
            'base' => realpath($this->basePath) ?: $this->basePath,
            'publicRoot' => $publicRoot,
            'backup' => $backupName,
            'staging' => $stagingName,
            'actions' => $actions,
        ];
        $temp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            @unlink($temp);
            return false;
        }
        $handle = @fopen($temp, 'xb');
        if ($handle === false) {
            @unlink($temp);
            return false;
        }
        $written = fwrite($handle, $json);
        $flushed = $written === strlen($json) && fflush($handle);
        if ($flushed && function_exists('fsync')) {
            $flushed = fsync($handle);
        }
        fclose($handle);
        if (!$flushed) {
            @unlink($temp);
            return false;
        }
        @chmod($temp, 0o600);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            return false;
        }
        return true;
    }

    /** @param list<array{target:string,previous:bool,incoming:bool}> $moved */
    private function rollbackActions(array $moved, string $backup): array
    {
        $failed = [];
        foreach (array_reverse($moved) as $action) {
            $target = $action['target'];
            $live = $this->actionLivePath($target);
            $saved = "$backup/$target";
            if ($action['incoming'] && (file_exists($live) || is_link($live))
                && !($action['previous'] && $this->isAtomicPublicFile($target))
                && !$this->removePath($live)) {
                $failed[] = $target;
                continue;
            }
            if ($action['previous'] && (file_exists($saved) || is_link($saved))) {
                if ($this->isAtomicPublicFile($target)) {
                    if (!$this->copyAtomically($saved, $live)) {
                        $failed[] = $target;
                    }
                } elseif (!$this->move($saved, $live)) {
                    $failed[] = $target;
                }
            }
        }
        return $failed;
    }

    private function removePath(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (is_dir($path)) {
            $this->removeTree($path);
            return !file_exists($path);
        }
        return true;
    }

    private function copyAtomically(string $from, string $to): bool
    {
        $tmp = dirname($to) . '/.' . basename($to) . '.' . bin2hex(random_bytes(5)) . '.restore';
        if (!@copy($from, $tmp)) {
            @unlink($tmp);
            return false;
        }
        $handle = @fopen($tmp, 'rb');
        if ($handle === false) {
            @unlink($tmp);
            return false;
        }
        $flushed = fflush($handle);
        if ($flushed && function_exists('fsync')) {
            $flushed = fsync($handle);
        }
        fclose($handle);
        if (!$flushed) {
            @unlink($tmp);
            return false;
        }
        return @rename($tmp, $to);
    }

    /** @param list<string> $unrestored */
    private function finishJournalAfterRollback(array $unrestored, string $manifest, string $staging): void
    {
        if ($unrestored === []) {
            @unlink($manifest);
        }
        $this->removeTree($staging);
    }

    /**
     * Put a backed-up installation back. Used when an update is applied and the
     * site then fails to serve — the administrator gets one command back.
     */
    public function restore(string $backup): array
    {
        if (file_exists($this->manifestPath()) || is_link($this->manifestPath())) {
            return $this->failure('An earlier update needs recovery before another can start.');
        }
        $lock = $this->acquireInstallLock();
        if ($lock === false) {
            return $this->failure('Another update or restore is already in progress.');
        }

        try {
            $result = RequestGate::write($this->basePath, fn (): array => $this->restoreLocked($backup));
            return $result ?? $this->failure('Active requests prevented the restore; it can be retried.');
        } finally {
            $this->activePublicRoot = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{success: bool, error: ?string, backup: ?string} */
    private function restoreLocked(string $backup): array
    {
        $this->activePublicRoot = $this->resolvePublicRoot();
        if ($this->activePublicRoot === null) {
            return $this->failure('The configured public root is missing, unsafe, or not an absolute directory; nothing was changed.');
        }
        if (!is_dir($backup)) {
            return $this->failure('That backup does not exist.');
        }
        $backupRoot = $this->backupPath ?? $this->basePath . '/data/updates';
        $realBackupRoot = realpath($backupRoot);
        $realBackup = realpath($backup);
        if (is_link($backup) || $realBackupRoot === false || $realBackup === false
            || dirname($realBackup) !== $realBackupRoot || !str_starts_with(basename($realBackup), 'backup-')) {
            return $this->failure('That backup is not a safe directory.');
        }

        $restoreDirs = [];
        foreach (self::REPLACEABLE as $dir) {
            $saved = "$backup/$dir";
            if (is_link($saved)) {
                return $this->failure("Could not restore unsafe backup directory \"$dir\".");
            }
            if (is_dir($saved)) {
                $restoreDirs[] = $dir;
            }
        }
        if ($restoreDirs === []) {
            return $this->failure('That backup contains no restorable software directories.');
        }

        $recovery = $this->basePath . '/.update-restore-' . bin2hex(random_bytes(6));
        if (!@mkdir($recovery, 0o700)) {
            return $this->failure('A restore recovery directory could not be created.');
        }

        $stagedLive = [];
        $restored = [];
        foreach ($restoreDirs as $dir) {
            $saved = "$backup/$dir";
            $live = $this->livePath($dir) ?? ($this->basePath . '/' . $dir);
            $recoveryPath = "$recovery/$dir";

            $hasLive = is_dir($live) || is_link($live);
            if ($hasLive && !$this->move($live, $recoveryPath)) {
                return $this->finishRestoreFailure(
                    "Could not move the live \"$dir\" aside.",
                    $stagedLive,
                    $restored,
                    $backup,
                    $recovery,
                );
            }
            $stagedLive[] = $dir;

            if (!$this->move($saved, $live)) {
                return $this->finishRestoreFailure(
                    "Could not restore \"$dir\".",
                    $stagedLive,
                    $restored,
                    $backup,
                    $recovery,
                );
            }
            $restored[] = $dir;
        }

        $this->removeTree($recovery);

        return ['success' => true, 'error' => null, 'backup' => $backup];
    }

    /**
     * Roll back a failed restore, preserving the backup contents whenever each
     * reverse move succeeds. Recovery files are retained if any live path could
     * not be put back, and the returned error names both paths for repair.
     *
     * @param list<string> $stagedLive
     * @param list<string> $restored
     * @return array{success: false, error: string, backup: ?string}
     */
    private function finishRestoreFailure(
        string $cause,
        array $stagedLive,
        array $restored,
        string $backup,
        string $recovery,
    ): array {
        $restoredSet = array_fill_keys($restored, true);
        $unrestored = [];

        foreach (array_reverse($stagedLive) as $dir) {
            $live = $this->livePath($dir) ?? ($this->basePath . '/' . $dir);
            $saved = "$backup/$dir";
            $recoveryPath = "$recovery/$dir";

            if (isset($restoredSet[$dir]) && (is_dir($live) || is_link($live))
                && !$this->move($live, $saved)) {
                $unrestored[] = $dir;
                continue;
            }

            if ((is_dir($recoveryPath) || is_link($recoveryPath)) && !$this->move($recoveryPath, $live)) {
                $unrestored[] = $dir;
            }
        }

        if ($unrestored === []) {
            $this->removeTree($recovery);
            return $this->failure($cause . ' The restore was rolled back.');
        }

        return [
            'success' => false,
            'error' => $cause . ' The restore could not be fully rolled back for: '
                . implode(', ', array_values(array_unique($unrestored)))
                . '. Recovery files remain in ' . $recovery . '; the original backup is in ' . $backup . '.',
            'backup' => $backup,
        ];
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

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                && $opsys === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 0170000) === 0120000) {
                return 'The release package contains a symlink entry.';
            }

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

            // Only materialize files that can belong to a replaceable software
            // directory. Unknown package content is ignored rather than staged.
            if (!$this->isReplaceablePath($parts)) {
                continue;
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $declaredSize = (int) ($stat['size'] ?? -1);
            if ($declaredSize < 0 || $total + $declaredSize > self::MAX_TOTAL_BYTES) {
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
            $output = @fopen($target, 'wb');
            if ($output === false) {
                fclose($stream);
                return 'A file could not be written while extracting.';
            }
            $entryBytes = 0;
            $writeFailed = false;
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    $writeFailed = true;
                    break;
                }
                if ($chunk === '') {
                    continue;
                }
                $chunkLength = strlen($chunk);
                $entryBytes += $chunkLength;
                if ($entryBytes > $declaredSize || $total + $entryBytes > self::MAX_TOTAL_BYTES
                    || fwrite($output, $chunk) !== $chunkLength) {
                    $writeFailed = true;
                    break;
                }
            }
            fclose($stream);
            fclose($output);
            if ($writeFailed || $entryBytes !== $declaredSize) {
                @unlink($target);
                return $entryBytes > $declaredSize || $total + $entryBytes > self::MAX_TOTAL_BYTES
                    ? 'The release package is too large when extracted.'
                    : 'A file could not be written while extracting.';
            }
            $total += $entryBytes;
            @chmod($target, 0o644);
        }

        return null;
    }

    /** Resolve the live location of a replaceable directory. */
    private function livePath(string $directory): ?string
    {
        if ($directory !== 'public') {
            return $this->basePath . '/' . $directory;
        }
        if ($this->activePublicRoot !== null) {
            return $this->activePublicRoot;
        }
        return $this->resolvePublicRoot();
    }

    private function resolvePublicRoot(): ?string
    {
        if ($this->publicRoot === null) {
            return $this->basePath . '/public';
        }
        if ($this->publicRoot === '' || !str_starts_with($this->publicRoot, '/') || is_link(rtrim($this->publicRoot, '/'))) {
            return null;
        }
        $resolved = realpath($this->publicRoot);
        if ($resolved === false || !is_dir($resolved) || $resolved === '/') {
            return null;
        }

        // A configured document root must not alias the application root or
        // any of its protected/code directories. The conventional in-tree
        // public directory remains valid; other in-tree roots are ambiguous.
        $base = realpath($this->basePath);
        if ($base !== false) {
            $insideBase = str_starts_with($resolved, rtrim($base, '/') . '/');
            if ($resolved === $base || ($insideBase && $resolved !== $base . '/public')
                || str_starts_with($base, rtrim($resolved, '/') . '/')) {
                return null;
            }
        }

        return $resolved;
    }

    /** @param list<string> $parts */
    private function isProtected(array $parts): bool
    {
        if (in_array($parts[0], self::PROTECTED_PATHS, true)) {
            return true;
        }

        return count($parts) > 1 && in_array($parts[1], self::PROTECTED_PATHS, true);
    }

    /** @param list<string> $parts */
    private function isReplaceablePath(array $parts): bool
    {
        return in_array($parts[0], self::REPLACEABLE, true)
            || (count($parts) > 1 && in_array($parts[1], self::REPLACEABLE, true));
    }

    /**
     * A package usually wraps its files in one top-level directory. When staging
     * contains exactly one directory and nothing else, that is the real root.
     */
    private function resolveRoot(string $staging): string
    {
        $entries = array_values(array_diff(scandir($staging) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir("$staging/{$entries[0]}")
            && !in_array($entries[0], [...self::REPLACEABLE, ...self::PROTECTED_PATHS], true)) {
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
            $live = $this->livePath($dir) ?? ($this->basePath . '/' . $dir);
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
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
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

    /** @return resource|false */
    private function acquireInstallLock()
    {
        $directory = $this->basePath . '/data/updates';
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            return false;
        }
        if (is_link($directory)) {
            return false;
        }

        $handle = @fopen($directory . '/' . self::LOCK_FILE, 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    /** @return array{success: bool, error: string, backup: null} */
    private function failure(string $error): array
    {
        return ['success' => false, 'error' => $error, 'backup' => null];
    }
}
