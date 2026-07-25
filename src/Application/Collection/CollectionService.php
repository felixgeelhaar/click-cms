<?php

declare(strict_types=1);

namespace Click\Cms\Application\Collection;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Domain\Collection\CollectionTypeRepository;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * The management logic for collection entries — the blog posts, team members or
 * products of a declared {@see CollectionType}.
 *
 * It is deliberately a close cousin of {@see \Click\Cms\Application\Content\PageService}:
 * an entry is an ordinary content document whose type is the collection's id, so
 * it inherits the same draft-and-publish model, the same per-language documents,
 * the same version history, audit trail and storage-layer authorization that a
 * page has. What differs is only the shape of the body — validated against the
 * collection type's field schema rather than a list of sections — so that is the
 * one thing this service does that PageService does not.
 *
 * The return shape mirrors PageService's: an `entry`, an `error`, an HTTP
 * `status`, and per-field `errors`, so a controller can hand either straight to
 * the client without a translation layer.
 */
final class CollectionService
{
    public function __construct(
        private readonly ContentService $content,
        private readonly CollectionTypeRepository $types,
        private readonly SectionValidator $validator,
        // Optional for the same reason it is optional on PageService: existing
        // callers construct this directly, and a gate they did not ask for must
        // not change their behaviour. Left out, the process-wide one is used.
        private readonly ?PublishGate $publishGate = null,
    ) {}

    private function publishGate(): PublishGate
    {
        return $this->publishGate ?? PublishGate::ambient();
    }

    /** @return list<CollectionType> */
    public function collectionTypes(): array
    {
        return $this->types->all();
    }

    public function collectionType(string $id): ?CollectionType
    {
        return $this->types->find($id);
    }

    /**
     * The working copies of a type's entries — what an editor manages, newest
     * first, including entries that have never been published.
     *
     * @return list<Content>
     */
    public function all(string $typeId, string|Locale|null $locale = null): array
    {
        return $this->ordered($typeId, $this->content->workingCopies($typeId, $locale));
    }

    /**
     * The published entries of a type, for a front end. Only what the public may
     * see, so a draft or a taken-down entry never leaks through delivery.
     *
     * @return list<Content>
     */
    public function published(string $typeId, string|Locale|null $locale = null): array
    {
        return $this->ordered($typeId, $this->content->all($typeId, $locale));
    }

    /**
     * Order a type's entries by its declared sort. Kept in one place so the
     * editor's listing and the public delivery present entries in the same order
     * — a reader and an editor disagreeing about what comes first is exactly the
     * kind of quiet mismatch this avoids.
     *
     * @param list<Content> $entries
     * @return list<Content>
     */
    private function ordered(string $typeId, array $entries): array
    {
        $type = $this->types->find($typeId);

        return $type === null ? $entries : $type->order($entries);
    }

    /** The working copy of one entry, or null. */
    public function find(string $typeId, string $slug, string|Locale|null $locale = null): ?Content
    {
        return $this->content->draft(ContentKey::for($typeId, $slug, $locale));
    }

    /** The published copy of one entry, or null. */
    public function findPublished(string $typeId, string $slug, string|Locale|null $locale = null): ?Content
    {
        return $this->content->get(ContentKey::for($typeId, $slug, $locale));
    }

    public function publicationOf(string $typeId, string $slug, string|Locale|null $locale = null): mixed
    {
        return $this->content->publicationOf(ContentKey::for($typeId, $slug, $locale));
    }

    /**
     * Which languages this entry has actually been written in — the same
     * per-document translation view a page has, so the entry editor can offer a
     * language switcher and show what is still untranslated.
     *
     * @return list<Locale>
     */
    public function translationsOf(string $typeId, string $slug): array
    {
        return $this->content->translationsOf($typeId, $slug);
    }

    /**
     * @param array<string, mixed> $body   The submitted `{ slug?, values }`.
     * @param array<string, mixed> $user
     * @return array{entry: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function create(string $typeId, array $body, array $user, string|Locale|null $locale = null): array
    {
        $type = $this->types->find($typeId);
        if ($type === null) {
            return $this->failure('Unknown collection.', 404);
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->can(Capability::CreateContent)) {
            return $this->failure('You do not have permission to create entries.', 403);
        }

        $validated = $this->validateValues($type, $body['values'] ?? []);
        if ($validated['errors'] !== []) {
            return $this->failure('Some fields are invalid.', 422, $validated['errors']);
        }
        $values = $validated['values'];

        $slug = $this->slugify((string) ($body['slug'] ?? ''))
            ?: $this->slugify((string) ($values[$type->titleField] ?? ''));
        if ($slug === '') {
            $slug = 'untitled';
        }

        // The working copy, not just the live entry: an unpublished draft holds
        // the address as firmly as a published one, and a second create must not
        // silently start a fresh chain over an unfinished entry.
        if ($this->content->draft(ContentKey::for($typeId, $slug, $locale)) !== null) {
            return $this->failure('An entry with that address already exists.', 409);
        }

        $values['slug'] = $slug;
        // Recorded so per-author permissions have an owner to check against.
        $values['owner'] = $user['username'] ?? 'unknown';

        $entry = Content::create(ContentKey::for($typeId, $slug, $locale), $values);
        $this->content->save($entry);

        return ['entry' => $entry, 'error' => null, 'status' => 201, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $user
     * @return array{entry: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function update(string $typeId, string $slug, array $body, array $user, string|Locale|null $locale = null): array
    {
        $type = $this->types->find($typeId);
        if ($type === null) {
            return $this->failure('Unknown collection.', 404);
        }

        $entry = $this->content->draft(ContentKey::for($typeId, $slug, $locale));
        if ($entry === null) {
            return $this->failure('Entry not found.', 404);
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canEditContentOwnedBy($entry->data['owner'] ?? null, $user['username'] ?? null)) {
            return $this->failure('You do not have permission to edit this entry.', 403);
        }

        $validated = $this->validateValues($type, $body['values'] ?? []);
        if ($validated['errors'] !== []) {
            return $this->failure('Some fields are invalid.', 422, $validated['errors']);
        }

        // The slug, language and owner identify the entry; letting a client
        // rewrite them here would orphan every link to it or hand it to someone
        // else. Publication is not a field either — it is presence in content/.
        $values = $validated['values'];
        unset($values['slug'], $values['locale'], $values['owner'], $values['status']);

        $entry->update($values);
        $this->content->save($entry);

        return ['entry' => $entry, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{entry: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function delete(string $typeId, string $slug, array $user, string|Locale|null $locale = null): array
    {
        if ($this->types->find($typeId) === null) {
            return $this->failure('Unknown collection.', 404);
        }

        $entry = $this->content->draft(ContentKey::for($typeId, $slug, $locale));
        if ($entry === null) {
            return $this->failure('Entry not found.', 404);
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canDeleteContentOwnedBy($entry->data['owner'] ?? null, $user['username'] ?? null)) {
            return $this->failure('You do not have permission to delete this entry.', 403);
        }

        $this->content->delete(ContentKey::for($typeId, $slug, $locale));

        return ['entry' => null, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{entry: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function publish(string $typeId, string $slug, array $user, string|Locale|null $locale = null): array
    {
        if ($this->types->find($typeId) === null) {
            return $this->failure('Unknown collection.', 404);
        }

        $entry = $this->content->draft(ContentKey::for($typeId, $slug, $locale));
        if ($entry === null) {
            return $this->failure('Entry not found.', 404);
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canPublishContentOwnedBy($entry->data['owner'] ?? null, $user['username'] ?? null)) {
            return $this->failure('You do not have permission to publish this entry.', 403);
        }

        $key = ContentKey::for($typeId, $slug, $locale);

        // An entry is a second publish path, and a gate that covered only pages
        // would be a gate an editor routes around by putting the thing in a
        // collection. The hook already carries the type, so whatever is gating
        // can tell a post from a page and decide accordingly.
        $refusal = $this->publishGate()->refusalFor($key, $user);
        if ($refusal !== null) {
            // 409, not 403 — see PageService::publish(): the caller is entitled,
            // the entry's state is what is not ready.
            return $this->failure($refusal, 409);
        }

        $published = $this->content->publish($key);
        if ($published === null) {
            return $this->failure('This entry could not be published.', 500);
        }

        $this->publishGate()->announcePublished($key, $user);

        return ['entry' => $published, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{entry: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function unpublish(string $typeId, string $slug, array $user, string|Locale|null $locale = null): array
    {
        if ($this->types->find($typeId) === null) {
            return $this->failure('Unknown collection.', 404);
        }

        $key = ContentKey::for($typeId, $slug, $locale);

        $entry = $this->content->draft($key);
        if ($entry === null) {
            return $this->failure('Entry not found.', 404);
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canUnpublishContentOwnedBy($entry->data['owner'] ?? null, $user['username'] ?? null)) {
            return $this->failure('You do not have permission to unpublish this entry.', 403);
        }

        if (!$this->content->exists($key)) {
            return $this->failure('This entry is not published.', 409);
        }

        $this->content->unpublish($key);

        return ['entry' => $entry, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * Validate a submitted value set against the collection type's fields,
     * discarding anything the schema does not declare so an entry can only ever
     * hold the shape its type was written for.
     *
     * @param mixed $values
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    private function validateValues(CollectionType $type, mixed $values): array
    {
        if (!is_array($values)) {
            return ['values' => [], 'errors' => ['values' => 'Values must be an object.']];
        }

        $result = $this->validator->validate($type->schema, $values);

        return ['values' => $result->values, 'errors' => $result->errors];
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * @param array<string, string> $errors
     * @return array{entry: null, error: string, status: int, errors: array<string, string>}
     */
    private function failure(string $message, int $status, array $errors = []): array
    {
        return ['entry' => null, 'error' => $message, 'status' => $status, 'errors' => $errors];
    }
}
