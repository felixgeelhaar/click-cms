<?php

declare(strict_types=1);

namespace Click\Cms\Application\Content;

use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\Schema\SectionTypeRepository;
use Click\Cms\Domain\Content\ResolvedContent;
use Click\Cms\Domain\Publishing\PublicationSchedule;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\ScheduledDocument;
use Click\Cms\Domain\Publishing\ScheduleStore;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

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
 *
 * Everything here reads and writes the *working copy*, not the live page. That
 * is what management means now that a save no longer goes straight to the
 * public site: {@see all()} and {@see find()} show an editor what they are
 * working on, including pages nobody has published, while {@see resolve()}
 * remains the reader's path and shows only what is live. Publication is a
 * separate act — {@see publish()} — and per language, because `page:de:home`
 * and `page:en:home` are two documents and always have been.
 */
final class PageService
{
    /** @var list<Locale> */
    private readonly array $supportedLocales;

    /**
     * @param list<Locale> $supportedLocales Languages this site publishes in.
     *        Empty means no restriction, which is what a caller with no
     *        configuration to consult — a test, a plugin — should get.
     * @param ?PublishGate $publishGate Who may veto a publish. Null falls back
     *        to the process-wide gate the kernel installs at boot, so a caller
     *        that knows nothing about plugins still cannot publish past one.
     * @param ?ScheduleStore $schedules Where deferred publications are kept.
     *        Null means this installation has none, and every scheduling method
     *        refuses with a 501 rather than accepting a schedule nothing will
     *        ever carry out.
     */
    public function __construct(
        private readonly ContentService $content,
        private readonly SectionTypeRepository $sectionTypes,
        private readonly SectionValidator $validator = new SectionValidator(),
        array $supportedLocales = [],
        private readonly ?PublishGate $publishGate = null,
        private readonly ?ScheduleStore $schedules = null,
    ) {
        $this->supportedLocales = array_values($supportedLocales);
    }

    /**
     * Resolved per call rather than in the constructor: this service is built
     * lazily by handlers that may exist before the kernel has finished booting
     * its plugins, and a gate captured too early would be the empty one.
     */
    private function publishGate(): PublishGate
    {
        return $this->publishGate ?? PublishGate::ambient();
    }

    /**
     * Every page as it is being worked on, live or not.
     *
     * @return list<Content>
     */
    public function all(string|Locale|null $locale = null): array
    {
        return $this->content->draftPages($locale);
    }

    /**
     * Only what the public can read — the list a delivery path wants.
     *
     * @return list<Content>
     */
    public function published(string|Locale|null $locale = null): array
    {
        return $this->content->pages($locale);
    }

    /** The working copy in exactly this language, or nothing. */
    public function find(string $slug, string|Locale|null $locale = null): ?Content
    {
        return $this->content->draftPage($slug, $locale);
    }

    /** Where this page stands: live, edited since, or never published. */
    public function publicationOf(string $slug, string|Locale|null $locale = null): PublicationState
    {
        $parsed = $this->parseLocale(is_string($locale) ? $locale : $locale?->code);

        return $this->content->publicationOf(
            ContentKey::page($slug, $parsed['locale'] ?? $this->content->defaultLocale())
        );
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
        //
        // The working copy, not the live page. An unpublished draft occupies the
        // address just as firmly, and refusing to notice it would let a second
        // "create" silently start a fresh chain over somebody's unfinished work.
        if ($this->content->draftPage($slug, $locale) !== null) {
            return $this->failure('A page with that address already exists.', 409);
        }

        $sections = $this->validateSections($data);
        if ($sections['errors'] !== []) {
            return $this->failure('Some sections are invalid.', 422, $sections['errors']);
        }
        $data = $sections['data'];

        // Recorded so per-author permissions have something to check against.
        $data['owner'] ??= $user['username'] ?? 'unknown';

        // See `unset()` in `update()`: publication is not a field, and a client
        // sending one must not be able to put it back.
        unset($data['status']);

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
        //
        // The working copy, so a second edit builds on the first rather than on
        // whatever happens to be live. Editing an unpublished page twice would
        // otherwise 404 the second time.
        $page = $this->content->draftPage($slug, $parsed['locale']);
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
        //
        // `status` goes with them because publication is no longer a field on
        // the document — it is presence in `content/`. Letting a client write
        // one would recreate exactly the two-sources-of-truth problem this
        // change removed: a page claiming to be published while the public gets
        // a 404, with nothing able to say which was right.
        unset($data['slug'], $data['locale'], $data['status']);

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

        $page = $this->content->draftPage($slug, $parsed['locale']);
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

    /* ------------------------------------------------------- publication -- */

    /**
     * Put the working copy of this page, in this language, in front of the
     * public.
     *
     * One language at a time and no cross-language grouping, because there is
     * nothing to group: `page:de:home` and `page:en:home` are two documents, and
     * publishing a German translation the moment its English original is
     * approved is exactly the accident this avoids.
     *
     * This is also where an editorial gate — a review workflow, an embargo — is
     * asked whether the publish may happen at all. Here rather than in the HTTP
     * handler, because a gate the seeder, the CLI or a plugin walks past is not
     * a gate; every route to the live site goes through this method.
     *
     * @param array<string, mixed> $user
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function publish(string $slug, array $user, string|Locale|null $locale = null): array
    {
        $parsed = $this->parseLocale(is_string($locale) ? $locale : $locale?->code);
        if ($parsed['error'] !== null) {
            return $this->failure($parsed['error'], 400);
        }

        $page = $this->content->draftPage($slug, $parsed['locale']);
        if ($page === null) {
            return $this->failure('Page not found.', 404);
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canPublishContentOwnedBy($page->data['owner'] ?? null, $user['username'] ?? null)) {
            return $this->failure('You do not have permission to publish this page.', 403);
        }

        $key = ContentKey::page($slug, $parsed['locale']);

        // Asked after permission, so an account that may not publish at all is
        // never told about an editorial state it has no business seeing.
        $refusal = $this->publishGate()->refusalFor($key, $user);
        if ($refusal !== null) {
            // 409 rather than 403: the request is well formed and the caller is
            // entitled to make it. What is wrong is the page's current state,
            // and telling an editor "forbidden" would send them looking for a
            // permission they already have.
            return $this->failure($refusal, 409);
        }

        $published = $this->content->publish($key);

        if ($published === null) {
            // The working copy was there a moment ago, so this is a storage
            // failure rather than a missing page, and saying "not found" would
            // send the editor looking for the wrong problem.
            return $this->failure('This page could not be published.', 500);
        }

        // Only now, and only once it landed: whoever was gating this publish
        // gets to close its own record, and cannot close it for a publish that
        // never happened.
        $this->publishGate()->announcePublished($key, $user);

        return ['page' => $published, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /**
     * Take the live page down. The working copy and every version survive.
     *
     * @param array<string, mixed> $user
     * @return array{page: ?Content, error: ?string, status: int, errors: array<string, string>}
     */
    public function unpublish(string $slug, array $user, string|Locale|null $locale = null): array
    {
        $parsed = $this->parseLocale(is_string($locale) ? $locale : $locale?->code);
        if ($parsed['error'] !== null) {
            return $this->failure($parsed['error'], 400);
        }

        $key = ContentKey::page($slug, $parsed['locale']);

        $page = $this->content->draftPage($slug, $parsed['locale']);
        if ($page === null) {
            return $this->failure('Page not found.', 404);
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canUnpublishContentOwnedBy($page->data['owner'] ?? null, $user['username'] ?? null)) {
            return $this->failure('You do not have permission to unpublish this page.', 403);
        }

        // Refused rather than reported as a success. An editor who is told
        // "unpublished" about a page that was never live has been told the
        // system did something, and will not go looking for the reason their
        // change is still not visible.
        if (!$this->content->exists($key)) {
            return $this->failure('This page is not published.', 409);
        }

        $this->content->unpublish($key);

        return ['page' => $page, 'error' => null, 'status' => 200, 'errors' => []];
    }

    /* ------------------------------------------------------- scheduling -- */

    /**
     * Arrange for a page to publish, or to come down, at a stated time.
     *
     * Scheduling is publishing, deferred, so it is governed by the publish
     * permission and nothing weaker. An account that may not put a page live now
     * may not arrange for it to put itself live at three in the morning either —
     * a deferred act that skipped the check would be a way for any author to
     * publish anything, just more slowly.
     *
     * The publish *gate* is a different matter and is deliberately not consulted
     * here. A review workflow's answer is about the page as it stands, and the
     * page will have changed by the time the schedule fires; asking now would
     * cache a stale approval. {@see \Click\Cms\Application\Publishing\SchedulingService}
     * asks it at the moment of publication instead, which is the moment the
     * answer is about.
     *
     * @param array<string, mixed> $user
     * @param ?string $publishAt   An absolute instant, or null for none.
     * @param ?string $unpublishAt An absolute instant, or null for none.
     * @return array{schedule: array<string, mixed>, error: ?string, status: int}
     */
    public function schedule(
        string $slug,
        array $user,
        ?string $publishAt,
        ?string $unpublishAt,
        string|Locale|null $locale = null,
    ): array {
        $context = $this->scheduleContext($slug, $user, $locale);
        if ($context['error'] !== null) {
            return $this->scheduleFailure($context['error'], $context['status']);
        }

        try {
            $schedule = PublicationSchedule::of(
                $this->parseInstant($publishAt, 'publication'),
                $this->parseInstant($unpublishAt, 'takedown'),
            );
        } catch (InvalidArgumentException $e) {
            // 422 rather than 400: the request is well formed and the caller is
            // entitled to make it; what is wrong is the pair of times in it.
            return $this->scheduleFailure($e->getMessage(), 422);
        }

        $schedules = $this->schedules;
        $schedules->save($context['key'], $schedule, $user['username'] ?? null);

        return $this->scheduleResponse($context['key'], $schedule, $user['username'] ?? null);
    }

    /**
     * What is scheduled for this page, if anything.
     *
     * @param array<string, mixed> $user
     * @return array{schedule: array<string, mixed>, error: ?string, status: int}
     */
    public function scheduleOf(string $slug, array $user, string|Locale|null $locale = null): array
    {
        $context = $this->scheduleContext($slug, $user, $locale);
        if ($context['error'] !== null) {
            return $this->scheduleFailure($context['error'], $context['status']);
        }

        $key = $context['key'];

        return $this->scheduleResponse(
            $key,
            $this->schedules->find($key),
            $this->schedules->scheduledBy($key),
        );
    }

    /**
     * Cancel whatever was scheduled. Cancelling nothing is a success, because
     * the state the caller asked for is the state that already holds.
     *
     * @param array<string, mixed> $user
     * @return array{schedule: array<string, mixed>, error: ?string, status: int}
     */
    public function clearSchedule(string $slug, array $user, string|Locale|null $locale = null): array
    {
        $context = $this->scheduleContext($slug, $user, $locale);
        if ($context['error'] !== null) {
            return $this->scheduleFailure($context['error'], $context['status']);
        }

        $this->schedules->clear($context['key']);

        return $this->scheduleResponse($context['key'], PublicationSchedule::none(), null);
    }

    /**
     * Everything scheduled across the site, soonest first.
     *
     * Ordered by what happens next rather than by page, because the question
     * this list answers is "what is about to change", and a reader scanning for
     * that should not have to sort it themselves.
     *
     * @param array<string, mixed> $user
     * @return array{schedules: list<array<string, mixed>>, error: ?string, status: int}
     */
    public function pendingSchedules(array $user): array
    {
        if ($this->schedules === null) {
            return ['schedules' => [], 'error' => $this->noScheduleStoreMessage(), 'status' => 501];
        }

        // The listing shows unpublished work — what is queued to go live, and
        // when — so it is gated on the same permission that may act on it.
        if (!Role::fromName($user['role'] ?? null)->canPublishContentOwnedBy(null, $user['username'] ?? null)) {
            return ['schedules' => [], 'error' => 'You do not have permission to see scheduled publications.', 'status' => 403];
        }

        $pending = $this->schedules->all();

        usort($pending, static function (ScheduledDocument $a, ScheduledDocument $b): int {
            $left = $a->schedule->nextDueAt();
            $right = $b->schedule->nextDueAt();

            // Neither can actually be null — an empty schedule is never stored —
            // but the port permits it, and sorting must not depend on that.
            if ($left === null || $right === null) {
                return $left === $right ? 0 : ($left === null ? 1 : -1);
            }

            return $left <=> $right;
        });

        return [
            'schedules' => array_map(
                fn (ScheduledDocument $d): array => $this->scheduleShape($d->key, $d->schedule, $d->scheduledBy),
                $pending
            ),
            'error' => null,
            'status' => 200,
        ];
    }

    /**
     * The checks every scheduling operation shares: a store exists, the language
     * parses, the page exists, and the caller may publish it.
     *
     * @param array<string, mixed> $user
     * @return array{key: ?ContentKey, error: ?string, status: int}
     */
    private function scheduleContext(string $slug, array $user, string|Locale|null $locale): array
    {
        if ($this->schedules === null) {
            return ['key' => null, 'error' => $this->noScheduleStoreMessage(), 'status' => 501];
        }

        $parsed = $this->parseLocale(is_string($locale) ? $locale : $locale?->code);
        if ($parsed['error'] !== null) {
            return ['key' => null, 'error' => $parsed['error'], 'status' => 400];
        }

        $page = $this->content->draftPage($slug, $parsed['locale']);
        if ($page === null) {
            return ['key' => null, 'error' => 'Page not found.', 'status' => 404];
        }

        $role = Role::fromName($user['role'] ?? null);
        if (!$role->canPublishContentOwnedBy($page->data['owner'] ?? null, $user['username'] ?? null)) {
            return ['key' => null, 'error' => 'You do not have permission to schedule this page.', 'status' => 403];
        }

        return ['key' => ContentKey::page($slug, $parsed['locale']), 'error' => null, 'status' => 200];
    }

    /**
     * Read one instant from what an editor's browser sent.
     *
     * Only absolute times are schedules. A relative expression — "+1 week",
     * "tomorrow" — is refused rather than resolved, because resolving it once at
     * save time stores something the editor did not type, and *not* resolving it
     * would make the stored schedule mean a different moment on every sweep.
     *
     * @throws InvalidArgumentException with a message naming which end is wrong.
     */
    private function parseInstant(?string $value, string $which): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // A full date is what separates an instant from an expression. Checked
        // before parsing because `DateTimeImmutable` accepts both and reports
        // no difference afterwards.
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value)) !== 1) {
            throw new InvalidArgumentException(
                "The scheduled {$which} must be a date and time, such as 2026-08-01T09:00:00Z."
            );
        }

        try {
            $parsed = new DateTimeImmutable(trim($value));
        } catch (\Exception) {
            throw new InvalidArgumentException("The scheduled {$which} is not a date and time this understands.");
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['error_count'] ?? 0) > 0 || ($errors['warning_count'] ?? 0) > 0)) {
            throw new InvalidArgumentException("The scheduled {$which} is not a date and time this understands.");
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array{schedule: array<string, mixed>, error: ?string, status: int}
     */
    private function scheduleResponse(ContentKey $key, PublicationSchedule $schedule, ?string $by): array
    {
        return [
            'schedule' => $this->scheduleShape($key, $schedule, $by),
            'error' => null,
            'status' => 200,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleShape(ContentKey $key, PublicationSchedule $schedule, ?string $by): array
    {
        return $schedule->toArray() + [
            'slug' => $key->slug,
            'type' => $key->type,
            'locale' => $key->locale->code,
            'scheduledBy' => $by,
        ];
    }

    /**
     * @return array{schedule: array<string, mixed>, error: string, status: int}
     */
    private function scheduleFailure(string $message, int $status): array
    {
        return ['schedule' => [], 'error' => $message, 'status' => $status];
    }

    /**
     * Refusing rather than quietly accepting. A site with no schedule store that
     * answered "scheduled" would leave an editor waiting for a publication that
     * nothing on the system is going to perform — the silent degradation
     * `core.md` names as this codebase's recurring bug.
     */
    private function noScheduleStoreMessage(): string
    {
        return 'Scheduled publishing is not available on this installation.';
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
