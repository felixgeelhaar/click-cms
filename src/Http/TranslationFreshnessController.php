<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Translation\TranslationFreshnessService;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/** Authenticated handlers for saved translation review metadata. */
final class TranslationFreshnessController
{
    public function __construct(private readonly PageService $pages, private readonly TranslationFreshnessService $freshness) {}

    /** @param array<string, mixed> $query @param array<string, mixed> $user @return array<string, mixed> */
    public function read(array $query, array $user): array
    {
        if ($user === []) return ['status' => 401, 'error' => 'Not authenticated.'];
        if (!$this->role($user)->can(Capability::ViewContent)) return ['status' => 403, 'error' => 'You cannot view translation status.'];

        $identity = $this->identity($query['slug'] ?? null, $query['sourceLocale'] ?? null, $query['targetLocale'] ?? null);
        if (isset($identity['error'])) return $identity['error'];

        return $this->freshness->status($identity['slug'], $identity['sourceLocale'], $identity['targetLocale']);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $user @return array<string, mixed> */
    public function mark(array $input, array $user): array
    {
        if ($user === []) return ['status' => 401, 'error' => 'Not authenticated.'];
        $role = $this->role($user);
        if (!$role->can(Capability::ViewContent)) return ['status' => 403, 'error' => 'You cannot view translation status.'];

        $identity = $this->identity($input['slug'] ?? null, $input['sourceLocale'] ?? null, $input['targetLocale'] ?? null);
        if (isset($identity['error'])) return $identity['error'];

        $target = $this->pages->find($identity['slug'], $identity['targetLocale']);
        if ($target === null) return ['status' => 404, 'error' => 'Both saved language versions must exist.'];
        $username = is_string($user['username'] ?? null) ? $user['username'] : null;
        $owner = is_string($target->data['owner'] ?? null) ? $target->data['owner'] : null;
        if (!$role->canEditContentOwnedBy($owner, $username)) {
            return ['status' => 403, 'error' => 'You cannot mark this target translation as reviewed.'];
        }

        $sourceRevision = $input['sourceRevision'] ?? null;
        $targetRevision = $input['targetRevision'] ?? null;
        $expectedReviewedAt = $input['expectedReviewedAt'] ?? null;
        if (!is_string($sourceRevision) || !is_string($targetRevision)) {
            return ['status' => 400, 'error' => 'Saved source and target revisions are required.'];
        }
        if (!array_key_exists('expectedReviewedAt', $input)
            || ($expectedReviewedAt !== null && !is_string($expectedReviewedAt))) {
            return ['status' => 400, 'error' => 'The observed review marker version is required.'];
        }

        return $this->freshness->mark(
            $identity['slug'], $identity['sourceLocale'], $identity['targetLocale'],
            $sourceRevision, $targetRevision, $username ?? '', $expectedReviewedAt,
        );
    }

    /** @return array{slug:string,sourceLocale:string,targetLocale:string}|array{error:array<string,mixed>} */
    private function identity(mixed $slug, mixed $sourceLocale, mixed $targetLocale): array
    {
        if (!is_string($slug) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D', $slug) !== 1
            || !is_string($sourceLocale) || !is_string($targetLocale)) {
            return ['error' => ['status' => 400, 'error' => 'A valid page slug and source/target languages are required.']];
        }
        $source = $this->pages->parseLocale($sourceLocale);
        $target = $this->pages->parseLocale($targetLocale);
        if ($source['error'] !== null || $target['error'] !== null) {
            return ['error' => ['status' => 400, 'error' => $source['error'] ?? $target['error']]];
        }
        $sourceCode = $source['locale']?->code;
        $targetCode = $target['locale']?->code;
        if ($sourceCode === null || $targetCode === null || $sourceCode === $targetCode) {
            return ['error' => ['status' => 400, 'error' => 'Choose different source and target languages.']];
        }

        return ['slug' => $slug, 'sourceLocale' => $sourceCode, 'targetLocale' => $targetCode];
    }

    /** @param array<string, mixed> $user */
    private function role(array $user): Role
    {
        return Role::fromName(is_string($user['role'] ?? null) ? $user['role'] : null);
    }
}
