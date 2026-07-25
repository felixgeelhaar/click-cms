<?php

declare(strict_types=1);

namespace Click\Cms\Application\Cache;

/**
 * Rendered public pages, kept as flat files so a flat-file site stays fast.
 *
 * Serving a page today costs a content read, a menu read, a settings read, a
 * theme manifest read, a section-type read and a media lookup per image. None of
 * that changes between two visitors asking for the same page in the same
 * language, so under any real load the site spends its time re-deriving a string
 * it already had. This stores that string. Deliberately as files: the whole point
 * of this CMS is that it needs nothing but PHP and a directory, and a cache that
 * required Redis to be fast would take that away.
 *
 * ## The dangerous part is not the storage, it is the key and the invalidation
 *
 * A cache that serves the wrong page is worse than no cache at all — it is a
 * correctness bug that survives a redeploy and that nobody can reproduce, because
 * the wrong answer is only wrong for the people who are not looking. Two rules
 * follow, and everything below exists to enforce them.
 *
 * **Nothing that is not the public, anonymous page may ever be stored.** See
 * {@see isCacheable()}. A preview shows unpublished work and carries a banner
 * naming the editor's draft state; writing one into a cache the public reads is
 * a disclosure, not a performance regression. A signed-in visitor's page can
 * differ too, so it is treated the same way.
 *
 * **The key must name everything the output depends on**, and everything else
 * must invalidate. See {@see keyFor()} for the first and the invalidation note
 * on {@see flush()} for the second.
 *
 * Nothing here throws. A cache is an optimisation; the moment it can take a site
 * down by failing, it has cost more than it saves. A write that cannot happen is
 * a miss next time, and a read that cannot happen is a render.
 */
final class RenderCache
{
    /**
     * Entries are `<64 hex>.html`, and the in-flight temporary files that
     * {@see put()} renames from are `<64 hex>.html.<12 hex>.tmp`. Matching on the
     * shape rather than on `*` is what makes {@see flush()} safe to point at a
     * directory: a mis-configured cache path deletes nothing it did not write.
     */
    private const ENTRY_PATTERN = '/^[0-9a-f]{64}\.html(?:\.[0-9a-f]{12}\.tmp)?$/D';

    /** A key is a bare sha-256 hex digest and nothing else. */
    private const KEY_PATTERN = '/^[0-9a-f]{64}$/D';

    /**
     * @param string $directory Where entries live — one flat directory, expected
     *        to be `<base>/data/cache/render`. Under `data/` because that is the
     *        writable directory that survives a redeploy, and under `cache/`
     *        because everything in it is derived and safe to delete by hand.
     * @param bool   $enabled   Off is a first-class state, not a degraded one:
     *        a site debugging a rendering problem, or a plugin author whose hook
     *        output changes on every request, must be able to turn this off and
     *        get a genuinely uncached site rather than a stale one.
     */
    public function __construct(
        private readonly string $directory,
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /* --------------------------------------------------------- eligibility -- */

    /**
     * Whether this particular render may be stored and served to anyone else.
     *
     * The two exclusions are not tuning knobs, they are the correctness of the
     * feature:
     *
     * - **A preview is never cacheable.** It renders unpublished work, it is
     *   reached with a signed link or a session, and it carries a banner that
     *   depends on the page's publication state at that instant. Store one and
     *   the next anonymous visitor to that slug is served somebody's draft.
     * - **A signed-in render is never cacheable.** The public shell is the same
     *   today, but a `web.render` plugin is handed the request and may add an
     *   edit affordance or anything else keyed to the person looking. A cache
     *   that a logged-in request can write to is a cache the public reads out of,
     *   which is the classic way a shared cache gets poisoned.
     *
     * Callers should treat this as the gate on both sides — do not read a cached
     * page for a preview either. Reading is less dangerous than writing, but a
     * preview that showed the last published render would silently tell an editor
     * their unsaved work looks fine.
     *
     * ### The limit of this check, stated plainly
     *
     * It knows about the two variations the CMS itself creates. It cannot know
     * that a `web.render` plugin varied its output on the query string, a cookie,
     * or the time of day — none of which are in the key. A site running such a
     * plugin must turn the cache off; there is no way for this class to detect it,
     * and pretending otherwise would be the more dangerous design.
     */
    public function isCacheable(bool $preview, bool $authenticated): bool
    {
        return $this->enabled && !$preview && !$authenticated;
    }

    /* ----------------------------------------------------------------- key -- */

    /**
     * The cache key for one rendered page.
     *
     * What is in it is exactly what changes the bytes of the document for an
     * anonymous visitor:
     *
     * - **slug** — which page. Obvious, and alone it is not enough.
     * - **locale** — the language *actually served*, not the one requested. When
     *   German falls back to English the document says `lang="en"` and contains
     *   English prose, and the header's hrefs are built from the served locale
     *   too. Keying on the served locale is therefore both correct and the
     *   version that hits: every URL that falls back to English shares one entry.
     * - **theme id** — a theme switch changes the stylesheet link in every
     *   document on the site. Leave it out and activating a theme serves the old
     *   design from cache until something else happens to invalidate, which is
     *   the bug that makes people distrust caches.
     * - **theme version** — the cache-busting token in the stylesheet URL, which
     *   is the stylesheet's mtime. This is the one that is easy to skip and worth
     *   keeping: a designer editing their CSS in place never bumps a version and
     *   never activates anything, so nothing would invalidate, yet every cached
     *   document now links a `?v=` that is out of date. Including it means that
     *   edit heals the cache by itself.
     *
     * Everything else the document depends on — the main menu, the site name, the
     * page's own content, section type definitions, which plugins are on — is
     * *not* in the key, because it is not per-page and there is nothing sensible
     * to key it by. Those are handled by invalidation instead; see {@see flush()}.
     *
     * The result is a digest, never a path built from the inputs. A slug is user
     * input and reaches this method from a URL; concatenating it into a filename
     * is how a cache turns into an arbitrary-file-write. The separator is a NUL
     * so that no combination of inputs can impersonate another — without it,
     * slug `a` + locale `b-c` and slug `a-b` + locale `c` would be one entry.
     */
    public function keyFor(
        string $slug,
        string $locale,
        string $themeId,
        string $themeVersion = '',
    ): string {
        return hash('sha256', implode("\0", [$slug, $locale, $themeId, $themeVersion]));
    }

    /* -------------------------------------------------------------- lookup -- */

    /**
     * The stored document, or null for anything at all that is not one.
     *
     * A miss and a broken entry are the same answer on purpose: the caller's only
     * possible response to either is to render the page, so distinguishing them
     * would buy nothing and add a failure mode. Empty counts as broken — an empty
     * document is never a legitimate render, so it can only be a truncated or
     * emptied file, and returning it would blank the page.
     */
    public function get(string $key): ?string
    {
        if (!$this->enabled || !$this->isValidKey($key)) {
            return null;
        }

        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        $html = @file_get_contents($path);

        return is_string($html) && $html !== '' ? $html : null;
    }

    /**
     * Store a rendered document, atomically.
     *
     * Write-then-rename, as content, settings and the theme choice all do:
     * `rename()` is atomic within a filesystem, so a request reading this entry
     * while another writes it sees the whole old document or the whole new one.
     * Without it, the first visitor after a publish would occasionally receive
     * half a page — a failure that is intermittent, load-dependent and very hard
     * to attribute to a cache.
     *
     * Refuses an empty document. A render that produced nothing is a bug
     * somewhere upstream, and caching it would turn one broken response into
     * every response until the next publish.
     *
     * Silent on failure by design: a read-only or full disk should make a site
     * slow, not down. The key check is not a formality either — it is what
     * guarantees this method cannot be talked into writing outside its directory,
     * whatever a caller passes.
     */
    public function put(string $key, string $html): void
    {
        if (!$this->enabled || !$this->isValidKey($key) || $html === '') {
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return;
        }

        $path = $this->pathFor($key);
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $html, LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /* -------------------------------------------------- invalidation (API) -- */

    /**
     * Drop one entry.
     *
     * ### Read this before using it instead of {@see flush()}
     *
     * Per-page invalidation is **not** generally safe here, and the reason is
     * structural rather than a missing feature. Every document contains the site
     * header, and the header contains the main menu and the site name. A page
     * can also render other pages' content — a listing section names them. So
     * "page X changed" does not imply "only X's document changed": publishing a
     * page that sits in the menu changes the header of every other page, and
     * renaming one changes every menu that links it.
     *
     * There is no dependency map from a document back to the things it rendered,
     * and inventing one would be a bigger, more error-prone feature than the
     * cache itself. Until there is one, the honest position is: **wire
     * {@see flush()} to the events below, not this.** A full flush on a publish
     * costs one directory sweep and a cold cache for a moment on a site whose
     * pages are, by construction, cheap to render — versus a stale menu that
     * nobody notices for a week.
     *
     * This exists for the narrow case where the caller can prove the change is
     * confined to one document (and for tests). If you cannot state that proof in
     * one sentence, call {@see flush()}.
     */
    public function forget(string $key): void
    {
        if (!$this->isValidKey($key)) {
            return;
        }

        @unlink($this->pathFor($key));
    }

    /**
     * Empty the cache.
     *
     * ### The events that MUST call this
     *
     * A missed invalidation is the failure mode that makes a cache worse than
     * useless, so the list is written out rather than left to be inferred. Every
     * one of these changes the bytes of at least one public document without
     * changing any key:
     *
     * - **Content** — a page published, unpublished, created, updated/saved,
     *   deleted, or restored from history. Publish and unpublish are the obvious
     *   two; save matters because an already-published page can be edited in
     *   place, and restore matters because it is a write that does not look like
     *   one from the editor's side.
     * - **Menus** — any menu created, edited, reordered or deleted. The main menu
     *   is in the header of *every* page, so a menu change is a site-wide change.
     * - **Settings** — any settings save. The site name is the brand in the
     *   header of every page.
     * - **Themes** — a theme activated. (A theme's own files being edited is
     *   handled by the version component of the key instead; see
     *   {@see keyFor()}.)
     * - **Plugins** — a plugin enabled, disabled, installed, updated or removed.
     *   A `web.render` plugin can replace the entire document, so its presence is
     *   part of the output whether or not it currently does anything.
     * - **Section types** — anything under `config/sections` changing, since a
     *   section definition is how a stored section becomes markup.
     * - **Media** — a media item deleted or replaced. A deleted item's URL
     *   resolves to empty, so a cached page keeps a picture that is now a 404.
     *
     * Redirect rules are deliberately absent: a redirect only fires when a slug
     * resolves to nothing, and a slug that resolves to nothing was never cached.
     *
     * Only entries this class wrote are removed, matched by name shape. The
     * directory itself stays, and so does anything else in it — so a cache path
     * that is wrong, or one day shared, cannot become a delete of a site's files.
     */
    public function flush(): void
    {
        $entries = @scandir($this->directory);
        if ($entries === false) {
            // Nothing has been cached yet. An empty cache is the desired state.
            return;
        }

        foreach ($entries as $entry) {
            if (preg_match(self::ENTRY_PATTERN, $entry) === 1) {
                @unlink($this->directory . '/' . $entry);
            }
        }
    }

    /* -------------------------------------------------------------- paths -- */

    /**
     * The only place a key becomes a path.
     *
     * Every caller of this has already been through {@see isValidKey()}, so what
     * is concatenated here is 64 hex characters and cannot contain a separator,
     * a dot segment or a NUL — whatever arrived from the outside.
     */
    private function pathFor(string $key): string
    {
        return $this->directory . '/' . $key . '.html';
    }

    /**
     * Keys come from {@see keyFor()} and are therefore digests. Anything else is
     * refused rather than sanitised: a `../../public/index.php` that got
     * *cleaned up* into some neighbouring filename is still a bug, and one that
     * is now silent. Refusing turns the whole class of path traversal into a
     * cache miss.
     */
    private function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }
}
