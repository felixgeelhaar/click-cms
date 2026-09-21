<?php

declare(strict_types=1);

namespace Click\Cms\Application\Theme;

use Click\Cms\Domain\Theme\Theme;

/**
 * Finds the themes installed on this site and remembers which one is live.
 *
 * The point of the directory is where it sits: `themes/` is at the installation
 * root, beside `content/` and `data/`, not inside the application. Before this,
 * the only stylesheet a page could link was `public/theme.css` — a file the CMS
 * owns and a deploy overwrites, so "install a theme" meant "edit the product and
 * hope the next upgrade does not take it back". A theme placed here survives an
 * update for the same reason a page does: it is the site's, not the CMS's.
 *
 * Plugins may also ship themes under `plugins/<id>/themes/<theme-id>/`. Those
 * are discovered alongside disk themes; a disk theme with the same id wins, so a
 * site can override a packaged design without editing the plugin. Plugin theme
 * CSS is served through the management API (public GET) because the Apache
 * `/themes` alias only covers the installation themes directory.
 *
 * Discovery is a directory scan rather than a registry, which is what makes
 * installing a theme "copy a folder in" — the same move that installs a plugin.
 * Nothing here throws: a site can put whatever it likes in that directory, so a
 * half-copied theme has to be an entry that quietly does not appear, never a
 * broken admin screen.
 *
 * The active id lives in `data/theme.json`, the writable directory that survives
 * a redeploy, written the same write-then-rename way as settings and content.
 */
final class ThemeRepository
{
    /** The theme a fresh install falls back to before anyone has chosen one. */
    private const FALLBACK_ID = 'default';

    private const MANIFEST = 'theme.json';

    /**
     * Extra roots scanned after `themesDir`. Disk always wins on id collision.
     *
     * @var list<array{dir: string, pluginId: string}>
     */
    private array $pluginRoots = [];

    /**
     * Where each discovered theme's files live, keyed by id. Filled on the
     * latest {@see all()} / {@see find()} so stylesheet URLs and asset serving
     * know whether to use the `/themes` alias or the API passthrough.
     *
     * @var array<string, array{dir: string, pluginId: ?string}>
     */
    private array $locations = [];

    /**
     * @param string $themesDir Where installed themes live, one directory each.
     * @param string $statePath The JSON file holding the active theme id.
     * @param string $urlPrefix The public URL the themes directory is served at.
     */
    public function __construct(
        private readonly string $themesDir,
        private readonly string $statePath,
        private readonly string $urlPrefix = '/themes',
    ) {
    }

    /**
     * The conventional layout, so the kernel does not have to spell out two
     * paths it has no choice about.
     *
     * `$siteRoot` defaults to `$basePath`, which is the single-site case and
     * every existing caller.
     */
    public static function forInstallation(string $basePath, string $urlPrefix = '/themes', ?string $siteRoot = null): self
    {
        return new self(
            $basePath . '/themes',
            ($siteRoot ?? $basePath) . '/data/theme.json',
            $urlPrefix,
        );
    }

    /**
     * Register themes shipped inside a plugin (`plugins/<id>/themes/`).
     *
     * Called after plugin discovery so only folders that exist are scanned.
     * Re-registering the same plugin replaces its previous root.
     */
    public function registerPluginThemes(string $pluginId, string $themesDir): void
    {
        $pluginId = strtolower(trim($pluginId));
        if ($pluginId === '' || !is_dir($themesDir)) {
            return;
        }

        $this->pluginRoots = array_values(array_filter(
            $this->pluginRoots,
            static fn (array $root): bool => $root['pluginId'] !== $pluginId
        ));
        $this->pluginRoots[] = ['dir' => rtrim($themesDir, '/'), 'pluginId' => $pluginId];
        $this->locations = [];
    }

    /**
     * Every installed theme, ordered by id so the admin list does not reshuffle
     * itself between requests on filesystem whim.
     *
     * @return list<Theme>
     */
    public function all(): array
    {
        $this->locations = [];
        $themes = [];

        foreach ($this->scanDir($this->themesDir) as $theme) {
            $themes[$theme->id] = $theme;
            $this->locations[$theme->id] = [
                'dir' => $this->themesDir . '/' . $theme->id,
                'pluginId' => null,
            ];
        }

        foreach ($this->pluginRoots as $root) {
            foreach ($this->scanDir($root['dir']) as $theme) {
                // Disk wins: a site override in themes/ keeps its id.
                if (isset($themes[$theme->id])) {
                    continue;
                }
                $themes[$theme->id] = $theme;
                $this->locations[$theme->id] = [
                    'dir' => $root['dir'] . '/' . $theme->id,
                    'pluginId' => $root['pluginId'],
                ];
            }
        }

        ksort($themes);

        return array_values($themes);
    }

    public function find(string $id): ?Theme
    {
        foreach ($this->all() as $theme) {
            if ($theme->id === $id) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * Provenance for the admin list: disk themes have no plugin id.
     */
    public function pluginIdOf(string $id): ?string
    {
        if ($this->locations === []) {
            $this->all();
        }

        return $this->locations[$id]['pluginId'] ?? null;
    }

    /**
     * Absolute directory holding this theme's files, or null if unknown.
     */
    public function directoryOf(string $id): ?string
    {
        if ($this->locations === []) {
            $this->all();
        }

        return $this->locations[$id]['dir'] ?? null;
    }

    public function active(): ?Theme
    {
        $stored = $this->storedId();
        if ($stored !== null) {
            $theme = $this->find($stored);
            if ($theme !== null) {
                return $theme;
            }
        }

        $fallback = $this->find(self::FALLBACK_ID);
        if ($fallback !== null) {
            return $fallback;
        }

        return $this->all()[0] ?? null;
    }

    public function activate(string $id): bool
    {
        $theme = $this->find($id);
        if ($theme === null) {
            return false;
        }

        return $this->persist($theme->id);
    }

    /**
     * The URL a page links for this theme, carrying a cache-busting version.
     *
     * Disk themes use the `/themes` alias. Plugin themes use a public API path
     * that streams the file, because the alias cannot see into `plugins/`.
     */
    public function stylesheetUrl(Theme $theme): string
    {
        if ($this->locations === []) {
            $this->all();
        }

        $dir = $this->locations[$theme->id]['dir'] ?? ($this->themesDir . '/' . $theme->id);
        $path = $dir . '/' . $theme->stylesheet();
        $mtime = @filemtime($path);
        $version = $mtime !== false ? (string) $mtime : $theme->version;

        $pluginId = $this->locations[$theme->id]['pluginId'] ?? null;
        if ($pluginId !== null) {
            $url = '/api/themes/' . rawurlencode($theme->id) . '/stylesheet';
        } else {
            $url = rtrim($this->urlPrefix, '/') . '/' . $theme->id . '/' . $theme->stylesheet();
        }

        return $version === '' ? $url : $url . '?v=' . rawurlencode($version);
    }

    /**
     * Absolute path of a theme's stylesheet on disk, for the public serve route.
     */
    public function stylesheetPath(Theme $theme): ?string
    {
        $dir = $this->directoryOf($theme->id);
        if ($dir === null) {
            return null;
        }

        $path = $dir . '/' . $theme->stylesheet();

        return is_file($path) ? $path : null;
    }

    /* -------------------------------------------------------------- disk -- */

    /**
     * @return list<Theme>
     */
    private function scanDir(string $themesDir): array
    {
        $entries = @scandir($themesDir);
        if ($entries === false) {
            return [];
        }

        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $theme = $this->readFrom($themesDir, $entry);
            if ($theme !== null) {
                $found[] = $theme;
            }
        }

        return $found;
    }

    private function readFrom(string $themesDir, string $id): ?Theme
    {
        $manifestPath = $themesDir . '/' . $id . '/' . self::MANIFEST;

        if (!$this->isSafeId($id) || !is_file($manifestPath)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($manifestPath), true);
        if (!is_array($decoded)) {
            return null;
        }

        $theme = Theme::fromArray($decoded, $id);
        if ($theme === null) {
            return null;
        }

        if (!is_file($themesDir . '/' . $id . '/' . $theme->stylesheet())) {
            return null;
        }

        return $theme;
    }

    private function isSafeId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) === 1;
    }

    private function storedId(): ?string
    {
        if (!is_file($this->statePath)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($this->statePath), true);
        if (!is_array($decoded)) {
            return null;
        }

        $id = trim((string) ($decoded['active'] ?? ''));

        return $id === '' ? null : $id;
    }

    private function persist(string $id): bool
    {
        $directory = dirname($this->statePath);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return false;
        }

        $json = json_encode(
            ['active' => $id],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $tmp = $this->statePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $this->statePath)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
