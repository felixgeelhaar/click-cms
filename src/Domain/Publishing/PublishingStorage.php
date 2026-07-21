<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\VersionedStorage;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Storage where saving a publishable document does not make it public.
 *
 * The inherited {@see \Click\Cms\Domain\Storage\StorageInterface} methods keep
 * meaning exactly what they always did — `find()` and `findByType()` read the
 * live document — because that is the public read path, it is a single file
 * read, and shared hosting is the reason it must stay one. What changes is
 * `save()`: for a type {@see Publishable} names, it records a version and
 * writes nothing to `content/`.
 *
 * The additions here are the other half of that bargain. An editor needs to
 * reach the copy they are working on, and something has to be able to promote
 * it, and neither is expressible through a port whose whole vocabulary is "the
 * document at this key".
 */
interface PublishingStorage extends VersionedStorage
{
    /**
     * The copy being worked on: the newest version, or the live document when
     * there is no version chain at all.
     *
     * Null once the working copy has been deleted, even though the versions
     * that led to it are still retained — history is what a deleted page can be
     * recovered from, not somewhere it goes on quietly existing.
     */
    public function draft(ContentKey $key): ?Content;

    /**
     * Every document of a type as it is being worked on, live or not.
     *
     * What a management listing wants. `findByType()` answers the reader's
     * question instead, and an editor given that list cannot see the page they
     * created this morning and have not published.
     *
     * @return list<Content>
     */
    public function workingCopies(string $type, ?Locale $locale = null): array;

    /**
     * Promote the working copy into `content/`.
     *
     * @return ?Content What went live, or null when there was nothing to promote.
     */
    public function publish(ContentKey $key): ?Content;

    /**
     * Remove the live document, leaving every version standing.
     *
     * @return bool True when something was actually taken down.
     */
    public function unpublish(ContentKey $key): bool;

    public function publicationOf(ContentKey $key): PublicationState;
}
