<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Cache;

use Click\Cms\Application\Cache\RenderCache;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Clears the render cache whenever stored content changes.
 *
 * ## Why this is a decorator and not a call in each handler
 *
 * A render cache is only ever as good as the least attentive invalidation, and
 * "remember to clear the cache" is the instruction that is reliably forgotten —
 * by the handler added next year, by the plugin, by the CLI task. Putting it
 * here, at the one place the whole application gets its storage from, means the
 * question stops being "did this code path remember?" and becomes "did it
 * write?". A write it does not know about is one that bypassed storage
 * entirely, and there is no such path.
 *
 * It sits outside the audit and versioning decorators so it fires after a write
 * has actually succeeded. Clearing a cache for a save that then threw would be
 * harmless but dishonest; clearing before the write lands would leave a window
 * in which a concurrent request repopulates the cache from the old content and
 * the new content never appears.
 *
 * ## Why every write flushes everything
 *
 * Not laziness — structure. Every document on the site embeds the site header,
 * which is built from the main menu and the site name, so publishing any page
 * that appears in the menu changes the header of every *other* page. There is
 * no dependency map from a rendered document back to what went into it, and
 * building one would be a larger and more error-prone feature than the cache
 * it serves. A flat-file cache of a small site is cheap to refill; serving one
 * stale page is not cheap at all.
 *
 * Reads pass straight through untouched. A `find()` is not a change.
 *
 * ## What this cannot see
 *
 * Only things stored as content documents — pages, collection entries, menus,
 * redirects, users, media metadata. Settings, the active theme, the plugin set
 * and the section type definitions live outside storage and are invalidated by
 * their own handlers.
 */
final class CacheInvalidatingStorage implements PublishingStorage
{
    public function __construct(
        private readonly PublishingStorage $inner,
        private readonly RenderCache $cache,
    ) {
    }

    /* -------------------------------------------------------------- reads -- */

    public function find(ContentKey $key): ?Content
    {
        return $this->inner->find($key);
    }

    public function findByType(string $type, ?Locale $locale = null): array
    {
        return $this->inner->findByType($type, $locale);
    }

    public function exists(ContentKey $key): bool
    {
        return $this->inner->exists($key);
    }

    public function draft(ContentKey $key): ?Content
    {
        return $this->inner->draft($key);
    }

    public function workingCopies(string $type, ?Locale $locale = null): array
    {
        return $this->inner->workingCopies($type, $locale);
    }

    public function publicationOf(ContentKey $key): PublicationState
    {
        return $this->inner->publicationOf($key);
    }

    /* ------------------------------------------------------------- writes -- */

    public function save(Content $content): void
    {
        $this->inner->save($content);
        $this->cache->flush();
    }

    public function saveWithReason(Content $content, string $reason): void
    {
        $this->inner->saveWithReason($content, $reason);
        $this->cache->flush();
    }

    public function delete(ContentKey $key): bool
    {
        $deleted = $this->inner->delete($key);

        // Flushed even when nothing was deleted. Distinguishing the two would
        // save one refill and cost a reason to reason about; a delete that
        // found nothing is rare enough that the simpler rule wins.
        $this->cache->flush();

        return $deleted;
    }

    public function publish(ContentKey $key): ?Content
    {
        $published = $this->inner->publish($key);
        $this->cache->flush();

        return $published;
    }

    public function unpublish(ContentKey $key): bool
    {
        $unpublished = $this->inner->unpublish($key);
        $this->cache->flush();

        return $unpublished;
    }
}
