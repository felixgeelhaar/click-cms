<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

// Required defensively as well as relied on through the autoloader. A plugin is
// loaded by an include, and nothing guarantees Composer's autoloader is present
// at that moment, so the first-party classes this plugin needs are pulled in by
// hand — no runtime Composer dependency is added, these are core's own files.
foreach ([
    '/../../src/Application/Authentication/SessionStore.php',
    '/../../src/Domain/Identity/Capability.php',
    '/../../src/Domain/Identity/Role.php',
] as $__dep) {
    if (is_file(__DIR__ . $__dep)) {
        require_once __DIR__ . $__dep;
    }
}

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * Takes a downloadable backup of the whole site: every content document in every
 * language, the media metadata, and the uploaded media files.
 *
 * Administrator-only, and the reason is the whole point. A backup is not a view
 * of the published site — it is the entire content directory, which includes
 * unpublished drafts and every version an editor has not yet chosen to make
 * public. Handing that to an editor, let alone an anonymous caller, would leak
 * exactly the work draft-and-publish exists to keep private. So the capability
 * asked for is {@see Capability::ManageSettings}: the same administrator-only
 * gate the audit trail uses, for the same reason — it is the entire site, not
 * one editor's own work.
 *
 * The kernel's public allowlist needs no change to make this safe. That list is
 * deny-by-default: `GET /api/backup` is not named in it, so an unauthenticated
 * request is turned away with a 401 before this handler ever runs. The check
 * below is the second, finer gate the coarse guard cannot perform — it does not
 * know a signed-in editor from a signed-in administrator — and is where
 * administrator-only is actually enforced.
 */
class Plugin_backup extends \Click\Cms\Application\Plugin\BasePlugin
{
    public function getPluginId(): string
    {
        return 'backup';
    }

    public function getPluginName(): string
    {
        return 'Backup';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string|array{0: object, 1: string}>
     */
    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/backup' => [$this, 'handleBackup'],
        ];
    }

    /**
     * Build the archive and stream it as a file download.
     *
     * Shaped exactly like core's own file serving ({@see CoreApiRoutes::serveMediaFile}):
     * the headers and bytes are emitted here, and the handler returns
     * `['raw' => true]` so the kernel does not wrap the response in JSON. No
     * change to the plugin route mechanism is needed — a raw/binary download is
     * already expressible through it.
     *
     * @return array<string, mixed>
     */
    public function handleBackup(): array
    {
        // The finer of the two gates. A backup contains unpublished drafts, so it
        // is administrator-only; the kernel's deny-by-default guard has already
        // refused an anonymous caller, but it cannot tell a signed-in editor from
        // a signed-in administrator, and that distinction is the whole point here.
        $user = $this->currentUser();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ['status' => 403, 'error' => 'You do not have permission to export a backup.'];
        }

        $archivePath = $this->createArchive();

        // time()/date() are forbidden in the domain because the domain must have
        // no I/O and no clock; this is a plugin sending a response, not domain
        // code, so a timestamped filename is exactly what belongs here.
        $downloadName = 'click-cms-backup-' . date('Ymd-His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($archivePath));
        header('X-Content-Type-Options: nosniff');
        // A backup is the whole site including drafts: it must never sit in a
        // shared cache.
        header('Cache-Control: no-store, private');

        readfile($archivePath);
        @unlink($archivePath);

        return ['raw' => true, 'html' => ''];
    }

    /**
     * Build a ZIP of everything under the content root and return its path.
     *
     * Separated from {@see handleBackup} so it can be opened and inspected
     * without going through HTTP: the caller streams what this produces.
     *
     * @throws \RuntimeException when the archive cannot be created.
     */
    public function createArchive(): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'click-cms-backup') . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the backup archive.');
        }

        $files = $this->collectContentFiles();

        foreach ($files as $absolute => $entry) {
            $zip->addFile($absolute, $entry);
        }

        // A manifest is cheap and makes the archive self-describing: what it is,
        // when it was taken, and exactly which entries it should contain — so a
        // truncated download is detectable rather than a silent partial backup.
        $zip->addFromString('manifest.json', (string) json_encode([
            'generator' => 'click-cms',
            'generatedAt' => date('c'),
            'contentRoot' => 'content',
            'fileCount' => count($files),
            'entries' => array_values($files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zip->close();

        return $archivePath;
    }

    /**
     * Every file under the content root, mapped to its entry name in the archive.
     *
     * The list is a directory walk of the content root — never anything a caller
     * supplied — so a request cannot steer it at another path. Each file's real
     * location is then checked to sit inside the content root before it is added:
     * a symlink pointing out of the tree resolves to a real path that is not
     * under the root and is dropped, so a backup can never copy out whatever such
     * a link aimed at.
     *
     * @return array<string, string> absolute path => archive entry name
     */
    private function collectContentFiles(): array
    {
        $out = [];

        $root = realpath($this->contentRoot());
        if ($root === false || !is_dir($root)) {
            return $out;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $absolute = $file->getRealPath();
            if ($absolute === false || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = substr($absolute, strlen($root) + 1);
            // Store under `content/` so the archive mirrors the installation
            // layout and can be unpacked straight back over a content directory.
            $out[$absolute] = 'content/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        // A stable order makes two backups of the same content comparable.
        ksort($out);

        return $out;
    }

    private function contentRoot(): string
    {
        return $this->pluginManager->getBasePath() . '/content';
    }

    /**
     * The signed-in account, read from the same session store the kernel uses.
     *
     * The plugin is not handed the current user, so it reads the session itself
     * — the file named by the browser's cookie, under the installation's session
     * directory — exactly as the kernel does, rather than trusting anything in
     * the request.
     *
     * @return array<string, mixed>|null
     */
    private function currentUser(): ?array
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        return (new SessionStore($this->pluginManager->getBasePath() . '/data/sessions'))->user();
    }
}
