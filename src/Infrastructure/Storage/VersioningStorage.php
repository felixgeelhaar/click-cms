<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\History\VersionedStorage;
use Click\Cms\Domain\History\VersionStoreInterface;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Closure;

/**
 * Any storage backend, plus history.
 *
 * A decorator rather than versioning built into `JsonStorage`, because history
 * is not a property of one backend: writing it into the flat-file store would
 * mean a site that moved to SQLite silently lost the ability to undo. Wrapping
 * gives both — and any future backend — the same behaviour from one place.
 *
 * The author is supplied as a callback rather than a value because a storage
 * backend is constructed once at boot and used for many requests, so who is
 * writing is not known when it is built. It stays optional: a write from the
 * command line or from a plugin with no session records no author, which is
 * honest, rather than attributing the change to whoever happens to be first in
 * the user list.
 */
final class VersioningStorage implements VersionedStorage
{
    /** @var (Closure(): ?string)|null */
    private readonly ?Closure $author;

    /**
     * @param ?callable(): ?string $author Resolves whoever is writing, if anyone is.
     */
    public function __construct(
        private readonly StorageInterface $inner,
        private readonly VersionStoreInterface $versions,
        ?callable $author = null,
    ) {
        $this->author = $author === null ? null : Closure::fromCallable($author);
    }

    public function find(ContentKey $key): ?Content
    {
        return $this->inner->find($key);
    }

    public function findByType(string $type, ?Locale $locale = null): array
    {
        return $this->inner->findByType($type, $locale);
    }

    public function save(Content $content): void
    {
        $this->saveWithReason($content, Version::REASON_SAVE);
    }

    public function saveWithReason(Content $content, string $reason): void
    {
        // The document is written first: the editor's work reaching disk is the
        // thing that must not be at the mercy of the history mechanism.
        $this->inner->save($content);

        // A failure to record is deliberately allowed to propagate. A history
        // that quietly stops recording is precisely the silent degradation this
        // codebase keeps being bitten by — the editor would go on trusting a
        // safety net that is no longer there, and only find out on the day they
        // need it.
        $this->versions->record($content, $this->author(), $reason);
    }

    public function delete(ContentKey $key): bool
    {
        // Snapshot before removing, so deleting a page is recoverable too.
        // Deletion is the one operation the old code offered no way back from
        // at all, and the version outlives the document precisely so it can be
        // restored afterwards.
        $existing = $this->inner->find($key);

        if ($existing !== null) {
            $this->versions->record($existing, $this->author(), Version::REASON_DELETE);
        }

        return $this->inner->delete($key);
    }

    public function exists(ContentKey $key): bool
    {
        return $this->inner->exists($key);
    }

    private function author(): ?string
    {
        return $this->author === null ? null : ($this->author)();
    }
}
