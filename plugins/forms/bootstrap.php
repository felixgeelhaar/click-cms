<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Contact forms: a page includes a form section, a visitor submits it, and an
 * editor reads the submissions.
 *
 * Forms are an editorial feature, not core (see docs/core.md): a self-rendering
 * site can do without one, so this lives as a plugin. But it is a peculiar
 * plugin, because it opens the one door the rest of the CMS keeps shut — it lets
 * an anonymous visitor with no account write something the system keeps. That
 * single fact shapes every decision here.
 *
 *  - **The input is untrusted, always.** A submission is arbitrary text typed by
 *    someone with no account. It is never evaluated, never interpolated into a
 *    path, never rendered as markup by this plugin. It is stored as data and
 *    handed back as data; whoever displays it escapes it. A form is the textbook
 *    stored-XSS vector precisely because the field's whole purpose is to accept
 *    whatever a stranger types, so the value is treated as bytes to keep, not as
 *    a command to obey.
 *
 *  - **Submissions are content documents.** Type `form_submission`, written
 *    through the same {@see ContentService} every other write uses, so they
 *    inherit storage, backups and the version trail rather than sitting in a
 *    parallel store nothing else can see. The write path is deliberately the
 *    boring one.
 *
 *  - **Spam is met with a honeypot, not a dependency.** A hidden field a human
 *    never sees and a bot fills in reflexively. When it is filled the submission
 *    is accepted with an ordinary success response and then dropped — the bot is
 *    given no signal it was caught, because a bot told it failed simply retries
 *    with the trap avoided. Zero third-party services, per the no-dependency
 *    rule.
 *
 *  - **Reading is asymmetric with writing.** Submitting is public — that is the
 *    point, an anonymous visitor does it — so `/api/forms/submit` is on the
 *    kernel's public allowlist. Reading the submissions is management: the leads
 *    a form collects are private, so `/api/forms/submissions` is deny-by-default
 *    (it is not on the allowlist, so the kernel already demands a session) and
 *    this handler adds a capability check on top, refusing a signed-in account
 *    that lacks editorial reach.
 */
class Plugin_forms extends \Click\Cms\Application\Plugin\BasePlugin
{
    /**
     * The content type submissions are stored under. One document per
     * submission, addressed by a time-ordered slug so a listing is naturally
     * chronological and two submissions never collide on a key.
     */
    private const SUBMISSION_TYPE = 'form_submission';

    /**
     * The hidden field a real visitor leaves empty. Named `website` because that
     * is a field bots are especially eager to fill, and because the section
     * renderer can hide it from humans with a label a person would not type into.
     */
    private const HONEYPOT_FIELD = 'website';

    /**
     * The visitor-facing fields a contact form collects. These are the input
     * names the section renderer must emit, and the names this endpoint reads
     * back out of the POST body. Keeping the list here, beside the validation
     * that enforces it, means the shape is stated once.
     */
    private const MESSAGE_FIELDS = ['name', 'email', 'message'];

    public function getPluginId(): string
    {
        return 'forms';
    }

    public function getPluginName(): string
    {
        return 'Forms';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, callable>
     */
    public function hook_api_routes(array $params): array
    {
        return [
            // Public: an anonymous visitor posts this. Needs adding to the
            // kernel's public allowlist (see the report) — a POST is refused by
            // default, and this one must not be.
            'POST /api/forms/submit' => [$this, 'handleSubmit'],

            // Management: the collected leads. Deny-by-default keeps anonymous
            // callers out; the handler narrows it to editors and above.
            'GET /api/forms/submissions' => [$this, 'handleSubmissions'],
        ];
    }

    /**
     * Accept a submission from a public form.
     *
     * Returns a JSON body with `success: true` on acceptance and a 400 with an
     * `error` on refusal. A tripped honeypot returns the *same* success shape as
     * a genuine acceptance and stores nothing — indistinguishable to the sender
     * on purpose.
     *
     * @return array<string, mixed>
     */
    public function handleSubmit(): array
    {
        $input = $this->readBody();

        // The honeypot is checked before validation, so a bot that also happens
        // to send a malformed payload still gets the neutral success response
        // rather than a 400 that would teach it the field is a trap.
        if (trim((string) ($input[self::HONEYPOT_FIELD] ?? '')) !== '') {
            return $this->accepted();
        }

        $values = [];
        foreach (self::MESSAGE_FIELDS as $field) {
            // Cast rather than trust: a client could send an array where a string
            // is expected, and a value that is not a string is not a valid answer
            // to a text input. Coercing to string keeps the stored shape flat and
            // predictable for whatever reads it back.
            $values[$field] = is_scalar($input[$field] ?? null) ? trim((string) $input[$field]) : '';
        }

        $error = $this->validate($values);
        if ($error !== null) {
            return ['status' => 400, 'error' => $error];
        }

        $this->store(
            page: is_scalar($input['page'] ?? null) ? (string) $input['page'] : '',
            locale: is_scalar($input['locale'] ?? null) ? (string) $input['locale'] : '',
            values: $values,
        );

        return $this->accepted();
    }

    /**
     * List stored submissions, newest first, for an editor.
     *
     * The kernel's deny-by-default gate has already turned away anyone with no
     * session by the time this runs. This adds the finer question the kernel
     * cannot ask from a plugin route: a signed-in *author* or *viewer* has no
     * business reading a form's private leads, so the read is narrowed to a role
     * that can edit any content — editors and administrators. See the report:
     * the strongest home for this is a rule in the kernel's ApiGuard, which owns
     * capability gating; this is the same check placed where file ownership let
     * it live, as defence in depth behind the mandatory kernel gate.
     *
     * @return array<string, mixed>
     */
    public function handleSubmissions(): array
    {
        if (!$this->callerMayReadSubmissions()) {
            return ['status' => 403, 'error' => 'You do not have permission to read form submissions.'];
        }

        $contentService = $this->pluginManager->getContentService();

        $submissions = array_map(
            fn (Content $c): array => $this->present($c),
            $contentService->all(self::SUBMISSION_TYPE)
        );

        // Newest first. Sorting on the stored microsecond timestamp rather than
        // on file order, which glob() leaves filesystem-dependent, so the listing
        // is the same on every machine.
        usort(
            $submissions,
            static fn (array $a, array $b): int => strcmp($b['submittedAt'], $a['submittedAt'])
        );

        return ['data' => $submissions];
    }

    /**
     * The neutral acceptance response, shared by a genuine store and a dropped
     * honeypot so the two cannot be told apart from outside.
     *
     * @return array<string, mixed>
     */
    private function accepted(): array
    {
        return ['success' => true, 'message' => 'Thank you. Your message has been received.'];
    }

    /**
     * @param array<string, string> $values
     */
    private function validate(array $values): ?string
    {
        foreach (self::MESSAGE_FIELDS as $field) {
            if ($values[$field] === '') {
                return ucfirst($field) . ' is required.';
            }
        }

        // filter_var, not a regex of our own: reinventing email validation is a
        // reliable way to reject addresses that are valid and accept ones that
        // are not.
        if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'That email address does not look valid.';
        }

        return null;
    }

    /**
     * Write the submission as a content document.
     *
     * @param array<string, string> $values
     */
    private function store(string $page, string $locale, array $values): void
    {
        $contentService = $this->pluginManager->getContentService();

        // Microsecond precision so the ordering is total even for submissions
        // that arrive in the same second, and so two keys never collide.
        $now = new \DateTimeImmutable();
        $submittedAt = $now->format('Y-m-d\TH:i:s.uP');

        $key = ContentKey::fromString(self::SUBMISSION_TYPE . ':' . $this->slug($now));

        $contentService->save(Content::create($key, [
            // The source page's slug, so an editor reading a lead knows which
            // form it came from. Sanitised to the same segment shape a slug is
            // allowed to have — a submission cannot dictate a path or carry
            // markup into the field through this context value.
            'page' => $this->safePageRef($page),
            'locale' => $this->safeLocaleRef($locale),
            'submittedAt' => $submittedAt,
            // The untrusted values, stored exactly as typed. Not escaped here:
            // escaping is the display layer's job and doing it at rest would
            // corrupt the stored data for every reader that is not HTML.
            'fields' => $values,
        ]));
    }

    /**
     * Shape one stored document for the listing.
     *
     * @return array<string, mixed>
     */
    private function present(Content $content): array
    {
        $data = $content->data;

        return [
            'id' => $content->slug(),
            'page' => is_string($data['page'] ?? null) ? $data['page'] : '',
            'locale' => is_string($data['locale'] ?? null) ? $data['locale'] : '',
            'submittedAt' => is_string($data['submittedAt'] ?? null) ? $data['submittedAt'] : '',
            'fields' => is_array($data['fields'] ?? null) ? $data['fields'] : [],
        ];
    }

    /**
     * A time-ordered, collision-resistant slug: the timestamp so listings sort
     * chronologically by key alone, and random bytes so two submissions in the
     * same microsecond still get distinct documents. Every character is in the
     * set a content slug is allowed to contain.
     */
    private function slug(\DateTimeImmutable $at): string
    {
        return $at->format('Ymd-His-u') . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Reduce a page reference to a safe slug segment, or empty. This value comes
     * off the request, so it is untrusted like everything else: constraining it
     * to slug characters means a hostile `page` cannot smuggle markup into the
     * stored record or anything path-shaped into a key.
     */
    private function safePageRef(string $page): string
    {
        $page = trim($page);

        return preg_match('/^[A-Za-z0-9._-]+$/', $page) === 1 ? $page : '';
    }

    private function safeLocaleRef(string $locale): string
    {
        $locale = trim($locale);

        return preg_match('/^[A-Za-z0-9_-]+$/', $locale) === 1 ? $locale : '';
    }

    /**
     * Whether the signed-in caller may read submissions.
     *
     * Reads the session directly because a plugin route handler is not handed
     * the current user the way core handlers are. When no session can be
     * resolved at all — a unit test, a CLI call — this returns true: there is no
     * account to check, and in the real HTTP path the kernel's mandatory
     * deny-by-default gate has already refused an anonymous caller before this
     * point, so falling through here cannot expose anything the kernel let
     * through. The check exists to stop an *authenticated but under-privileged*
     * account, which is the one case the kernel's coarse gate does not cover.
     */
    private function callerMayReadSubmissions(): bool
    {
        if (!class_exists(SessionStore::class)) {
            return true;
        }

        $sessions = new SessionStore($this->pluginManager->getBasePath() . '/data/sessions');
        $user = $sessions->user();

        if ($user === null) {
            return true;
        }

        return Role::fromName($user['role'] ?? null)->can(Capability::EditAnyContent);
    }

    /**
     * The submission's field values, from a JSON body if one was sent, otherwise
     * from the form-encoded POST. A browser form posts the latter; a scripted
     * client may send the former. Reading both means the endpoint works from a
     * plain `<form>` and from `fetch(..., {body: JSON})` alike.
     *
     * @return array<string, mixed>
     */
    private function readBody(): array
    {
        $raw = (string) file_get_contents('php://input');

        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
    }
}
