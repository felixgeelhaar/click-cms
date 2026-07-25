<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Closure;

/**
 * Any storage backend, plus a gate on every mutating operation.
 *
 * A decorator, mirroring {@see VersioningStorage} and
 * {@see \Click\Cms\Infrastructure\Audit\AuditingStorage}, because who may
 * change a document is not a property of one backend: building the check into
 * the flat-file store would leave a site that moved to SQLite with no
 * enforcement at all. Wrapping gives every backend — and any future one — the
 * same gate from one place. It speaks the full {@see PublishingStorage}
 * vocabulary so it can sit anywhere in the decorator stack and so `publish` and
 * `unpublish`, which live only on the publishing surface, can be guarded too.
 *
 * The decision itself is deliberately not here. This class owns *when* a check
 * happens — before every write, delete, publish and unpublish, and never
 * before a read — but not *what* the answer is. The policy is supplied as a
 * callback `fn(string $operation, ContentKey $key): bool`, because the answer
 * depends on things this layer has no business knowing: the session's
 * capabilities, and the fact that some writes are legitimately anonymous. The
 * `form_submission` type is written by visitors who are not signed in, so a
 * blanket "deny unless authenticated" baked in here would break the forms
 * plugin. Keeping the rule in the injected callable is what lets the integrator
 * allow exactly those writes and refuse the rest.
 *
 * A denied operation throws {@see StorageAuthorizationException} rather than
 * returning quietly. A write that policy dropped and a write that succeeded
 * must not look the same to the caller — see that class for why the refusal is
 * surfaced instead of swallowed.
 *
 * Reads pass straight through, ungated. Whether a given actor may *see* a
 * document is an authorization question too, but it is answered on the read
 * path by the caller that has the request context; gating it here would turn
 * every public page view into a policy call and every miss into a 500.
 */
final class AuthorizingStorage implements PublishingStorage
{
    /** The operation names handed to the authorizer. */
    public const OP_WRITE = 'write';
    public const OP_DELETE = 'delete';
    public const OP_PUBLISH = 'publish';
    public const OP_UNPUBLISH = 'unpublish';

    /** @var Closure(string, ContentKey): bool */
    private readonly Closure $authorizer;

    public function types(): array
    {
        return $this->inner->types();
    }

    /**
     * @param callable(string, ContentKey): bool $authorizer Answers whether the
     *        operation is permitted for the key. It is the sole policy: this
     *        class only calls it and passes or throws.
     */
    public function __construct(
        private readonly PublishingStorage $inner,
        callable $authorizer,
    ) {
        $this->authorizer = Closure::fromCallable($authorizer);
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
        // A save and a restore are both 'write': the capability being checked is
        // "may this actor change this document", and the reason a version carries
        // is a history concern, not a distinct permission. The gate is imposed
        // before the inner call so nothing reaches disk on a denial.
        $this->guard(self::OP_WRITE, $content->key);

        $this->inner->saveWithReason($content, $reason);
    }

    public function delete(ContentKey $key): bool
    {
        $this->guard(self::OP_DELETE, $key);

        return $this->inner->delete($key);
    }

    public function publish(ContentKey $key): ?Content
    {
        $this->guard(self::OP_PUBLISH, $key);

        return $this->inner->publish($key);
    }

    public function unpublish(ContentKey $key): bool
    {
        $this->guard(self::OP_UNPUBLISH, $key);

        return $this->inner->unpublish($key);
    }

    /**
     * Ask the injected policy, and throw when it says no.
     *
     * The one place the decision is consulted. It holds nothing back and adds
     * nothing of its own: the authorizer's boolean is the whole answer.
     */
    private function guard(string $operation, ContentKey $key): void
    {
        if (!($this->authorizer)($operation, $key)) {
            throw StorageAuthorizationException::denied($operation, $key);
        }
    }
}
