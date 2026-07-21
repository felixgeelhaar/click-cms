<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use RuntimeException;

/**
 * Flat-file content storage: one JSON document per item, grouped by type and
 * language.
 *
 *   {contentDir}/{type}/{locale}/{slug}.json
 *
 * Chosen as the default because it needs no database and therefore runs on
 * plain PHP shared hosting, which is where small sites actually live.
 *
 * Writes go through a temporary file and an atomic rename, so a crash or a
 * concurrent read can never observe a half-written document — the failure mode
 * that silently corrupts flat-file CMSs.
 *
 * ## The layout before languages
 *
 * Documents used to live at `{contentDir}/{type}/{slug}.json`, with no language
 * segment. Those files are still read, and are treated as belonging to the
 * site's default locale — an upgrade that lost every existing page would be a
 * far worse bug than any this change fixes. A legacy document is migrated to
 * the new layout the first time it is saved, and only then, so a site that is
 * upgraded but never edited keeps working untouched.
 */
final class JsonStorage implements StorageInterface
{
    private readonly Locale $defaultLocale;

    /**
     * @param ?Locale $defaultLocale Which language the pre-languages layout is
     *        taken to hold. Comes from `core.languages.default`.
     */
    public function __construct(
        private readonly string $contentDir,
        ?Locale $defaultLocale = null,
    ) {
        $this->defaultLocale = $defaultLocale ?? Locale::default();
    }

    public function find(ContentKey $key): ?Content
    {
        // A key that cannot name a file is simply a miss. Reads are reached
        // straight from URLs, so throwing here would turn every request for
        // `/some/path` into a 500 instead of a 404.
        if (!$this->isSafeKey($key)) {
            return null;
        }

        foreach ($this->candidatePaths($key) as $path) {
            $content = $this->read($path, $key);
            if ($content !== null) {
                return $content;
            }
        }

        return null;
    }

    public function findByType(string $type, ?Locale $locale = null): array
    {
        if (!ContentKeyRules::isSafeSegment($type)) {
            return [];
        }

        $dir = $this->contentDir . '/' . $type;
        if (!is_dir($dir)) {
            return [];
        }

        // Keyed by "locale/slug" so a document that exists in both layouts is
        // returned once. Without this a page saved after the upgrade, whose
        // legacy file had not yet been cleaned up, appeared twice in the admin
        // listing — once current, once stale.
        $found = [];

        foreach ($this->localeDirectories($dir) as $code => $localeDir) {
            foreach ($this->jsonFiles($localeDir) as $file) {
                $key = ContentKey::fromString($type . ':' . $code . ':' . basename($file, '.json'));
                $found[$code . '/' . $key->slug] = $key;
            }
        }

        // The pre-languages layout: files sitting directly under the type.
        foreach ($this->jsonFiles($dir) as $file) {
            $slug = basename($file, '.json');
            $index = $this->defaultLocale->code . '/' . $slug;

            if (!isset($found[$index])) {
                $found[$index] = ContentKey::fromString(
                    $type . ':' . $this->defaultLocale->code . ':' . $slug
                );
            }
        }

        // glob() order is filesystem-dependent, and locale directories arrive
        // in whatever order the directory listing gives; sort so listings are
        // stable across machines.
        ksort($found, SORT_STRING);

        $out = [];
        foreach ($found as $key) {
            if ($locale !== null && !$key->locale->equals($locale)) {
                continue;
            }

            $content = $this->find($key);
            if ($content !== null) {
                $out[] = $content;
            }
        }

        return $out;
    }

    public function save(Content $content): void
    {
        $path = $this->pathFor($content->key);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create content directory: {$dir}");
        }

        $json = json_encode(
            $content->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        // Write-then-rename: rename() is atomic within a filesystem, so readers
        // see either the old document or the new one, never a partial write.
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write content file: {$path}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to commit content file: {$path}");
        }

        // Migration, and only after the new file is safely in place: leaving the
        // old one behind would mean every future read had two candidates and the
        // edit that was just made could be served or not depending on which
        // layout won.
        $this->removeLegacyFile($content->key);
    }

    public function delete(ContentKey $key): bool
    {
        if (!$this->isSafeKey($key)) {
            return false;
        }

        $deleted = false;

        foreach ($this->candidatePaths($key) as $path) {
            if (is_file($path) && @unlink($path)) {
                $deleted = true;
            }
        }

        return $deleted;
    }

    public function exists(ContentKey $key): bool
    {
        if (!$this->isSafeKey($key)) {
            return false;
        }

        foreach ($this->candidatePaths($key) as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every place a document with this key could be, most current first.
     *
     * @return list<string>
     */
    private function candidatePaths(ContentKey $key): array
    {
        $paths = [$this->pathFor($key)];

        $legacy = $this->legacyPathFor($key);
        if ($legacy !== null) {
            $paths[] = $legacy;
        }

        return $paths;
    }

    private function read(string $path, ContentKey $key): ?Content
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $row = json_decode($raw, true);
        if (!is_array($row)) {
            // A corrupt file should not take down a listing; treat it as absent.
            return null;
        }

        // Where the file sits decides what it is, not what it claims. A document
        // written before languages says `page:home`, which would otherwise parse
        // as English on a site whose default language is German.
        $row['key'] = $key->toString();

        return Content::fromArray($row);
    }

    private function pathFor(ContentKey $key): string
    {
        // The locale needs no check — Locale rejects anything that is not a
        // language tag at construction, so one cannot reach this point.
        ContentKeyRules::assertSafe($key);

        return $this->contentDir . '/' . $key->type . '/' . $key->locale->code . '/' . $key->slug . '.json';
    }

    /**
     * Where this document would have lived before languages, or null if it
     * could never have lived there.
     *
     * Only the default locale has a legacy location: a German document has no
     * claim on `content/page/home.json`, and letting it read one would serve
     * English prose as though it were the translation.
     */
    private function legacyPathFor(ContentKey $key): ?string
    {
        if (!$key->locale->equals($this->defaultLocale)) {
            return null;
        }

        return $this->contentDir . '/' . $key->type . '/' . $key->slug . '.json';
    }

    private function removeLegacyFile(ContentKey $key): void
    {
        $legacy = $this->legacyPathFor($key);

        if ($legacy !== null && is_file($legacy)) {
            @unlink($legacy);
        }
    }

    /**
     * Subdirectories of a type directory that name a language.
     *
     * @return array<string, string> locale code => directory path
     */
    private function localeDirectories(string $typeDir): array
    {
        $out = [];

        foreach (glob($typeDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $locale = Locale::tryFromString(basename($dir));

            // A directory that is not a language tag is not ours. Media
            // variants and anything a plugin has put here stay out of listings
            // rather than being reported as a language nobody configured.
            if ($locale !== null) {
                $out[$locale->code] = $dir;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function jsonFiles(string $dir): array
    {
        $files = glob($dir . '/*.json') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    private function isSafeKey(ContentKey $key): bool
    {
        return ContentKeyRules::isSafe($key);
    }
}
