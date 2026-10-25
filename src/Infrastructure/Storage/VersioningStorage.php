<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\ContentRevision;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\History\VersionStoreInterface;
use Click\Cms\Domain\History\SynchronizedVersionStoreInterface;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\Publishing\ConditionalPublishingStorage;
use Click\Cms\Domain\Storage\ConditionalStorage;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Closure;

/**
 * Any storage backend, plus history, plus the line between saving and going
 * live.
 *
 * A decorator rather than versioning built into `JsonStorage`, because history
 * is not a property of one backend: writing it into the flat-file store would
 * mean a site that moved to SQLite silently lost the ability to undo. Wrapping
 * gives both — and any future backend — the same behaviour from one place.
 *
 * Draft-and-publish lands here for the same reason. Once the version chain
 * exists there is nowhere better to keep an edit that is not live yet, and the
 * alternative — a second copy of the document beside the first — would give
 * every backend a new file layout to implement and every reader two places to
 * look. So a save to a {@see Publishable} type records a version and touches
 * `content/` not at all; publishing is what promotes it. Everything else still
 * writes straight through, because an account nobody has published is an
 * account nobody can sign in with.
 *
 * The consequence worth stating: `find()` and `findByType()` still mean exactly
 * what they meant before, the live document and nothing else. That is what
 * keeps the public read path a single file read, which is the constraint shared
 * hosting imposes and the reason this design was chosen over a status column.
 *
 * The author is supplied as a callback rather than a value because a storage
 * backend is constructed once at boot and used for many requests, so who is
 * writing is not known when it is built. It stays optional: a write from the
 * command line or from a plugin with no session records no author, which is
 * honest, rather than attributing the change to whoever happens to be first in
 * the user list.
 */
final class VersioningStorage implements ConditionalPublishingStorage, ConditionalStorage
{
    /** @var (Closure(): ?string)|null */
    private readonly ?Closure $author;

    public function types(): array
    {
        return $this->inner->types();
    }

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
        $this->synchronize($content->key, fn () => $this->saveUnlocked($content, $reason));
    }

    public function saveIfUnchanged(Content $content, string $expectedRevision): bool
    {
        if (!$this->versions instanceof SynchronizedVersionStoreInterface) {
            throw new \RuntimeException('Conditional saves require a synchronized version store.');
        }

        return $this->versions->synchronize($content->key, function () use ($content, $expectedRevision): bool {
            $current = $this->draft($content->key);
            $actualRevision = $current === null ? '' : ContentRevision::of($current);
            if (!hash_equals($actualRevision, $expectedRevision)) {
                return false;
            }

            $this->saveUnlocked($content, Version::REASON_SAVE);
            return true;
        });
    }

    private function saveUnlocked(Content $content, string $reason): void
    {
        // A publishable type does not reach `content/` here at all. Editing a
        // live page used to change the live page in the same breath, which made
        // "unpublished" a statement about intent and gave an editor no way to
        // work on a published page without the public reading the half-finished
        // version. The version chain is where that work now lives.
        if (!Publishable::includes($content->type())) {
            // The document is written first: the editor's work reaching disk is
            // the thing that must not be at the mercy of the history mechanism.
            $this->inner->save($content);
        }

        // A failure to record is deliberately allowed to propagate. A history
        // that quietly stops recording is precisely the silent degradation this
        // codebase keeps being bitten by — the editor would go on trusting a
        // safety net that is no longer there, and only find out on the day they
        // need it. For a publishable type it is now stricter than that: the
        // version *is* the save, so swallowing the failure would discard the
        // edit outright.
        $this->versions->record($content, $this->author(), $reason);
    }

    public function draft(ContentKey $key): ?Content
    {
        $newest = $this->versions->newest($key);

        if ($newest === null) {
            // Nothing was ever written through versioning. Content seeded
            // straight onto disk by an installer, or written by a backend that
            // was swapped in without this decorator, is still somebody's
            // document and the stored copy is the only one there is.
            return $this->inner->find($key);
        }

        // A delete marker means the working copy is gone even though the chain
        // that led to it is retained. History is where a deleted page is
        // recovered from, not somewhere it goes on quietly existing — a deleted
        // page that still answered here would reappear in every listing.
        return $newest->reason === Version::REASON_DELETE ? null : $newest->content();
    }

    public function workingCopies(string $type, ?Locale $locale = null): array
    {
        $out = [];
        $seen = [];

        foreach ($this->versions->keysOfType($type, $locale) as $key) {
            $draft = $this->draft($key);

            if ($draft !== null) {
                $out[] = $draft;
                $seen[$key->toString()] = true;
            }
        }

        // Live documents with no version chain — seeded content again. Without
        // this the first thing an administrator sees after installing is an
        // empty page list beside a site that plainly has pages.
        foreach ($this->inner->findByType($type, $locale) as $live) {
            if (!isset($seen[$live->key->toString()])) {
                $out[] = $live;
            }
        }

        return $out;
    }

    public function publishIfUnchanged(ContentKey $key, string $expectedRevision): Content|false|null
    {
        if (!$this->versions instanceof SynchronizedVersionStoreInterface) {
            throw new \RuntimeException('Conditional publication requires a synchronized version store.');
        }
        return $this->versions->synchronize($key, function () use ($key, $expectedRevision): Content|false|null {
            $draft = $this->draft($key);
            if ($draft === null) return null;
            if (!hash_equals($expectedRevision, ContentRevision::of($draft))) return false;
            return $this->publishUnlocked($key);
        });
    }

    public function publish(ContentKey $key): ?Content
    {
        return $this->synchronize($key, fn () => $this->publishUnlocked($key));
    }

    private function publishUnlocked(ContentKey $key): ?Content
    {
        $draft = $this->draft($key);

        if ($draft === null) {
            return null;
        }

        $this->inner->save($draft);

        // Recorded even though the document did not change, because otherwise
        // nothing anywhere says which of twenty versions the public is reading
        // — and retention would be free to discard it.
        $this->versions->record($draft, $this->author(), Version::REASON_PUBLISH);

        return $draft;
    }

    public function unpublish(ContentKey $key): bool
    {
        return $this->synchronize($key, fn () => $this->inner->delete($key));
    }

    public function delete(ContentKey $key): bool
    {
        return $this->synchronize($key, fn () => $this->deleteUnlocked($key));
    }

    private function deleteUnlocked(ContentKey $key): bool
    {
        // Snapshot before removing, so deleting a page is recoverable too.
        // The working copy may be the only copy of a never-published draft.
        $existing = $this->draft($key);

        if ($existing !== null) {
            $this->versions->record($existing, $this->author(), Version::REASON_DELETE);
        }

        $removed = $this->inner->delete($key);

        return $removed || $existing !== null;
    }

    public function publicationOf(ContentKey $key): PublicationState
    {
        return PublicationState::of(
            $this->inner->find($key),
            $this->versions->newest($key),
            $this->versions->lastPublished($key),
        );
    }

    public function exists(ContentKey $key): bool
    {
        return $this->inner->exists($key);
    }

    private function author(): ?string
    {
        return $this->author === null ? null : ($this->author)();
    }

    private function synchronize(ContentKey $key, callable $operation): mixed
    {
        return $this->versions instanceof SynchronizedVersionStoreInterface
            ? $this->versions->synchronize($key, $operation)
            : $operation();
    }
}
