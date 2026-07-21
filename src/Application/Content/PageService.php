<?php

declare(strict_types=1);

namespace Click\Cms\Application\Content;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\Schema\SectionTypeRepository;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Managing pages: creating, editing, deleting, and who may do which.
 *
 * Core rather than part of a delivery plugin. Editing a page is management, and
 * the admin UI cannot work without it; reading published pages is delivery, and
 * a site that renders its own pages needs no API for that at all. Keeping both
 * in one plugin meant disabling delivery also disabled editing.
 */
final class PageService
{
    public function __construct(
        private readonly ContentService $content,
        private readonly SectionTypeRepository $sectionTypes,
        private readonly SectionValidator $validator = new SectionValidator(),
    ) {}

    /**
     * @return list<Content>
     */
    public function all(): array
    {
        return $this->content->pages();
    }

    public function find(string $slug): ?Content
    {
        return $this->content->page($slug);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function create(array $data, array $user): array
    {
        if (!isset($data['title']) && !isset($data['content']) && !isset($data['sections'])) {
            return $this->failure('A title, content or sections are required.', 400);
        }

        $slug = $this->slugify((string) ($data['slug'] ?? '')) ?: $this->slugify((string) ($data['title'] ?? ''));
        if ($slug === '') {
            $slug = 'untitled';
        }

        if ($this->content->page($slug) !== null) {
            return $this->failure('A page with that address already exists.', 409);
        }

        $sections = $this->validateSections($data);
        if ($sections['errors'] !== []) {
            return $this->failure('Some sections are invalid.', 422, $sections['errors']);
        }
        $data = $sections['data'];

        // Recorded so per-author permissions have something to check against.
        $data['owner'] ??= $user['username'] ?? 'unknown';

        $page = Content::create(ContentKey::page($slug), $data);
        $this->content->save($page);

        return ['page' => $page, 'error' => null, 'status' => 201, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function update(string $slug, array $data, array $user): array
    {
        $page = $this->content->page($slug);
        if ($page === null) {
            return $this->failure('Page not found.', 404);
        }

        $permission = $this->canModify($page->data, $user);
        if ($permission !== true) {
            return $this->failure($permission, 403);
        }

        $sections = $this->validateSections($data);
        if ($sections['errors'] !== []) {
            return $this->failure('Some sections are invalid.', 422, $sections['errors']);
        }
        $data = $sections['data'];

        // The address identifies the page; changing it here would silently
        // orphan every link to it.
        unset($data['slug']);

        $page->update($data);
        $this->content->save($page);

        return ['page' => $page, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function delete(string $slug, array $user): array
    {
        $page = $this->content->page($slug);
        if ($page === null) {
            return $this->failure('Page not found.', 404);
        }

        $permission = $this->canDelete($page->data, $user);
        if ($permission !== true) {
            return $this->failure($permission, 403);
        }

        $this->content->delete(ContentKey::page($slug));

        return ['page' => null, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * Validate a payload's sections against their declared types.
     *
     * Anything the schema does not declare is discarded here rather than
     * stored, so content can only ever hold a shape the site's templates were
     * written for.
     *
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    private function validateSections(array $data): array
    {
        if (!array_key_exists('sections', $data)) {
            return ['data' => $data, 'errors' => []];
        }

        $sections = $data['sections'];
        if (!is_array($sections) || !array_is_list($sections)) {
            return ['data' => $data, 'errors' => ['sections' => 'Sections must be a list.']];
        }

        $errors = [];
        $clean = [];

        foreach ($sections as $index => $section) {
            if (!is_array($section) || !isset($section['type']) || !is_string($section['type'])) {
                $errors["{$index}.type"] = 'Section is missing a type.';
                continue;
            }

            $type = $this->sectionTypes->find($section['type']);
            if ($type === null) {
                $errors["{$index}.type"] = "Unknown section type \"{$section['type']}\".";
                continue;
            }

            $values = $section['values'] ?? [];
            if (!is_array($values)) {
                $errors["{$index}.values"] = 'Section values must be an object.';
                continue;
            }

            $result = $this->validator->validate($type, $values);

            if (!$result->isValid()) {
                foreach ($result->errors as $field => $message) {
                    $errors["{$index}.{$field}"] = $message;
                }
                continue;
            }

            $clean[] = ['type' => $type->id, 'values' => $result->values];
        }

        $data['sections'] = $clean;

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $pageData
     * @return true|string True, or the reason why not.
     */
    public function canModify(array $pageData, array $user): bool|string
    {
        $role = Role::fromName($user['role'] ?? null);

        if ($role->canEditContentOwnedBy($pageData['owner'] ?? null, $user['username'] ?? null)) {
            return true;
        }

        return 'You do not have permission to edit this page.';
    }

    /**
     * @param array<string, mixed> $pageData
     * @return true|string
     */
    public function canDelete(array $pageData, array $user): bool|string
    {
        // Deleting cannot be partially undone, so the role map holds it to a
        // stricter rule than editing.
        $role = Role::fromName($user['role'] ?? null);

        if ($role->canDeleteContentOwnedBy($pageData['owner'] ?? null, $user['username'] ?? null)) {
            return true;
        }

        return 'You do not have permission to delete this page.';
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = preg_replace('/-{2,}/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * @param array<string, string> $errors
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    private function failure(string $message, int $status, array $errors = []): array
    {
        return ['page' => null, 'error' => $message, 'status' => $status, 'errors' => $errors];
    }
}
