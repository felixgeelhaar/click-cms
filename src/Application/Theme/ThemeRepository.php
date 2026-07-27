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
 * Discovery is a directory scan rather than a registry, which is what makes
 * installing a theme "copy a folder in" — the same move that installs a plugin.
 * Nothing here throws: a site can put whatever it likes in that directory, so a
 * half-copied theme has to be an entry that quietly does not appear, never a
 * broken admin screen.
 *
 * The active id lives in `data/theme.json`, the writable directory that survives
 * a redeploy, written the same write-then-rename way as settings and content.
 * That matters more here than it looks: this file is read on every public page
 * render, so a reader catching a half-written file would take the site's design
 * off mid-save.
 */
final class ThemeRepository
{
    /** The theme a fresh install falls back to before anyone has chosen one. */
    private const FALLBACK_ID = 'default';

    private const MANIFEST = 'theme.json';

    /**
     * @param string $themesDir Where installed themes live, one directory each.
     * @param string $statePath The JSON file holding the active theme id.
     * @param string $urlPrefix The public URL the themes directory is served at.
     *        Configurable because how a site exposes those files — an Apache
     *        alias, a symlink into the document root, a PHP passthrough — is a
     *        deployment decision, and baking one in here would make the other
     *        two impossible without editing the CMS.
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
     * The two roots are separate arguments because they answer different
     * questions once an installation serves more than one site. **Which themes
     * exist** is a property of the installation — they are packages, deployed
     * with the code, and copying eight identical directories per client would be
     * absurd. **Which one is active** is a property of the site: an agency's
     * whole reason for running eight sites is that they do not look alike.
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
     * Every installed theme, ordered by id so the admin list does not reshuffle
     * itself between requests on filesystem whim.
     *
     * A directory is skipped when it has no readable manifest, when the manifest
     * does not describe a usable theme, or when the stylesheet it names is not
     * actually there. The last check is the one that is easy to leave out and
     * worth keeping: a theme listed but missing its CSS is a theme that can be
     * activated to produce an unstyled site and a 404 in the console, which is a
     * far worse failure than never having appeared.
     *
     * @return list<Theme>
     */
    public function all(): array
    {
        $entries = @scandir($this->themesDir);
        if ($entries === false) {
            // No themes directory at all is the state of a fresh install, not an
            // error: nothing is installed yet.
            return [];
        }

        $themes = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $theme = $this->read($entry);
            if ($theme !== null) {
                $themes[$theme->id] = $theme;
            }
        }

        ksort($themes);

        return array_values($themes);
    }

    public function find(string $id): ?Theme
    {
        return $this->read($id);
    }

    /**
     * The theme a page should be rendered with.
     *
     * Falls back rather than returning null while any theme is installed: a site
     * that has never opened the Themes screen, or whose stored choice names a
     * theme somebody has since deleted, must still render with a design. Null
     * means only what it says — nothing is installed to render with.
     */
    public function active(): ?Theme
    {
        $stored = $this->storedId();
        if ($stored !== null) {
            $theme = $this->read($stored);
            if ($theme !== null) {
                return $theme;
            }
        }

        $fallback = $this->read(self::FALLBACK_ID);
        if ($fallback !== null) {
            return $fallback;
        }

        return $this->all()[0] ?? null;
    }

    /**
     * Switch the live theme. False for an id that is not installed — persisting
     * it would leave `data/theme.json` pointing at nothing, and the next render
     * would silently fall back while the admin screen insisted the choice had
     * been saved.
     */
    public function activate(string $id): bool
    {
        $theme = $this->read($id);
        if ($theme === null) {
            return false;
        }

        return $this->persist($theme->id);
    }

    /**
     * The URL a page links for this theme, carrying a cache-busting version.
     *
     * The version is the file's mtime where it can be read, and the theme's
     * declared version otherwise. mtime first because it is the one that changes
     * when it needs to: a designer editing their stylesheet in place almost
     * never bumps a version number, and without this every visitor keeps the old
     * CSS until their browser feels like asking again.
     */
    public function stylesheetUrl(Theme $theme): string
    {
        $path = $this->themesDir . '/' . $theme->id . '/' . $theme->stylesheet();
        $mtime = @filemtime($path);

        $version = $mtime !== false ? (string) $mtime : $theme->version;
        $url = rtrim($this->urlPrefix, '/') . '/' . $theme->id . '/' . $theme->stylesheet();

        return $version === '' ? $url : $url . '?v=' . rawurlencode($version);
    }

    /* -------------------------------------------------------------- disk -- */

    /**
     * Read one theme directory, by the id it would have. Every path this class
     * builds runs through here, so the id is validated — by {@see Theme} — before
     * it is ever concatenated into a path.
     */
    private function read(string $id): ?Theme
    {
        $manifestPath = $this->themesDir . '/' . $id . '/' . self::MANIFEST;

        // Checked before reading rather than relying on the id validation alone:
        // a `..` id would be rejected by Theme, but only after this string had
        // been handed to the filesystem, and a check that runs second is a check
        // a refactor can drop.
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

        if (!is_file($this->themesDir . '/' . $id . '/' . $theme->stylesheet())) {
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

    /**
     * Write-then-rename, as content and settings do: a page render that reads
     * this file mid-save sees the old choice or the new one, never a truncated
     * document that would leave the site unstyled.
     */
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
