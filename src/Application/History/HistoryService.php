<?php

declare(strict_types=1);

namespace Click\Cms\Application\History;

use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\History\VersionedStorage;
use Click\Cms\Domain\History\VersionStoreInterface;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Looking at a document's history, and putting an earlier state back.
 *
 * The permission checks live here rather than only at the router, because this
 * is the layer that knows who owns the document — the router can ask "may this
 * role restore anything at all", but only here can it be asked whether they may
 * restore *this*.
 */
final class HistoryService
{
    public function __construct(
        private readonly VersionedStorage $storage,
        private readonly VersionStoreInterface $versions,
    ) {}

    /**
     * Every retained version of a document, newest first.
     *
     * @param array<string, mixed> $user
     * @return array{versions: ?list<array<string, mixed>>, error: ?string, status: int}
     */
    public function all(ContentKey $key, array $user): array
    {
        $role = Role::fromName($user['role'] ?? null);

        // History shows unpublished drafts, so seeing it needs at least the
        // right to see content at all.
        if (!$role->can(Capability::ViewContent)) {
            return $this->failure('You do not have permission to view this history.', 403);
        }

        $versions = array_map(
            static fn (Version $version): array => $version->summary(),
            $this->versions->all($key)
        );

        return ['versions' => $versions, 'error' => null, 'status' => 200];
    }

    /**
     * One version in full, so it can be read or compared before restoring.
     *
     * @param array<string, mixed> $user
     * @return array{version: ?Version, error: ?string, status: int}
     */
    public function get(ContentKey $key, string $versionId, array $user): array
    {
        $role = Role::fromName($user['role'] ?? null);

        if (!$role->can(Capability::ViewContent)) {
            return ['version' => null, 'error' => 'You do not have permission to view this history.', 'status' => 403];
        }

        $version = $this->versions->find($key, $versionId);

        return $version === null
            ? ['version' => null, 'error' => 'Version not found.', 'status' => 404]
            : ['version' => $version, 'error' => null, 'status' => 200];
    }

    /**
     * Put an earlier version back.
     *
     * Restoring writes forward rather than rewinding: the earlier state is
     * saved as the newest one, which retains a version of it in turn. Nothing
     * is unwound and nothing is discarded, so a restore of the wrong version is
     * itself undoable — the property that makes this safe to offer to an editor
     * who is already having a bad afternoon.
     *
     * @param array<string, mixed> $user
     * @return array{version: ?Version, error: ?string, status: int}
     */
    public function restore(ContentKey $key, string $versionId, array $user): array
    {
        $version = $this->versions->find($key, $versionId);
        if ($version === null) {
            return ['version' => null, 'error' => 'Version not found.', 'status' => 404];
        }

        $current = $this->storage->find($key);

        // Ownership comes from the document as it stands, falling back to the
        // version's own record of it. The fallback is what lets a deleted page
        // still be restored: there is no current document left to ask.
        $owner = $this->ownerOf($current?->data ?? []) ?? $version->owner();

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canRestoreContentOwnedBy($owner, $this->usernameOf($user))) {
            return [
                'version' => null,
                'error' => 'You do not have permission to restore this page.',
                'status' => 403,
            ];
        }

        // Belt and braces against a state that never passed through versioning
        // — content seeded directly onto disk, or written by a backend that was
        // swapped in without the decorator. Without this, restoring over such a
        // document would be the one way this feature could still lose work.
        if ($current !== null && !$this->isRetained($key, $current->toArray())) {
            $this->versions->record($current, $this->usernameOf($user), Version::REASON_SAVE);
        }

        $restored = $version->content();

        // An empty update, purely for its effect on the timestamp: the document
        // was changed now, even though its content is old, and a listing sorted
        // by "last edited" would otherwise bury it.
        $restored->update([]);

        $this->storage->saveWithReason($restored, Version::REASON_RESTORE);

        return ['version' => $version, 'error' => null, 'status' => 200];
    }

    /**
     * Whether this exact state is already recoverable.
     *
     * @param array<string, mixed> $document
     */
    private function isRetained(ContentKey $key, array $document): bool
    {
        $newest = $this->versions->all($key)[0] ?? null;

        // Timestamps differ on every write, so they are excluded: what matters
        // is whether the editor's work is recoverable, not whether the two
        // records were written at the same instant.
        return $newest !== null
            && ($newest->document['data'] ?? null) === ($document['data'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ownerOf(array $data): ?string
    {
        $owner = $data['owner'] ?? null;

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function usernameOf(array $user): ?string
    {
        $username = $user['username'] ?? null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * @return array{versions: ?list<array<string, mixed>>, error: ?string, status: int}
     */
    private function failure(string $message, int $status): array
    {
        return ['versions' => null, 'error' => $message, 'status' => $status];
    }
}
