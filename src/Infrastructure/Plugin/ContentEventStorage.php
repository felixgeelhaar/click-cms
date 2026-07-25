<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Plugin;

use Click\Cms\Application\Plugin\ContentGate;
use Click\Cms\Application\Plugin\ContentRefusedException;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Closure;

/**
 * Tells the plugins what happened to a document, and lets them refuse first.
 *
 * ## Why a decorator and not a call in each handler
 *
 * The same argument that made {@see \Click\Cms\Infrastructure\Cache\CacheInvalidatingStorage}
 * a decorator, and it is the stronger case of the two. "Remember to also fire
 * the event" is forgotten by the handler added next year, by the CLI task, by
 * the seeder, by the plugin that writes content of its own — and an extension
 * system whose events fire on some write paths and not others is worse than one
 * with no events at all, because a search index that is usually current is
 * trusted and a missing one is not.
 *
 * Here the question stops being "did this code path remember?" and becomes "did
 * it write?". A write this cannot see is one that bypassed storage entirely, and
 * there is no such path: every save, delete, publish and unpublish in the
 * application — management API, delivery, history restore, backup restore,
 * seeder, plugin — goes through the one stack this sits on top of.
 *
 * It also settles a question no handler can answer alone. `content.saved` must
 * mean *the change is live and everything downstream of it is consistent*, and
 * a handler firing its own event has no way to know whether the render cache has
 * been cleared yet. Sitting outermost, it does.
 *
 * ## Where it sits, and why outermost
 *
 * Outside every other write decorator — outside cache invalidation, which is
 * outside authorization, versioning and audit. Two consequences, both wanted:
 *
 *  - An announcement is made only once the write has been authorised, versioned,
 *    audited, written *and* the stale render cache dropped. A listener that
 *    re-renders the page it was just told about — a static exporter, a cache
 *    warmer — therefore cannot warm from content it has just been told is old.
 *    Announcing from inside the cache decorator would make that a race.
 *  - A veto is asked before the layers beneath it have had their say, so
 *    `content.before_save` can fire for a write that authorization then refuses.
 *    That is why the before-hooks are documented as side-effect-free and why
 *    `content.saved` exists: an intention is not an outcome. The alternative —
 *    asking plugins last — would let one plugin's veto run after another
 *    decorator had already committed part of the write.
 *
 * ## What it costs when no plugin is listening
 *
 * One `array_key_exists` on a memoised map, per write, per hook — no payload
 * built, no actor resolved, no extra storage read, no plugin bootstrap loaded.
 * The listener question is answered from the `hooks` array each `plugin.json`
 * already put in memory at discovery. The two reads that establish whether a
 * save is a creation happen *only* when something is actually listening for it.
 *
 * ## What it cannot see
 *
 * Only content documents. Media *files*, settings, the active theme and the
 * plugin set are not stored through this port, so nothing here fires for them —
 * see `docs/core.md` for what that leaves out and why it was not faked.
 */
final class ContentEventStorage implements PublishingStorage
{
    /** @var Closure(): array<string, mixed> */
    private readonly Closure $actor;

    /**
     * @param ContentGate $gate The contract: what a refusal looks like, and what
     *        a payload is allowed to carry.
     * @param (callable(): array<string, mixed>)|null $actor Who is acting, read
     *        lazily on each write because this stack outlives any one request.
     *        Null is an unattributed write — a CLI task — which is announced with
     *        an empty actor rather than a fabricated one.
     */
    public function __construct(
        private readonly PublishingStorage $inner,
        private readonly ContentGate $gate,
        ?callable $actor = null,
    ) {
        $this->actor = $actor === null
            ? static fn (): array => []
            : Closure::fromCallable($actor);
    }

    /* -------------------------------------------------------------- reads -- */

    public function find(ContentKey $key): ?Content
    {
        return $this->inner->find($key);
    }

    public function types(): array
    {
        return $this->inner->types();
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
        $veto = $this->gate->listensTo(ContentGate::BEFORE_SAVE);
        $announce = $this->gate->listensTo(ContentGate::SAVED);

        if (!$veto && !$announce) {
            $this->inner->saveWithReason($content, $reason);

            return;
        }

        $key = $content->key;
        $user = ($this->actor)();

        // Whether this is a creation costs two reads to establish, so it is
        // established only when somebody asked. A listener that maintains an
        // index needs it: "added" and "changed" are different operations, and
        // working it out from the payload alone is not possible.
        $facts = [
            'created' => $this->inner->draft($key) === null && !$this->inner->exists($key),
            // Why the write happened — a plain save, a history restore, a
            // publish snapshot. A listener that should not react to a restore
            // has no other way to tell.
            'reason' => $reason,
        ];

        if ($veto) {
            $refusal = $this->gate->refusalForSave($key, $user, $facts);
            if ($refusal !== null) {
                throw ContentRefusedException::refused(ContentGate::BEFORE_SAVE, $key, $refusal);
            }
        }

        $this->inner->saveWithReason($content, $reason);

        // After the inner call returns, so a write that threw is never announced.
        if ($announce) {
            $this->gate->announceSaved($key, $user, $facts);
        }
    }

    public function delete(ContentKey $key): bool
    {
        $veto = $this->gate->listensTo(ContentGate::BEFORE_DELETE);
        $announce = $this->gate->listensTo(ContentGate::DELETED);

        if (!$veto && !$announce) {
            return $this->inner->delete($key);
        }

        $user = ($this->actor)();

        if ($veto) {
            $refusal = $this->gate->refusalForDelete($key, $user);
            if ($refusal !== null) {
                throw ContentRefusedException::refused(ContentGate::BEFORE_DELETE, $key, $refusal);
            }
        }

        $deleted = $this->inner->delete($key);

        // Only a delete that removed something is announced. Unlike the cache
        // flush next to it, where over-firing is merely wasteful, telling a
        // listener a document is gone when it was never there would have it
        // drop an index entry, or fire a webhook, for nothing that happened.
        if ($deleted && $announce) {
            $this->gate->announceDeleted($key, $user);
        }

        return $deleted;
    }

    public function publish(ContentKey $key): ?Content
    {
        // No hook here on purpose. `content.published` is fired by
        // {@see \Click\Cms\Application\Plugin\PublishGate} from the service
        // layer, which is also where the veto that precedes it lives and where
        // the acting user is known. Firing it again from storage would deliver
        // every publish to every listener twice.
        return $this->inner->publish($key);
    }

    public function unpublish(ContentKey $key): bool
    {
        $announce = $this->gate->listensTo(ContentGate::UNPUBLISHED);

        if (!$announce) {
            return $this->inner->unpublish($key);
        }

        $user = ($this->actor)();
        $unpublished = $this->inner->unpublish($key);

        // The counterpart to `content.published`, and announced from here rather
        // than beside it because nothing at the service layer announces a
        // takedown at all — an unpublish reachable only through the page API
        // would be invisible to a plugin whenever it came from anywhere else.
        if ($unpublished) {
            $this->gate->announceUnpublished($key, $user);
        }

        return $unpublished;
    }
}
