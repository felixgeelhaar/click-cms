<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Theme;

/**
 * One installed theme, as described by its `theme.json` manifest.
 *
 * A theme is CSS and nothing else. The renderer emits semantic markup with
 * stable class names (`cms-section--<type>`, `cms-field--<name>`), and a theme
 * targets those names — it never contributes markup, templates or code. That is
 * what makes "the design cannot be broken by an editor" true: there is no seam
 * through which a theme could change what a page *says*, only how it looks.
 *
 * Parsing never throws. Themes are discovered by scanning a directory that a
 * site owns, so a hand-edited or half-copied manifest is an ordinary condition,
 * not an exceptional one; a broken theme must drop out of the list rather than
 * take the admin — or worse, the public site — down with it.
 */
final class Theme
{
    /**
     * A theme id becomes both a URL segment and a path segment, so it is held to
     * the narrowest form that can be neither: lowercase, digits and hyphens, and
     * never leading with a hyphen. Anything else — a dot, a slash, `..` — is
     * refused outright rather than escaped at each use site, because an escape
     * that one caller forgets is a directory traversal.
     */
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]*$/D';

    /** Asset and stylesheet filenames live under the theme directory and are held to the same bar. */
    private const FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';

    private const DEFAULT_STYLESHEET = 'theme.css';

    /**
     * @param list<string> $assets Extra files the theme ships beside its
     *        stylesheet — a font, a background, a print sheet. Recorded so a
     *        packaging or copying step knows what belongs to the theme; the
     *        renderer links only the stylesheet.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        private readonly string $stylesheetFile,
        public readonly array $assets,
    ) {
    }

    /**
     * Read one manifest. Null when it could not describe an installable theme:
     * an unusable id, or no name to show a person choosing between themes.
     *
     * The id is passed in rather than read from the manifest because the
     * directory name is the identity — it is what the URL and the stored active
     * theme refer to. A manifest claiming a different id would give a theme two
     * names for itself, and the one that decides where files are read from would
     * be the one nobody wrote down.
     *
     * @param array<string, mixed> $manifest
     */
    public static function fromArray(array $manifest, string $id): ?self
    {
        $id = strtolower(trim($id));
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $name = trim((string) ($manifest['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $stylesheet = trim((string) ($manifest['stylesheet'] ?? '')) ?: self::DEFAULT_STYLESHEET;
        if (!self::isSafeFile($stylesheet) || !str_ends_with(strtolower($stylesheet), '.css')) {
            return null;
        }

        return new self(
            id: $id,
            name: $name,
            // No fallback to a made-up "1.0.0": the version is what busts caches
            // and what an operator compares, so an absent one should read as
            // absent rather than as a version somebody chose.
            version: trim((string) ($manifest['version'] ?? '')),
            description: trim((string) ($manifest['description'] ?? '')),
            author: trim((string) ($manifest['author'] ?? '')),
            stylesheetFile: $stylesheet,
            assets: self::safeAssets($manifest['assets'] ?? null),
        );
    }

    /**
     * The theme's main CSS file, relative to its own directory. `theme.css`
     * unless the manifest names another, so the common case needs no manifest
     * entry at all.
     */
    public function stylesheet(): string
    {
        return $this->stylesheetFile;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'stylesheet' => $this->stylesheetFile,
            'assets' => $this->assets,
        ];
    }

    /**
     * Anything that is not a plain filename is dropped rather than rejecting the
     * whole theme: an unusable extra asset costs the site a background image,
     * while an unusable stylesheet costs it its design, so only the second is
     * worth refusing to install over.
     *
     * @return list<string>
     */
    private static function safeAssets(mixed $assets): array
    {
        if (!is_array($assets)) {
            return [];
        }

        $safe = [];
        foreach ($assets as $asset) {
            if (!is_string($asset)) {
                continue;
            }
            $asset = trim($asset);
            if (self::isSafeFile($asset)) {
                $safe[] = $asset;
            }
        }

        return array_values(array_unique($safe));
    }

    private static function isSafeFile(string $file): bool
    {
        return preg_match(self::FILE_PATTERN, $file) === 1 && !str_contains($file, '..');
    }
}
