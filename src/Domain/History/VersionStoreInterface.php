<?php

declare(strict_types=1);

namespace Click\Cms\Domain\History;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;

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
}
