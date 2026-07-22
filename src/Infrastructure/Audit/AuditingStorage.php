<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Audit;

use Click\Cms\Domain\Audit\AuditAction;
use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\Audit\AuditLogInterface;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Closure;
use DateTimeImmutable;

/**
 * Any storage backend, plus a record of who changed what and when.
 *
 * A decorator, and the outermost one, mirroring {@see VersioningStorage} for the
 * same reason history is a decorator: recording who wrote a document is not a
 * property of one backend, and building it into the flat-file store would mean a
 * site that moved to SQLite silently lost its audit trail. Wrapping gives every
 * backend the same accountability from one place.
 *
 * It wraps a {@see PublishingStorage} rather than a bare {@see
 * \Click\Cms\Domain\Storage\StorageInterface} on purpose. Draft-and-publish put
 * three of the events most worth recording — publish, unpublish and restore —
 * on the publishing surface, not the plain one, and those are precisely the
 * actions the order of work names as the reason an audit trail matters now: a
 * restore can replace a working copy, a publish changes what the public sees.
 * Sitting outside the versioning decorator and speaking its full vocabulary is
 * what lets all of them be caught here, at the one place the whole application
 * gets its storage from, without a handler having to remember to record.
 *
 * Only writes are recorded. Reads pass straight through — a `find()` is not an
 * event, and auditing every page view would bury the writes that matter under a
 * flood the trail was never meant to hold.
 *
 * The author is a callback, not a value, for the same reason it is on the
 * versioning decorator: a storage backend is built once at boot and serves many
 * requests, so who is writing is unknown at construction. It stays optional — a
 * write from the command line or a plugin with no session records no actor,
 * which is honest, rather than being pinned on whoever the resolver last named.
 *
 * The clock is injected too. The domain may not read `time()`, so the moment an
 * entry is stamped with is decided here, in infrastructure, and supplied to the
 * entry. A test can hand in a fixed clock; production takes the default.
 */
final class AuditingStorage implements PublishingStorage
{
    /** @var (Closure(): ?string)|null */
    private readonly ?Closure $author;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param ?callable(): ?string          $author Resolves whoever is writing, if anyone is.
     * @param ?callable(): DateTimeImmutable $clock  Supplies the moment; defaults to now.
     */
    public function __construct(
        private readonly PublishingStorage $inner,
        private readonly AuditLogInterface $log,
        ?callable $author = null,
        ?callable $clock = null,
    ) {
        $this->author = $author === null ? null : Closure::fromCallable($author);
        $this->clock = $clock === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable('now')
            : Closure::fromCallable($clock);
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
        $this->saveWithReason($content, Version::REASON_SAVE);
    }

    public function saveWithReason(Content $content, string $reason): void
    {
        // Whether this is a creation or an edit is decided from the working copy
        // as it stands *before* the write — a live document, an unpublished
        // draft, or nothing at all. Asking after the save would always find a
        // document and read every write as an update. `draft()` is the right
        // question because a publishable type never reaches `content/` on save,
        // so `exists()` would call the first edit of a new page a creation and
        // the second one a creation too.
        $action = $reason === Version::REASON_RESTORE
            ? AuditAction::Restored
            : ($this->inner->draft($content->key) === null ? AuditAction::Created : AuditAction::Updated);

        // The write goes first: the editor's work reaching disk is the thing
        // that must not be at the mercy of the recording, exactly as the
        // versioning decorator writes the document before it records the
        // version. The entry is written after, and a failure to write it is
        // allowed to propagate — see the class note on why the trail surfaces
        // its failures rather than swallowing them. The consequence is stated
        // plainly: a save whose content persisted but whose entry could not be
        // written returns an error, so an operator learns the trail is broken
        // rather than trusting a safety net that has quietly stopped recording.
        $this->inner->saveWithReason($content, $reason);

        $this->record($action, $content->key);
    }

    public function delete(ContentKey $key): bool
    {
        $removed = $this->inner->delete($key);

        // Only a delete that removed something is an event. Recording a delete
        // of a document that was never there would put a phantom into the trail
        // — an actor blamed for removing what did not exist.
        if ($removed) {
            $this->record(AuditAction::Deleted, $key);
        }

        return $removed;
    }

    public function publish(ContentKey $key): ?Content
    {
        $published = $this->inner->publish($key);

        // Null means there was nothing to promote, so nothing happened to
        // record. A non-null result is the moment private work became public,
        // which is one of the events this trail exists for.
        if ($published !== null) {
            $this->record(AuditAction::Published, $key);
        }

        return $published;
    }

    public function unpublish(ContentKey $key): bool
    {
        $taken = $this->inner->unpublish($key);

        if ($taken) {
            $this->record(AuditAction::Unpublished, $key);
        }

        return $taken;
    }

    private function record(AuditAction $action, ContentKey $key): void
    {
        $this->log->append(
            AuditEntry::of($this->author(), $action, $key, ($this->clock)())
        );
    }

    private function author(): ?string
    {
        return $this->author === null ? null : ($this->author)();
    }
}
