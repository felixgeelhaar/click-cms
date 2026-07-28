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
use Click\Cms\Application\Backup\BackupException;
use Click\Cms\Application\Backup\BackupService;
use Click\Cms\Application\Backup\BackupStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Infrastructure\Storage\StorageFactory;

/**
 * Takes a backup of the whole site: every content document in every type and
 * every language, plus the uploaded media files.
 *
 * ## What changed, and why it had to
 *
 * This plugin used to build its archive by walking the `content/` directory.
 * That directory holds documents on exactly one of the four supported backends.
 * On `sqlite`, `mysql`, `mariadb` and `postgres` the documents live in the
 * database, so the archive contained the site's media, not one single content
 * document — and wrote a manifest reporting success. A site backed up nightly
 * for a year had a year of archives that would have restored an empty site.
 *
 * The export now goes through {@see StorageInterface::types()} and
 * `findByType()`, which is the pair that makes "everything in this site"
 * expressible without knowing what a site contains or which backend holds it.
 * The archive is therefore backend-independent: one taken from SQLite restores
 * onto flat files, and that portability is the feature rather than a side
 * effect. {@see \Click\Cms\Application\Backup\BackupExporter} does the work; this
 * plugin is the HTTP surface over it.
 *
 * ## Administrator-only, and the reason is the whole point
 *
 * A backup is not a view of the published site — it is every document the site
 * holds, which includes unpublished drafts and the `user` records carrying
 * password hashes. Handing that to an editor, let alone an anonymous caller,
 * would leak exactly the work draft-and-publish exists to keep private, and the
 * credentials besides. So the capability asked for is
 * {@see Capability::ManageSettings}: the same administrator-only gate the audit
 * trail uses, for the same reason — it is the entire site, not one editor's own
 * work.
 *
 * The kernel's public allowlist needs no change to make this safe. That list is
 * deny-by-default: none of these routes is named in it, so an unauthenticated
 * request is turned away with a 401 before a handler ever runs. The check in each
 * handler is the second, finer gate the coarse guard cannot perform — it does not
 * know a signed-in editor from a signed-in administrator — and is where
 * administrator-only is actually enforced.
 *
 * ## What this plugin deliberately does not do
 *
 * It does not restore. A restore writes over a live site and, on a site of any
 * size, takes long enough that a browser or a proxy will give up in the middle of
 * it — and "the restore was interrupted halfway" is the one outcome worse than
 * the data loss it was repairing. `bin/click-backup.php --restore=` is where that
 * lives, at a console, with a lock, with a verification pass that refuses a bad
 * archive before touching anything.
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
            // Unchanged, and still the thing an administrator reaches for: a
            // self-contained archive streamed as a download.
            'GET /api/backup' => [$this, 'handleBackup'],
            'GET /api/backups' => [$this, 'handleList'],
            'POST /api/backups' => [$this, 'handleCreate'],
        ];
    }

    /**
     * Build an archive and stream it as a file download.
     *
     * Shaped exactly like core's own file serving ({@see CoreApiRoutes::serveMediaFile}):
     * the headers and bytes are emitted here, and the handler returns
     * `['raw' => true]` so the kernel does not wrap the response in JSON. No
     * change to the plugin route mechanism is needed — a raw/binary download is
     * already expressible through it.
     *
     * `?archive=<name>` hands back a retained archive instead of a fresh one.
     * Retained archives keep their media in a shared pool, so they are cheap on
     * disk and meaningless off this server; asking for one converts it to a
     * self-contained archive on the way out. Without that, the nightly backups
     * could never leave the machine they protect against losing.
     *
     * @return array<string, mixed>
     */
    public function handleBackup(): array
    {
        $refusal = $this->refuseUnlessAdministrator();
        if ($refusal !== null) {
            return $refusal;
        }

        $requested = is_string($_GET['archive'] ?? null) ? (string) $_GET['archive'] : '';

        try {
            $archivePath = $requested === ''
                ? $this->createArchive()
                : $this->createPortableCopy($requested);
        } catch (BackupException $e) {
            return ['status' => 400, 'error' => $e->getMessage()];
        }

        // time()/date() are forbidden in the domain because the domain must have
        // no I/O and no clock; this is a plugin sending a response, not domain
        // code, so a timestamped filename is exactly what belongs here.
        $downloadName = 'click-cms-backup-' . date('Ymd-His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($archivePath));
        header('X-Content-Type-Options: nosniff');
        // A backup is the whole site including drafts and password hashes: it
        // must never sit in a shared cache.
        header('Cache-Control: no-store, private');

        readfile($archivePath);
        @unlink($archivePath);

        return ['raw' => true, 'html' => ''];
    }

    /**
     * What backups are retained, newest first.
     *
     * An administrator cannot act on backups they cannot see. Each row says how
     * many documents and media files the archive holds and which backend it came
     * off — enough to notice that last night's backup contains eleven documents
     * when the site has four hundred, which is the failure this feature was
     * rebuilt after.
     *
     * @return array<string, mixed>
     */
    public function handleList(): array
    {
        $refusal = $this->refuseUnlessAdministrator();
        if ($refusal !== null) {
            return $refusal;
        }

        $config = $this->coreConfig();

        return ['data' => [
            'enabled' => $config->backupEnabled(),
            'intervalHours' => $config->backupIntervalHours(),
            'keep' => $config->backupKeep(),
            'includeMedia' => $config->backupIncludeMedia(),
            'maxMediaBytes' => $config->backupMaxMediaBytes(),
            'poolBytes' => $this->store()->pool()->bytesUsed(),
            'backups' => $this->store()->listing(),
        ]];
    }

    /**
     * Take a retained backup now, and apply retention.
     *
     * The same act the cron entry performs, so an administrator who has just made
     * a large change does not have to wait for tonight. It is a POST because it
     * writes: an archive appears, and older ones may be deleted by retention.
     *
     * Deliberately not gated on `core.backup.enabled`. That setting governs the
     * *unattended* schedule — whether the site takes backups nobody asked for —
     * and this is an administrator asking for one, in person.
     *
     * @return array<string, mixed>
     */
    public function handleCreate(): array
    {
        $refusal = $this->refuseUnlessAdministrator();
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            $result = $this->service()->takeBackup($this->coreConfig()->backupKeep());
        } catch (BackupException $e) {
            return ['status' => 500, 'error' => $e->getMessage()];
        }

        $manifest = $result['manifest'];

        return ['data' => [
            'name' => $result['name'],
            'documents' => $manifest->documentCount(),
            'media' => $manifest->mediaCount(),
            // Surfaced rather than buried in the archive: a file the backup does
            // not hold is the one thing an administrator must not discover for
            // the first time while restoring.
            'skippedMedia' => $manifest->skippedMedia,
            'sourceBackend' => $manifest->sourceBackend,
            'pruned' => $result['pruned'],
        ]];
    }

    /* -------------------------------------------------------------- archives -- */

    /**
     * Build a self-contained archive of the whole site and return its path.
     *
     * Separated from {@see handleBackup} so it can be opened and inspected
     * without going through HTTP: the caller streams what this produces. Media
     * bytes go inside the ZIP rather than into the shared pool, because a
     * download that referred to a pool the recipient does not have could not be
     * restored anywhere — which is the only reason to download one.
     *
     * @throws BackupException when the archive cannot be created.
     */
    public function createArchive(): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'click-cms-backup') . '.zip';

        $this->service()->exportPortable($archivePath);

        return $archivePath;
    }

    /**
     * A self-contained copy of a retained archive, for downloading.
     *
     * @throws BackupException when the name is not a retained archive, or the
     *         archive fails verification — a corrupt backup faithfully converted
     *         into a portable corrupt backup would be carried off-site and
     *         trusted, which is worse than an error.
     */
    public function createPortableCopy(string $name): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'click-cms-backup') . '.zip';

        $this->service()->exportPortableCopy($name, $archivePath);

        return $archivePath;
    }

    /* --------------------------------------------------------------- wiring -- */

    /**
     * The installation, for things every site shares. Config only.
     */
    private function basePath(): string
    {
        return $this->pluginManager->getBasePath();
    }

    /**
     * This site's root — its content, its media, its data.
     *
     * Distinct from {@see basePath()} on a multi-site installation, where they
     * are different directories. Backing up `--site=acme` while reading the
     * installation root would archive the primary site's content under the
     * name of somebody else's, which is the worst possible thing for a backup
     * to be wrong about.
     */
    private function siteRoot(): string
    {
        return $this->pluginManager->getSiteRoot();
    }

    private function coreConfig(): CoreConfig
    {
        return CoreConfig::load($this->basePath() . '/config/core.json');
    }

    private function store(): BackupStore
    {
        return new BackupStore($this->siteRoot() . '/data/backups');
    }

    /**
     * The backup service, wired against the *configured* backend.
     *
     * Storage is built here from configuration rather than borrowed from the
     * content service, and that is on purpose: an export must read the live
     * documents from the backend the site is actually configured for, and the
     * decorated service the rest of the application uses adds an audit record to
     * every read path it fronts. A nightly backup is not an editorial act and
     * should not fill the audit trail as though it were.
     */
    private function service(): BackupService
    {
        $config = $this->coreConfig();

        return new BackupService(
            $this->store(),
            $this->storage($config),
            $this->siteRoot() . '/content/media',
            $config->storageBackend(),
            $config->backupIncludeMedia(),
            $config->backupMaxMediaBytes(),
        );
    }

    private function storage(CoreConfig $config): StorageInterface
    {
        return StorageFactory::create($config, $this->siteRoot());
    }

    /* ---------------------------------------------------------- who is asking -- */

    /**
     * The finer of the two gates, applied identically by every route here.
     *
     * A backup contains unpublished drafts and password hashes, so it is
     * administrator-only; the kernel's deny-by-default guard has already refused
     * an anonymous caller, but it cannot tell a signed-in editor from a signed-in
     * administrator, and that distinction is the whole point.
     *
     * @return array<string, mixed>|null the refusal, or null to proceed
     */
    private function refuseUnlessAdministrator(): ?array
    {
        $user = $this->currentUser();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ['status' => 403, 'error' => 'You do not have permission to export a backup.'];
        }

        return null;
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

        return (new SessionStore($this->siteRoot() . '/data/sessions'))->user();
    }
}
