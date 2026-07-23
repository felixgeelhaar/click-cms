<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Collaboration: presence and comments on a page before it goes live.
 *
 * This is the Google-Docs convention narrowed to what a CMS actually needs (see
 * docs/collaboration.md): see who else is on a page, leave review comments, and
 * publish when everyone is happy — but deliberately *not* co-editing, which is a
 * research-grade merge problem this plugin does not attempt. What is left,
 * presence and comments, is ordinary create/read/update, and that is all this
 * handler does.
 *
 * Every decision here follows from three facts:
 *
 *  - **Transport is polling, never a socket.** The CMS runs on managed shared
 *    hosting with no long-running process, no spare port and no daemon (core.md),
 *    so presence cannot be pushed. The client heart-beats every ~10s and polls
 *    for the roster; the only realtime features left after dropping co-editing —
 *    an avatar and an "also editing" warning — are not latency-sensitive, so
 *    twelve seconds of lag costs nothing and a socket buys nothing. The seam is
 *    an ordinary HTTP endpoint precisely so a future WebSocket transport could
 *    replace it without the rest of the plugin noticing.
 *
 *  - **Presence self-expires.** A heartbeat is a timestamp, not a login, and
 *    browsers close without saying goodbye. So there is no "leave" call to trust:
 *    an editor is present only while their last beat is recent, and anyone older
 *    than {@see PRESENCE_TTL_SECONDS} is pruned on the next read or write. The
 *    roster is therefore always correct without anything having to clean up after
 *    a crashed tab.
 *
 *  - **Nobody here is anonymous.** This is the mirror image of the forms plugin.
 *    A form submit is the one endpoint a stranger may write to; collaboration is
 *    the opposite — a heartbeat or a comment is an act by a named editor, so no
 *    session means no access, and on top of that the account must be able to edit
 *    any content (editor and administrator). An author who may only touch their
 *    own drafts has no business in another page's review thread.
 *
 * Comments are content documents of type {@see COMMENT_TYPE}, written through the
 * same {@see \Click\Cms\Application\Content\ContentService} every other write
 * uses — exactly as the forms plugin stores `form_submission` — so they inherit
 * storage, backups and the version trail. A comment body is text an editor
 * typed: it is stored verbatim as data, never escaped at rest and never rendered
 * as markup by this plugin. Whoever displays it escapes it (the admin panel uses
 * text interpolation only), which keeps the stored bytes honest for every reader
 * that is not HTML.
 */
class Plugin_collaboration extends \Click\Cms\Application\Plugin\BasePlugin
{
    /**
     * One presence document per page+language, holding a map of the editors
     * currently on it keyed by username. A single document rather than one per
     * heartbeat means a beat is an update to an existing record, so the store
     * does not grow without bound and the roster is a single read.
     */
    private const PRESENCE_TYPE = 'collaboration_presence';

    /**
     * One document per comment, addressed by a time-ordered slug — the same
     * shape the forms plugin gives a submission, so a listing is chronological by
     * key and two comments never collide.
     */
    private const COMMENT_TYPE = 'collaboration_comment';

    /**
     * How long a heartbeat counts as "present". Three missed beats at the ~10s
     * client interval: long enough that a slow poll or a paused tab does not make
     * someone flicker out and back, short enough that a closed browser is gone
     * within half a minute. The client interval and this ceiling are a pair — the
     * report states both.
     */
    private const PRESENCE_TTL_SECONDS = 30;

    public function getPluginId(): string
    {
        return 'collaboration';
    }

    public function getPluginName(): string
    {
        return 'Collaboration';
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
        // None of these are on the kernel's public allowlist, so the kernel's
        // deny-by-default gate already refuses a caller with no session before a
        // handler runs. Each handler then adds the finer capability check the
        // kernel cannot ask from a plugin route.
        return [
            // Presence: a beat that says "I am still here", and the roster of who
            // else is. Same path, split by method.
            'POST /api/collaboration/presence' => [$this, 'handleHeartbeat'],
            'GET /api/collaboration/presence' => [$this, 'handlePresence'],

            // Comments: post one, list a page's thread, mark one resolved.
            'POST /api/collaboration/comments' => [$this, 'handlePostComment'],
            'GET /api/collaboration/comments' => [$this, 'handleListComments'],
            'POST /api/collaboration/comments/resolve' => [$this, 'handleResolveComment'],
        ];
    }

    /* ------------------------------------------------------------ presence -- */

    /**
     * Record the caller's heartbeat and hand back the current roster.
     *
     * The response includes the roster so a single poll both refreshes presence
     * and reads it — the client never needs a second request per tick.
     *
     * @return array<string, mixed>
     */
    public function handleHeartbeat(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $input = $this->readBody();
        $page = $this->safePageRef($this->stringField($input, 'page'));
        $locale = $this->safeLocaleRef($this->stringField($input, 'locale'));

        if ($page === '') {
            return ['status' => 400, 'error' => 'A page is required to record presence.'];
        }

        $this->recordPresence($page, $locale, $this->identityKey($user), $this->displayName($user));

        return ['data' => ['editors' => $this->presenceFor($page, $locale)]];
    }

    /**
     * The editors currently on a page, pruned of anyone gone stale.
     *
     * @return array<string, mixed>
     */
    public function handlePresence(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $page = $this->safePageRef((string) ($_GET['page'] ?? ''));
        $locale = $this->safeLocaleRef((string) ($_GET['locale'] ?? ''));

        if ($page === '') {
            return ['status' => 400, 'error' => 'A page is required to read presence.'];
        }

        return ['data' => ['editors' => $this->presenceFor($page, $locale)]];
    }

    /**
     * Merge the caller into the page's roster, refreshing their timestamp.
     *
     * Public rather than private so the pruning behaviour can be exercised
     * directly with injected clock values — the HTTP handlers always pass the
     * real time. Overwriting by username means a second tab or a reload updates
     * the same person rather than listing them twice.
     */
    public function recordPresence(
        string $page,
        string $locale,
        string $userKey,
        string $displayName,
        ?int $now = null
    ): void {
        $now ??= time();
        $contentService = $this->pluginManager->getContentService();

        $key = $this->presenceKey($page, $locale);
        $existing = $contentService->get($key);

        $editors = $this->prune($this->editorsOf($existing), $now);
        $editors[$userKey] = ['displayName' => $displayName, 'lastSeen' => $now];

        $data = ['page' => $page, 'locale' => $locale, 'editors' => $editors];

        $contentService->save(
            $existing !== null ? $existing->update($data) : Content::create($key, $data)
        );
    }

    /**
     * The fresh editors on a page, most recently seen first.
     *
     * Public for the same reason as {@see recordPresence()}: the staleness rule
     * is the property worth testing, and testing it needs a clock.
     *
     * @return list<array{user: string, name: string, lastSeen: int}>
     */
    public function presenceFor(string $page, string $locale, ?int $now = null): array
    {
        $now ??= time();
        $contentService = $this->pluginManager->getContentService();

        $editors = $this->prune(
            $this->editorsOf($contentService->get($this->presenceKey($page, $locale))),
            $now
        );

        $out = [];
        foreach ($editors as $userKey => $entry) {
            $out[] = [
                'user' => (string) $userKey,
                'name' => is_string($entry['displayName'] ?? null) && $entry['displayName'] !== ''
                    ? $entry['displayName']
                    : 'Someone',
                'lastSeen' => (int) ($entry['lastSeen'] ?? 0),
            ];
        }

        // A stable order the viewer can perceive, so the bar does not reshuffle
        // on every poll for no visible reason.
        usort($out, static fn (array $a, array $b): int => $b['lastSeen'] <=> $a['lastSeen']);

        return $out;
    }

    /**
     * Drop every editor whose last beat is older than the TTL. The prune runs on
     * both read and write, so a stale entry is ignored the instant it is read and
     * physically removed the next time anyone beats on the same page — presence
     * needs no separate reaper.
     *
     * @param array<string, mixed> $editors
     * @return array<string, mixed>
     */
    private function prune(array $editors, int $now): array
    {
        $fresh = [];
        foreach ($editors as $userKey => $entry) {
            $lastSeen = is_array($entry) ? (int) ($entry['lastSeen'] ?? 0) : 0;
            if ($now - $lastSeen <= self::PRESENCE_TTL_SECONDS) {
                $fresh[$userKey] = $entry;
            }
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function editorsOf(?Content $presence): array
    {
        if ($presence === null) {
            return [];
        }

        $editors = $presence->data['editors'] ?? null;

        return is_array($editors) ? $editors : [];
    }

    private function presenceKey(string $page, string $locale): ContentKey
    {
        // Presence is per page *and* language, because `page:de:home` and
        // `page:en:home` are separate documents an editor works on separately.
        // Both segments are already reduced to slug characters, so joining them
        // cannot smuggle a second key part in.
        $slug = $page . ($locale !== '' ? '.' . $locale : '');

        return ContentKey::fromString(self::PRESENCE_TYPE . ':' . $slug);
    }

    /* ------------------------------------------------------------ comments -- */

    /**
     * Post a comment against a page.
     *
     * @return array<string, mixed>
     */
    public function handlePostComment(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $input = $this->readBody();
        $page = $this->safePageRef($this->stringField($input, 'page'));
        $locale = $this->safeLocaleRef($this->stringField($input, 'locale'));
        $body = trim($this->stringField($input, 'body'));

        if ($page === '') {
            return ['status' => 400, 'error' => 'A page is required to post a comment.'];
        }
        if ($body === '') {
            return ['status' => 400, 'error' => 'A comment cannot be empty.'];
        }

        $comment = $this->storeComment(
            $page,
            $locale,
            $this->identityKey($user),
            $this->displayName($user),
            $body,
        );

        return ['data' => $comment];
    }

    /**
     * List a page's comment thread, oldest first.
     *
     * @return array<string, mixed>
     */
    public function handleListComments(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $page = $this->safePageRef((string) ($_GET['page'] ?? ''));
        $locale = $this->safeLocaleRef((string) ($_GET['locale'] ?? ''));

        if ($page === '') {
            return ['status' => 400, 'error' => 'A page is required to list comments.'];
        }

        return ['data' => $this->commentsFor($page, $locale)];
    }

    /**
     * Mark a comment resolved.
     *
     * Resolving is an update to the same document, not a delete: the thread stays
     * readable so an editor can see a point was raised and settled rather than
     * finding it silently gone.
     *
     * @return array<string, mixed>
     */
    public function handleResolveComment(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $input = $this->readBody();
        $id = $this->safeId($this->stringField($input, 'id'));

        if ($id === '') {
            return ['status' => 400, 'error' => 'A comment id is required.'];
        }

        $contentService = $this->pluginManager->getContentService();
        $key = ContentKey::fromString(self::COMMENT_TYPE . ':' . $id);
        $comment = $contentService->get($key);

        if ($comment === null || $comment->type() !== self::COMMENT_TYPE) {
            return ['status' => 404, 'error' => 'That comment does not exist.'];
        }

        $comment->update([
            'resolved' => true,
            'resolvedAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
            'resolvedBy' => $this->identityKey($user),
        ]);
        $contentService->save($comment);

        return ['data' => $this->presentComment($comment)];
    }

    /**
     * Write a comment as a content document and return its presented shape.
     *
     * @return array<string, mixed>
     */
    private function storeComment(
        string $page,
        string $locale,
        string $author,
        string $authorName,
        string $body
    ): array {
        $contentService = $this->pluginManager->getContentService();

        $now = new \DateTimeImmutable();
        $key = ContentKey::fromString(self::COMMENT_TYPE . ':' . $this->slug($now));

        $comment = Content::create($key, [
            'page' => $page,
            'locale' => $locale,
            'author' => $author,
            'authorName' => $authorName,
            // The editor's text, kept exactly as typed. Not escaped here:
            // escaping is the display layer's job, and doing it at rest would
            // corrupt the stored value for every reader that is not HTML.
            'body' => $body,
            'resolved' => false,
            // A field of our own rather than the aggregate's createdAt, which
            // Content::create() consumes as the record's timestamp and strips
            // from data — mirroring how the forms plugin stores `submittedAt`.
            'postedAt' => $now->format('Y-m-d\TH:i:s.uP'),
            'resolvedAt' => null,
            'resolvedBy' => null,
        ]);

        $contentService->save($comment);

        return $this->presentComment($comment);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commentsFor(string $page, string $locale): array
    {
        $contentService = $this->pluginManager->getContentService();

        $out = [];
        foreach ($contentService->all(self::COMMENT_TYPE) as $comment) {
            $data = $comment->data;
            if (($data['page'] ?? '') !== $page) {
                continue;
            }
            // An empty locale filter means "any language", so a caller that does
            // not know the language still gets the thread; a specific one narrows
            // to that language's document set.
            if ($locale !== '' && ($data['locale'] ?? '') !== $locale) {
                continue;
            }
            $out[] = $this->presentComment($comment);
        }

        // Oldest first: a thread reads top to bottom in the order it was written.
        usort($out, static fn (array $a, array $b): int => strcmp($a['postedAt'], $b['postedAt']));

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentComment(Content $comment): array
    {
        $data = $comment->data;

        return [
            'id' => $comment->slug(),
            'page' => is_string($data['page'] ?? null) ? $data['page'] : '',
            'locale' => is_string($data['locale'] ?? null) ? $data['locale'] : '',
            'author' => is_string($data['author'] ?? null) ? $data['author'] : '',
            'authorName' => is_string($data['authorName'] ?? null) ? $data['authorName'] : '',
            // Handed back as data, the exact bytes stored. The reader escapes it.
            'body' => is_string($data['body'] ?? null) ? $data['body'] : '',
            'resolved' => ($data['resolved'] ?? false) === true,
            'postedAt' => is_string($data['postedAt'] ?? null) ? $data['postedAt'] : '',
            'resolvedAt' => is_string($data['resolvedAt'] ?? null) ? $data['resolvedAt'] : null,
            'resolvedBy' => is_string($data['resolvedBy'] ?? null) ? $data['resolvedBy'] : null,
        ];
    }

    /**
     * Escape a value for safe inclusion in HTML.
     *
     * The API returns JSON, so this plugin renders no markup of its own — the
     * admin panel is the display layer and escapes with text interpolation. This
     * exists for any server-side HTML context a caller may build from a stored
     * comment (an email notification, a rendered digest), so the one correct
     * escaping is stated here rather than reinvented at each call site.
     */
    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /* ------------------------------------------------------- identity/auth -- */

    /**
     * The signed-in account, or null. Read straight from the session store
     * because a plugin route handler is not handed the current user the way core
     * handlers are.
     *
     * @return array<string, mixed>|null
     */
    private function currentUser(): ?array
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        $sessions = new SessionStore($this->pluginManager->getBasePath() . '/data/sessions');

        return $sessions->user();
    }

    /**
     * Whether the caller may take part in collaboration at all.
     *
     * The opposite default from the forms plugin: there, a null user falls
     * through to the kernel's gate because submitting is public. Here nothing is
     * public, so a null user is refused outright, and a signed-in account still
     * needs the reach to edit any content — the same management bar the forms
     * *reading* endpoint holds.
     *
     * @param array<string, mixed>|null $user
     */
    private function mayCollaborate(?array $user): bool
    {
        return $user !== null
            && Role::fromName(is_string($user['role'] ?? null) ? $user['role'] : null)
                ->can(Capability::EditAnyContent);
    }

    /**
     * A stable key for the account, reduced to slug characters so it is safe as a
     * roster map key and as a stored identifier.
     *
     * @param array<string, mixed> $user
     */
    private function identityKey(array $user): string
    {
        $username = is_string($user['username'] ?? null) ? $user['username'] : '';
        $key = preg_replace('/[^A-Za-z0-9._-]/', '', $username) ?? '';

        return $key !== '' ? $key : 'unknown';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function displayName(array $user): string
    {
        $name = $user['displayName'] ?? ($user['username'] ?? '');

        return is_string($name) && $name !== '' ? $name : 'Someone';
    }

    /**
     * @return array<string, mixed>
     */
    private function forbidden(): array
    {
        return ['status' => 403, 'error' => 'You do not have permission to collaborate on this page.'];
    }

    /* --------------------------------------------------------------- input -- */

    /**
     * A single string field from a decoded body, coerced and never trusted to be
     * a string in the first place.
     *
     * @param array<string, mixed> $input
     */
    private function stringField(array $input, string $field): string
    {
        return is_scalar($input[$field] ?? null) ? (string) $input[$field] : '';
    }

    /**
     * Reduce a page reference to a safe slug segment, or empty. Untrusted like
     * everything off the request: constraining it to slug characters means a
     * hostile value cannot smuggle markup into a stored record or a path into a
     * key.
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
     * The id of a comment to act on, constrained to the exact character set the
     * slug generator produces so it cannot be steered into another key.
     */
    private function safeId(string $id): string
    {
        $id = trim($id);

        return preg_match('/^[A-Za-z0-9._-]+$/', $id) === 1 ? $id : '';
    }

    /**
     * A time-ordered, collision-resistant slug: the timestamp so a listing sorts
     * chronologically by key, and random bytes so two comments in the same
     * microsecond still get distinct documents. Every character is one a content
     * slug is allowed to contain.
     */
    private function slug(\DateTimeImmutable $at): string
    {
        return $at->format('Ymd-His-u') . '-' . bin2hex(random_bytes(6));
    }

    /**
     * The request body, from a JSON payload if one was sent, otherwise the
     * form-encoded POST — so the endpoints work from `fetch(..., {body: JSON})`
     * and a plain form alike.
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
