<?php

declare(strict_types=1);

namespace Click\Cms\Application\Content;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\Schema\SectionTypeRepository;
use Click\Cms\Domain\Content\ResolvedContent;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Managing pages: creating, editing, deleting, and who may do which.
 *
 * Core rather than part of a delivery plugin. Editing a page is management, and
 * the admin UI cannot work without it; reading published pages is delivery, and
 * a site that renders its own pages needs no API for that at all. Keeping both
 * in one plugin meant disabling delivery also disabled editing.
 *
 * Every operation here works on one language at a time, and none of them falls
 * back to another. Falling back is right for a reader and wrong for an editor:
 * an editor who asks to change the German page and is quietly given the English
 * one will overwrite English text believing they are writing German. Reading
 * with fallback is {@see resolve()}, and it is deliberately separate.
 */
final class PageService
{
    /** @var list<Locale> */
    private readonly array $supportedLocales;

    /**
     * @param list<Locale> $supportedLocales Languages this site publishes in.
     *        Empty means no restriction, which is what a caller with no
     *        configuration to consult — a test, a plugin — should get.
     */
    public function __construct(
        private readonly ContentService $content,
        private readonly SectionTypeRepository $sectionTypes,
        private readonly SectionValidator $validator = new SectionValidator(),
        array $supportedLocales = [],
    ) {
        $this->supportedLocales = array_values($supportedLocales);
    }

    /**
     * @return list<Content>
     */
    public function all(string|Locale|null $locale = null): array
    {
        return $this->content->pages($locale);
    }

    /** The page in exactly this language, or nothing. */
    public function find(string $slug, string|Locale|null $locale = null): ?Content
    {
        return $this->content->page($slug, $locale);
    }

    /**
     * The page for a reader: this language if it exists, the default language
     * if it does not, and a record of which was served.
     */
    public function resolve(string $slug, string|Locale|null $locale = null): ?ResolvedContent
    {
        return $this->content->resolvePage($slug, $locale);
    }

    /**
     * Parse a locale supplied by a request.
     *
     * A tag that is not a language, or a language this site does not publish
     * in, is refused rather than accepted: writing to an unconfigured locale
     * creates a document nothing will ever read and a directory nobody meant to
     * make, and the editor is told none of it.
     *
     * @return array{locale: ?Locale, error: ?string}
     */
    public function parseLocale(?string $code): array
    {
        if ($code === null || trim($code) === '') {
            return ['locale' => $this->content->defaultLocale(), 'error' => null];
        }

        $locale = Locale::tryFromString($code);
        if ($locale === null) {
            return ['locale' => null, 'error' => "\"{$code}\" is not a valid language tag."];
        }

        if ($this->supportedLocales !== [] && !$this->supports($locale)) {
            return [
                'locale' => null,
                'error' => "This site does not publish in \"{$locale->code}\".",
            ];
        }

        return ['locale' => $locale, 'error' => null];
    }

    private function supports(Locale $locale): bool
    {
        foreach ($this->supportedLocales as $supported) {
            if ($supported->equals($locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function create(array $data, array $user, string|Locale|null $locale = null): array
    {
        if (!isset($data['title']) && !isset($data['content']) && !isset($data['sections'])) {
            return $this->failure('A title, content or sections are required.', 400);
        }

        // The language may travel in the body as well as the query, because
        // creating a translation is one request and repeating the locale in two
        // places is an invitation to disagree with yourself.
        $locale ??= is_string($data['locale'] ?? null) ? $data['locale'] : null;
        $parsed = $this->parseLocale(is_string($locale) ? $locale : $locale?->code);
        if ($parsed['error'] !== null) {
            return $this->failure($parsed['error'], 400);
        }
        $locale = $parsed['locale'];
        unset($data['locale']);

        $slug = $this->slugify((string) ($data['slug'] ?? '')) ?: $this->slugify((string) ($data['title'] ?? ''));
        if ($slug === '') {
            $slug = 'untitled';
        }

        // Per language: `page/de/home` existing is not a reason to refuse
        // `page/en/home`. Translations share an address by design.
        if ($this->content->page($slug, $locale) !== null) {
            return $this->failure('A page with that address already exists.', 409);
        }

        $sections = $this->validateSections($data);
        if ($sections['errors'] !== []) {
            return $this->failure('Some sections are invalid.', 422, $sections['errors']);
        }
        $data = $sections['data'];

        // Recorded so per-author permissions have something to check against.
        $data['owner'] ??= $user['username'] ?? 'unknown';

        $page = Content::create(ContentKey::page($slug, $locale), $data);
        $this->content->save($page);

        return ['page' => $page, 'error' => null, 'status' => 201, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function update(string $slug, array $data, array $user, string|Locale|null $locale = null): array
    {
        $parsed = $this->parseLocale(is_string($locale) ? $locale : $locale?->code);
        if ($parsed['error'] !== null) {
            return $this->failure($parsed['error'], 400);
        }

        // No fallback on the write path. A 404 here means "that translation
        // does not exist yet"; silently editing the language it would have
        // fallen back to is how English pages get German text written into them.
        $page = $this->content->page($slug, $parsed['locale']);
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

        // The address and the language both identify the page; changing either
        // here would silently orphan every link to it.
        unset($data['slug'], $data['locale']);

        $page->update($data);
        $this->content->save($page);

        return ['page' => $page, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function delete(string $slug, array $user, string|Locale|null $locale = null): array
    {
        $parsed = $this->parseLocale(is_string($locale) ? $locale : $locale?->code);
        if ($parsed['error'] !== null) {
            return $this->failure($parsed['error'], 400);
        }

        $page = $this->content->page($slug, $parsed['locale']);
        if ($page === null) {
            return $this->failure('Page not found.', 404);
        }

        $permission = $this->canDelete($page->data, $user);
        if ($permission !== true) {
            return $this->failure($permission, 403);
        }

        // One translation, not the document in every language. Deleting the
        // German page must leave the English one standing.
        $this->content->delete(ContentKey::page($slug, $parsed['locale']));

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
