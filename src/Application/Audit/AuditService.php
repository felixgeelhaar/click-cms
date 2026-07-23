<?php

declare(strict_types=1);

namespace Click\Cms\Application\Audit;

use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\Audit\AuditLogInterface;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Reading the audit trail, for an endpoint to expose.
 *
 * Thin on purpose: the trail is append-only and the recording happens at the
 * storage layer, so all that is left for an application service is to answer the
 * two questions an operator asks — the recent history of the whole system, and
 * the history of one document — and to guard who may ask them.
 *
 * The permission check lives here, beside the operation, rather than being left
 * to whichever router happens to call it — the same principle the rest of the
 * system now follows, and the reason authorisation belongs in the application
 * layer and not scattered through transport. Reading the trail is gated on
 * {@see Capability::ManageSettings}: it is the record of everyone's actions, an
 * operator's accountability tool, and a role without the run of the site's
 * settings has no business enumerating every editor's writes. An unrecognised
 * role falls to the least privileged and is refused, which is the fail-closed
 * default the whole identity model holds.
 */
final class AuditService
{
    public function __construct(private readonly AuditLogInterface $log)
    {
    }

    /**
     * The most recent entries across every document, newest first.
     *
     * @param array<string, mixed> $user
     * @return array{entries: ?list<array<string, mixed>>, error: ?string, status: int}
     */
    public function recent(array $user, int $limit = 50): array
    {
        if (!$this->mayView($user)) {
            return $this->refused();
        }

        return $this->present($this->log->recent($limit));
    }

    /**
     * The history of one document, newest first.
     *
     * @param array<string, mixed> $user
     * @return array{entries: ?list<array<string, mixed>>, error: ?string, status: int}
     */
    public function forDocument(ContentKey $key, array $user, int $limit = 50): array
    {
        if (!$this->mayView($user)) {
            return $this->refused();
        }

        return $this->present($this->log->forDocument($key, $limit));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function mayView(array $user): bool
    {
        return Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings);
    }

    /**
     * @param list<AuditEntry> $entries
     * @return array{entries: list<array<string, mixed>>, error: null, status: int}
     */
    private function present(array $entries): array
    {
        return [
            'entries' => array_map(
                static fn (AuditEntry $entry): array => $entry->toArray(),
                $entries
            ),
            'error' => null,
            'status' => 200,
        ];
    }

    /**
     * @return array{entries: null, error: string, status: int}
     */
    private function refused(): array
    {
        return [
            'entries' => null,
            'error' => 'You do not have permission to view the audit trail.',
            'status' => 403,
        ];
    }
}
