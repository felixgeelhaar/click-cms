<?php

declare(strict_types=1);

namespace Click\Cms\Application\History;

use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\Publishing\PublishingStorage;
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
        private readonly PublishingStorage $storage,
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

        // The working copy, not the live page. A restore replaces what the
        // editor is working on and leaves publication alone — putting an
        // earlier version straight in front of the public would make undo the
        // one editing action with no review step, which is the reverse of what
        // a safety net should be.
        $current = $this->storage->draft($key);

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
        // swapped in without the decorator.
        //
        // The danger this guards against changed shape with draft-and-publish
        // and is worth restating rather than leaving the old reasoning here. A
        // restore no longer writes over the live document, so it cannot destroy
        // it directly. What it does is make the restored state the working copy,
        // and the next publish overwrites the live document with it — at which
        // point a live state that appears nowhere in history is gone with no way
        // back. Recording it first is the difference between an undo and a
        // delayed loss.
        //
        // Both copies are checked, because either can be the unretained one: the
        // working copy when nothing was ever versioned, the live document when
        // something wrote past the decorator.
        foreach ([$current, $this->storage->find($key)] as $atRisk) {
            if ($atRisk !== null && !$this->isRetained($key, $atRisk->toArray())) {
                $this->versions->record($atRisk, $this->usernameOf($user), Version::REASON_SAVE);
            }
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
        // The whole chain rather than only its newest entry. Publishing records
        // a version that duplicates the working copy, so the live document is
        // routinely retained one or two entries back rather than at the front,
        // and checking only the front would record a redundant copy on every
        // restore of a published page.
        foreach ($this->versions->all($key) as $version) {
            // Timestamps differ on every write, so they are excluded: what
            // matters is whether the editor's work is recoverable, not whether
            // the two records were written at the same instant.
            if (($version->document['data'] ?? null) === ($document['data'] ?? null)) {
                return true;
            }
        }

        return false;
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
