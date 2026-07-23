<?php

declare(strict_types=1);

namespace Click\Cms\Domain\History;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Persistence port for retained versions.
 *
 * Separate from {@see \Click\Cms\Domain\Storage\StorageInterface} rather than
 * folded into it, because the two have different shapes: content is addressed
 * by key alone and there is exactly one of it, whereas versions are a list per
 * key that is appended to and trimmed. Folding them together would have forced
 * every storage backend to implement retention before it could store anything.
 */
interface VersionStoreInterface
{
    /**
     * Retain this state of the document.
     *
     * @param ?string $author Whoever performed the write, if that is knowable.
     */
    public function record(
        Content $content,
        ?string $author = null,
        string $reason = Version::REASON_SAVE,
    ): Version;

    /**
     * Every retained version of a document, newest first.
     *
     * Versions outlive the document: deleting a page must not destroy the
     * history that would let it be recovered.
     *
     * @return list<Version>
     */
    public function all(ContentKey $key): array;

    public function find(ContentKey $key, string $versionId): ?Version;

    /**
     * The newest retained version, which is the document's working copy.
     *
     * Separate from `all()` because every read of a page's publication state
     * needs it, and asking for the whole chain to look at one end of it turns a
     * listing of fifty pages into a thousand file reads.
     */
    public function newest(ContentKey $key): ?Version;

    /**
     * The newest version recorded by a publish, if the document has ever been
     * published.
     *
     * Two callers need it and neither can work it out for itself: retention has
     * to know which version it must not discard, and a UI has to be able to
     * distinguish a page that was taken down from one that was never live.
     */
    public function lastPublished(ContentKey $key): ?Version;

    /**
     * Every document of a type that has a version chain.
     *
     * A management listing has to include documents that exist only as drafts,
     * and those are in no other index — `content/` is by definition where they
     * are not.
     *
     * @return list<ContentKey>
     */
    public function keysOfType(string $type, ?Locale $locale = null): array;
}
