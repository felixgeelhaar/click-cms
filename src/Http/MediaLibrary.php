<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Media\MediaItem;
use Closure;

/**
 * Finding and managing media once a library has grown past a screenful.
 *
 * The existing endpoints ({@see CoreApiRoutes::listMedia()} and friends) return
 * the whole library and delete one item at a time. That is fine for a handful of
 * uploads and unworkable for hundreds: there is no way to search, no way to
 * group, and clearing out a batch means one round-trip per file. This adds the
 * three things that close that gap — filename search, folder grouping and bulk
 * deletion — without touching the storage model.
 *
 * It owns no storage of its own. Listing and deletion are delegated to the same
 * {@see MediaService} the rest of the application uses, so a file removed here is
 * removed exactly as it is anywhere else (metadata, original and every variant),
 * and a listing here sees exactly what the single-item endpoints see. This class
 * only adds the reading lens — the query filters and the folder derivation — on
 * top of that one source of truth.
 *
 * Folders are virtual. There are no directories on disk: an item's folder is the
 * path portion of its original filename (everything before the last "/"), or the
 * root when it has none. A file uploaded as "products/hero.jpg" therefore lives
 * in the "products" folder purely by virtue of its stored name, and nothing about
 * the flat, outside-the-document-root storage layout has to change. The original
 * name is already kept for display, so this reuses it rather than inventing a new
 * field that every writer would then have to populate.
 *
 * These are management endpoints reached only by an authenticated admin UI; the
 * kernel's deny-by-default {@see ApiGuard} already turns away anonymous callers.
 * Bulk deletion is guarded a second time here, against the caller's own role,
 * because deleting many files at once is exactly the action a site may want held
 * to a higher bar than viewing them.
 */
final class MediaLibrary
{
    /**
     * @param Closure(): Role $currentRole Resolves the calling account's role,
     *        deferred so the caller is read at request time rather than at
     *        construction. Kept as the real {@see Role} rather than a bare
     *        boolean so authorization is answered by the domain's one capability
     *        map — this class never decides for itself what a role may do.
     */
    public function __construct(
        private readonly MediaService $media,
        private readonly Closure $currentRole,
        /** Where this installation serves media from; see MediaItem::toArray(). */
        private readonly string $mediaBaseUrl = '/api/media/file',
    ) {}

    /**
     * List the library, optionally narrowed by a filename search and a folder.
     *
     * `q` is a case-insensitive substring match against the original filename —
     * the name a human recognises, not the generated id. `folder` restricts the
     * result to one virtual folder ("" for the root), so the two compose: search
     * within a folder by passing both.
     *
     * The `folders` list is computed from the whole library rather than the
     * filtered subset, so a front end can render the full set of folders to pick
     * from no matter what filter is currently applied — narrowing the view must
     * not make the other folders vanish from the chooser.
     *
     * @param array{q?: string, folder?: string, displayWidth?: int|string} $query
     * @return array{data: list<array<string, mixed>>, folders: list<string>, total: int}
     */
    public function list(array $query): array
    {
        $all = $this->media->all();

        $needle = trim((string) ($query['q'] ?? ''));
        // Passing the folder key at all — even empty — means "the root folder",
        // which is a real filter. Its absence means "no folder filter". The two
        // are distinguished so a caller can ask for ungrouped items explicitly.
        $hasFolderFilter = array_key_exists('folder', $query);
        $folder = $hasFolderFilter ? trim((string) $query['folder']) : null;

        // A display width the field will show the image at, forwarded to the
        // domain so its "too small for this slot" verdict is worded once there
        // rather than recomputed per client — the same contract listMedia uses.
        $displayWidth = $this->displayWidth($query['displayWidth'] ?? null);

        // What the caller can actually use. A field picker asks for one kind
        // because the two are not substitutable: an image field renders an
        // `<img>` and a file field a `<video>`. Without it every picker listed
        // the whole library, so a clip appeared in the image chooser as a broken
        // thumbnail and could be selected into a slot that cannot show it.
        //
        // An unrecognised kind filters nothing rather than everything: a
        // mistyped parameter should not present an empty library as the truth.
        $kind = trim((string) ($query['kind'] ?? ''));
        $kind = in_array($kind, ['image', 'video'], true) ? $kind : null;

        $data = [];
        foreach ($all as $item) {
            if ($kind !== null && $item->kind() !== $kind) {
                continue;
            }

            if ($needle !== '' && !$this->matchesName($item, $needle)) {
                continue;
            }

            if ($hasFolderFilter && $this->folderOf($item) !== $folder) {
                continue;
            }

            // Enrich rather than replace: the item's own payload is untouched and
            // the derived folder rides alongside it, so a front end can group
            // without re-deriving the rule this class owns.
            $data[] = ['folder' => $this->folderOf($item)] + $item->toArray($displayWidth, $this->mediaBaseUrl);
        }

        return [
            'data' => $data,
            'folders' => $this->foldersOf($all),
            'total' => count($data),
        ];
    }

    /**
     * Delete several items at once, reporting the fate of each.
     *
     * Guarded by {@see Capability::DeleteAnyMedia}: removing a batch of files is
     * management, and a role that may view or upload media is not thereby allowed
     * to clear it out. A caller without the capability is refused whole — nothing
     * is deleted — rather than silently skipping the files it could not touch,
     * because a partial success there would hide the refusal.
     *
     * Every requested id is reported, deleted or not, so the caller learns which
     * ones were already gone (a stale UID from a list someone else has since
     * changed) instead of being told only a total. Ids are de-duplicated so a
     * repeated id cannot inflate the count, and blanks are dropped.
     *
     * @param list<string> $ids
     * @return array<string, mixed>
     */
    public function bulkDelete(array $ids): array
    {
        if (!($this->currentRole)()->can(Capability::DeleteAnyMedia)) {
            return ['status' => 403, 'error' => 'You do not have permission to delete media.'];
        }

        // De-duplicate while preserving the order asked for, and drop anything
        // empty: a blank id can never match a stored file and would only produce
        // a noise "not found" line.
        $unique = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id !== '' && !in_array($id, $unique, true)) {
                $unique[] = $id;
            }
        }

        if ($unique === []) {
            return ['status' => 400, 'error' => 'No media to delete.'];
        }

        $results = [];
        $deleted = 0;
        foreach ($unique as $id) {
            // MediaService::delete returns false for an id it cannot find, which
            // is precisely the per-item outcome to report rather than to fail on.
            $ok = $this->media->delete($id);
            $results[] = ['id' => $id, 'deleted' => $ok];
            if ($ok) {
                $deleted++;
            }
        }

        return [
            'data' => [
                'requested' => count($unique),
                'deleted' => $deleted,
                'results' => $results,
            ],
        ];
    }

    /**
     * Whether an item's original filename contains the search text.
     *
     * Matched with mb_stripos so the search is case-insensitive and correct for
     * multibyte names — an editor searching "café" should find "Café.jpg".
     */
    private function matchesName(MediaItem $item, string $needle): bool
    {
        return mb_stripos($item->originalName, $needle) !== false;
    }

    /**
     * The virtual folder an item belongs to: the path portion of its original
     * filename, or "" for the root.
     *
     * Purely derived — no directory exists — so it stays in step with the file
     * for free. Backslashes are normalised to forward slashes so a name captured
     * on Windows groups the same way as one from anywhere else, and any leading
     * or trailing slash is trimmed so "/products/" and "products" are one folder
     * rather than three.
     */
    private function folderOf(MediaItem $item): string
    {
        $name = str_replace('\\', '/', $item->originalName);

        $slash = strrpos($name, '/');
        if ($slash === false) {
            return '';
        }

        return trim(substr($name, 0, $slash), '/');
    }

    /**
     * The distinct, sorted set of folders present in the library, root included
     * as "" when anything is ungrouped — the menu a front end offers to filter by.
     *
     * @param list<MediaItem> $items
     * @return list<string>
     */
    private function foldersOf(array $items): array
    {
        $folders = [];
        foreach ($items as $item) {
            $folder = $this->folderOf($item);
            if (!in_array($folder, $folders, true)) {
                $folders[] = $folder;
            }
        }

        sort($folders);

        return $folders;
    }

    /**
     * A positive display width from the query, or null for anything unparseable.
     *
     * A bad query string should fall back to the general quality verdict, never
     * to a wrong one, so a non-positive or non-numeric value is treated as absent.
     */
    private function displayWidth(mixed $raw): ?int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $width = (int) $raw;

        return $width > 0 ? $width : null;
    }
}
