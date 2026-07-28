<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

/**
 * Collaboration: presence, comments and review on a page before it goes live.
 *
 * This is the Google-Docs convention narrowed to what a CMS actually needs (see
 * docs/collaboration.md): see who else is on a page, leave review comments, ask
 * someone to approve, then publish everything at once — but deliberately *not*
 * co-editing, which is a research-grade merge problem this plugin does not
 * attempt. What is left is ordinary create/read/update plus one veto, and that
 * is all this handler does.
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
 *
 * Review is the one part of this plugin that reaches back into core, through
 * {@see \Click\Cms\Application\Plugin\PublishGate}: a workflow whose approval
 * step nothing enforces is a note-taking feature, so `content.before_publish`
 * exists to let this plugin refuse. Three rules keep that veto honest:
 *
 *  - **It is off until a site asks for it.** A CMS that suddenly cannot publish
 *    because a plugin shipped is a broken CMS. The gate reads a stored setting
 *    that defaults to off, so an installation which has not adopted review never
 *    notices this code exists.
 *  - **It only ever blocks a review that was started and not finished.** A page
 *    nobody sent for review publishes exactly as before. The gate enforces
 *    *finishing* a review, not *having* one — deciding that every page must be
 *    reviewed is an editorial policy, and this plugin does not have the standing
 *    to impose it.
 *  - **Nobody approves their own request.** That is the entire content of the
 *    word "review"; without it the feature records consent that nobody gave.
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

    /**
     * One review document per page+language, holding the current state and the
     * trail of decisions that produced it. One document rather than one per
     * event because the gate has to answer "where does this page stand" on every
     * publish, and that must be a single read rather than a scan.
     */
    private const REVIEW_TYPE = 'collaboration_review';

    /**
     * Whether the gate is armed, as a content document like everything else here
     * rather than a line in `config/core.json` — this plugin has no business
     * adding a setting to core's configuration file, and a document is editable
     * through the same API and backed up by the same backup.
     */
    private const SETTINGS_TYPE = 'collaboration_settings';
    private const SETTINGS_SLUG = 'review';

    /**
     * A record of a set of pages published together, written after the fact.
     * Nothing reads it to make a decision; it exists so "which four changes went
     * out on Tuesday" is answerable at all, which is the question the release
     * endpoint was built to make meaningful.
     */
    private const RELEASE_TYPE = 'collaboration_release';

    /* The review state machine, in full.
     *
     *      (no document) ──request──▶ IN_REVIEW ──approve──▶ APPROVED
     *                                    │  ▲                    │
     *                          changes   │  │ request            │ publish
     *                                    ▼  │                    ▼
     *                            CHANGES_REQUESTED           PUBLISHED
     *
     * plus CANCELLED, reachable from either open state, which ends the cycle
     * without recording an approval nobody gave.
     *
     * Only the two open states block a publish. APPROVED, PUBLISHED, CANCELLED
     * and no document at all are all "this page is not waiting on anybody". */
    private const STATE_IN_REVIEW = 'in_review';
    private const STATE_CHANGES_REQUESTED = 'changes_requested';
    private const STATE_APPROVED = 'approved';
    private const STATE_PUBLISHED = 'published';
    private const STATE_CANCELLED = 'cancelled';

    /** Built on demand: the release path is the only thing that needs it. */
    private ?PageService $pages = null;

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

            // Review: ask for one, record a decision, withdraw, read where a
            // page stands. The GET doubles as the list of everything open, which
            // is what a dashboard — or a future notifier — needs to read.
            'POST /api/collaboration/review' => [$this, 'handleRequestReview'],
            'GET /api/collaboration/review' => [$this, 'handleReadReview'],
            'POST /api/collaboration/review/decision' => [$this, 'handleReviewDecision'],
            'POST /api/collaboration/review/cancel' => [$this, 'handleCancelReview'],

            // Whether the gate is armed. Separate paths for reading and writing
            // because they are held to very different bars — see the handlers.
            'GET /api/collaboration/review/settings' => [$this, 'handleReadReviewSettings'],
            'POST /api/collaboration/review/settings' => [$this, 'handleWriteReviewSettings'],

            // Publishing a set of pages as one release.
            'POST /api/collaboration/release' => [$this, 'handlePublishTogether'],
        ];
    }

    /* ------------------------------------------------------------ the gate -- */

    /**
     * Core asking whether this publish may happen.
     *
     * Returns nothing at all unless there is a genuine reason to refuse: the
     * contract is fail-open, and a plugin that answers loudly when it has no
     * opinion is a plugin that will eventually block a publish it did not mean
     * to. Reads only, and never writes — a question must not have side effects,
     * because core may ask it and then fail for an unrelated reason.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function hook_content_before_publish(array $params): ?array
    {
        // Pages only. Collections and anything else publishable have their own
        // editorial life and no review documents; refusing them on the strength
        // of a page's state would be nonsense.
        if (($params['type'] ?? null) !== 'page') {
            return null;
        }

        $page = $this->safePageRef((string) ($params['slug'] ?? ''));
        if ($page === '') {
            return null;
        }

        $refusal = $this->reviewRefusalFor($page, $this->safeLocaleRef((string) ($params['locale'] ?? '')));

        return $refusal === null ? null : ['allowed' => false, 'reason' => $refusal];
    }

    /**
     * Core telling this plugin a page went live.
     *
     * The reviewed change is now what the public reads, so the review is over.
     * The document is moved to a terminal state rather than deleted: it is the
     * only record that what went live had been approved and by whom, and a
     * workflow that erases its own evidence on success is worth very little to
     * whoever has to reconstruct a bad release afterwards.
     *
     * @param array<string, mixed> $params
     */
    public function hook_content_published(array $params): void
    {
        if (($params['type'] ?? null) !== 'page') {
            return;
        }

        $page = $this->safePageRef((string) ($params['slug'] ?? ''));
        if ($page === '') {
            return;
        }

        $this->closeReview(
            $page,
            $this->safeLocaleRef((string) ($params['locale'] ?? '')),
            is_array($params['user'] ?? null) ? $params['user'] : []
        );
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

    /* -------------------------------------------------------------- review -- */

    /**
     * Ask for a review of a page's working copy.
     *
     * A named reviewer and a note are both optional. The reviewer is a hint, not
     * a lock: naming Hanna does not stop Ada approving it, because a review that
     * blocks on one person being available is a review that gets bypassed. It is
     * recorded so a notifier has somebody to write to.
     *
     * @return array<string, mixed>
     */
    public function handleRequestReview(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $input = $this->readBody();
        $page = $this->safePageRef($this->stringField($input, 'page'));
        $locale = $this->reviewLocale($this->safeLocaleRef($this->stringField($input, 'locale')));

        if ($page === '') {
            return ['status' => 400, 'error' => 'A page is required to request a review.'];
        }

        $existing = $this->reviewFor($page, $locale);
        if (($existing['state'] ?? '') === self::STATE_IN_REVIEW) {
            // Refused rather than quietly overwritten. A second request would
            // replace the first request's note and named reviewer, and the
            // person waiting on it would never learn their request was gone.
            return ['status' => 409, 'error' => 'This page is already waiting for review.'];
        }

        $now = $this->timestamp();
        $note = trim($this->stringField($input, 'note'));

        return ['data' => $this->writeReview($page, $locale, [
            'page' => $page,
            'locale' => $locale,
            'state' => self::STATE_IN_REVIEW,
            'requestedBy' => $this->identityKey($user),
            'requestedByName' => $this->displayName($user),
            'requestedAt' => $now,
            // Editor-typed text, stored exactly as given. Same rule as a comment
            // body: escaping is the display layer's job.
            'note' => $note,
            'reviewer' => $this->safeId($this->stringField($input, 'reviewer')),
            // A new cycle, so last cycle's decision must not be left standing —
            // an approval carried over would let a stale one unlock a page that
            // has changed since.
            'decidedBy' => null,
            'decidedByName' => null,
            'decidedAt' => null,
            'decisionNote' => null,
            'history' => $this->appendHistory($existing, [
                'state' => self::STATE_IN_REVIEW,
                'by' => $this->identityKey($user),
                'byName' => $this->displayName($user),
                'at' => $now,
                'note' => $note,
            ]),
        ])];
    }

    /**
     * Record a reviewer's decision: approve, or ask for changes.
     *
     * @return array<string, mixed>
     */
    public function handleReviewDecision(): array
    {
        $user = $this->currentUser();
        if (!$this->mayReview($user)) {
            return $this->forbidden();
        }

        $input = $this->readBody();
        $page = $this->safePageRef($this->stringField($input, 'page'));
        $locale = $this->reviewLocale($this->safeLocaleRef($this->stringField($input, 'locale')));
        $decision = strtolower(trim($this->stringField($input, 'decision')));

        if ($page === '') {
            return ['status' => 400, 'error' => 'A page is required to record a decision.'];
        }
        if ($decision !== 'approve' && $decision !== 'changes') {
            return ['status' => 400, 'error' => 'A decision must be either "approve" or "changes".'];
        }

        $review = $this->reviewFor($page, $locale);
        if (($review['state'] ?? '') !== self::STATE_IN_REVIEW) {
            // Deciding on a page nobody asked about would create an approval out
            // of nothing, which is exactly what the gate must not let through.
            return ['status' => 409, 'error' => 'This page is not waiting for review.'];
        }

        // The whole point of the feature. Not overridable by an administrator:
        // an override exists to be used, and once it is used routinely the
        // recorded approvals mean nothing. A site with one person who publishes
        // their own work should leave the gate off — that is what the off switch
        // is for — and an administrator who needs to unstick a real review can
        // cancel it, which records "cancelled by X" rather than an approval
        // nobody gave. Asking for *changes* on your own request is allowed: that
        // is finding more work to do, not consenting to your own.
        if ($decision === 'approve' && ($review['requestedBy'] ?? null) === $this->identityKey($user)) {
            return [
                'status' => 403,
                'error' => 'You cannot approve your own request for review.',
            ];
        }

        $now = $this->timestamp();
        $note = trim($this->stringField($input, 'note'));
        $state = $decision === 'approve' ? self::STATE_APPROVED : self::STATE_CHANGES_REQUESTED;

        $review['state'] = $state;
        $review['decidedBy'] = $this->identityKey($user);
        $review['decidedByName'] = $this->displayName($user);
        $review['decidedAt'] = $now;
        $review['decisionNote'] = $note;
        $review['history'] = $this->appendHistory($review, [
            'state' => $state,
            'by' => $this->identityKey($user),
            'byName' => $this->displayName($user),
            'at' => $now,
            'note' => $note,
        ]);

        return ['data' => $this->writeReview($page, $locale, $review)];
    }

    /**
     * End a review without deciding it.
     *
     * The requester may withdraw their own; an administrator may cancel
     * anybody's, because a page stuck behind a reviewer who has left the company
     * is a site administration problem and there has to be an honest way out of
     * it. Honest is the operative word: this records a cancellation, so nothing
     * afterwards can be mistaken for an approval.
     *
     * @return array<string, mixed>
     */
    public function handleCancelReview(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $input = $this->readBody();
        $page = $this->safePageRef($this->stringField($input, 'page'));
        $locale = $this->reviewLocale($this->safeLocaleRef($this->stringField($input, 'locale')));

        if ($page === '') {
            return ['status' => 400, 'error' => 'A page is required to cancel a review.'];
        }

        $review = $this->reviewFor($page, $locale);
        if (!$this->isOpen($review['state'] ?? '')) {
            return ['status' => 409, 'error' => 'There is no open review on this page.'];
        }

        $isRequester = ($review['requestedBy'] ?? null) === $this->identityKey($user);
        if (!$isRequester && !$this->isAdministrator($user)) {
            return [
                'status' => 403,
                'error' => 'Only the person who asked for this review, or an administrator, can cancel it.',
            ];
        }

        $now = $this->timestamp();
        $note = trim($this->stringField($input, 'note'));

        $review['state'] = self::STATE_CANCELLED;
        $review['history'] = $this->appendHistory($review, [
            'state' => self::STATE_CANCELLED,
            'by' => $this->identityKey($user),
            'byName' => $this->displayName($user),
            'at' => $now,
            'note' => $note,
        ]);

        return ['data' => $this->writeReview($page, $locale, $review)];
    }

    /**
     * Where a page stands, or — with no page named — every review still open.
     *
     * The list exists so something can be built on top of this without adding an
     * endpoint: a dashboard of what is waiting, or the notifier this plugin
     * deliberately does not contain. Reading is held to the same bar as the rest
     * of collaboration, because an open review names who asked, who was asked,
     * and what they said about unpublished work.
     *
     * @return array<string, mixed>
     */
    public function handleReadReview(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        $page = $this->safePageRef((string) ($_GET['page'] ?? ''));

        if ($page === '') {
            return ['data' => ['open' => $this->openReviews()]];
        }

        return ['data' => $this->reviewFor(
            $page,
            $this->reviewLocale($this->safeLocaleRef((string) ($_GET['locale'] ?? '')))
        )];
    }

    /**
     * Whether the gate is armed. Readable by any collaborator, because an editor
     * about to press Publish is entitled to know whether it can refuse them.
     *
     * @return array<string, mixed>
     */
    public function handleReadReviewSettings(): array
    {
        $user = $this->currentUser();
        if (!$this->mayCollaborate($user)) {
            return $this->forbidden();
        }

        return ['data' => ['enabled' => $this->reviewGateEnabled()]];
    }

    /**
     * Arm or disarm the gate.
     *
     * Administrators only, and this is the one place in the plugin held to a
     * higher bar than editing content. An editor who could switch the gate off
     * could publish their own unapproved page by turning off the thing that was
     * about to stop them, which would make every approval in the system
     * decorative.
     *
     * @return array<string, mixed>
     */
    public function handleWriteReviewSettings(): array
    {
        $user = $this->currentUser();
        if ($user === null || !$this->isAdministrator($user)) {
            return [
                'status' => 403,
                'error' => 'Only an administrator can change whether review is required before publishing.',
            ];
        }

        $input = $this->readBody();
        if (!array_key_exists('enabled', $input)) {
            return ['status' => 400, 'error' => 'A value for "enabled" is required.'];
        }

        // Anything that is not an affirmative is off, so a malformed payload
        // disarms rather than arms: failing to a state where the site can still
        // publish beats failing to one where it cannot.
        $enabled = in_array($input['enabled'], [true, 1, '1', 'true', 'yes', 'on'], true);

        $contentService = $this->pluginManager->getContentService();
        $key = ContentKey::fromString(self::SETTINGS_TYPE . ':' . self::SETTINGS_SLUG);
        $existing = $contentService->get($key);

        $data = [
            'enabled' => $enabled,
            'updatedBy' => $this->identityKey($user),
            'updatedAt' => $this->timestamp(),
        ];

        $contentService->save(
            $existing !== null ? $existing->update($data) : Content::create($key, $data)
        );

        return ['data' => ['enabled' => $enabled]];
    }

    /**
     * Why this page must not be published, or null.
     *
     * The single rule the gate rests on, kept in one method because two callers
     * ask it — core through the hook, and the release endpoint, which asks about
     * every page in a set before publishing any of them.
     */
    public function reviewRefusalFor(string $page, string $locale): ?string
    {
        if (!$this->reviewGateEnabled()) {
            return null;
        }

        $state = $this->reviewFor($page, $this->reviewLocale($locale))['state'] ?? '';

        // The reason names the state, because "not allowed" tells an editor
        // nothing about what to do next and this tells them exactly who they are
        // waiting for.
        return match ($state) {
            self::STATE_IN_REVIEW
                => 'This page is waiting for review and has not been approved yet.',
            self::STATE_CHANGES_REQUESTED
                => 'A reviewer asked for changes on this page. It has to be approved before it can be published.',
            default => null,
        };
    }

    /**
     * Publish a set of pages as one release.
     *
     * The reason this exists is in `collaboration.md`: publication is per
     * language and per page, so a change spanning four documents goes live in
     * four separate acts, and between the first and the last the site is
     * half-updated. This narrows that window to one request and — crucially —
     * refuses the whole set rather than publishing the part of it that happens
     * to be approved. A release that goes out half-approved is the failure this
     * was built to prevent, not an acceptable degradation of it.
     *
     * @return array<string, mixed>
     */
    public function handlePublishTogether(): array
    {
        $user = $this->currentUser();
        if (!$this->mayReview($user)) {
            return $this->forbidden();
        }

        $input = $this->readBody();
        $requested = $this->releaseTargets($input);

        if ($requested['error'] !== null) {
            return ['status' => 400, 'error' => $requested['error']];
        }

        $pages = $this->pageService();
        if ($pages === null) {
            return ['status' => 500, 'error' => 'Publishing is not available to this plugin.'];
        }

        // Pass one asks every question that can be asked without changing
        // anything. All of them, for all pages, before a single publish — the
        // whole value of a release is that it is refused as a unit, and a
        // pre-flight that stopped at the first problem would report one reason
        // when the editor needs the list.
        $refusals = [];
        foreach ($requested['targets'] as $target) {
            $reason = $this->releaseObjectionTo($target, $user);
            if ($reason !== null) {
                $refusals[] = ['page' => $target['page'], 'locale' => $target['locale'], 'reason' => $reason];
            }
        }

        if ($refusals !== []) {
            return [
                'status' => 409,
                'error' => 'This release was not published because some of its pages are not ready.',
                'data' => ['refused' => $refusals, 'published' => []],
            ];
        }

        // Pass two publishes, through the same service and therefore the same
        // gate a single publish goes through — so another plugin's veto is not
        // bypassed by calling this endpoint instead.
        $published = [];
        $failed = [];
        foreach ($requested['targets'] as $target) {
            $result = $pages->publish(
                $target['page'],
                $user,
                $target['locale'] !== '' ? $target['locale'] : null
            );

            if ($result['error'] !== null) {
                $failed[] = ['page' => $target['page'], 'locale' => $target['locale'], 'reason' => $result['error']];
                continue;
            }

            $published[] = ['page' => $target['page'], 'locale' => $target['locale']];
        }

        $release = $this->recordRelease($user, $published, $failed);

        if ($failed !== []) {
            // Nothing is rolled back, and that is a decision rather than an
            // omission. Every page in this set passed the pre-flight, so a
            // failure here is storage failing mid-write; un-publishing the pages
            // that succeeded would take down pages that were live and correct
            // before the release began, turning a partial update into an outage.
            // What the editor gets instead is the exact list of what did and did
            // not go out, so the remainder can be published again.
            return [
                'status' => 500,
                'error' => 'Part of this release could not be published.',
                'data' => ['release' => $release, 'published' => $published, 'failed' => $failed],
            ];
        }

        return ['data' => ['release' => $release, 'published' => $published, 'failed' => []]];
    }

    /**
     * Why this one page cannot go out in a release, or null.
     *
     * @param array{page: string, locale: string} $target
     * @param array<string, mixed> $user
     */
    private function releaseObjectionTo(array $target, array $user): ?string
    {
        $contentService = $this->pluginManager->getContentService();
        $draft = $contentService instanceof ContentService
            ? $contentService->draftPage($target['page'], $target['locale'] !== '' ? $target['locale'] : null)
            : null;

        if ($draft === null) {
            return 'There is no such page in this language.';
        }

        // The same ownership rule a single publish is held to. A release must not
        // be a way to put somebody else's draft live that the publish endpoint
        // would have refused.
        $role = Role::fromName(is_string($user['role'] ?? null) ? $user['role'] : null);
        if (!$role->canPublishContentOwnedBy(
            is_string($draft->data['owner'] ?? null) ? $draft->data['owner'] : null,
            is_string($user['username'] ?? null) ? $user['username'] : null
        )) {
            return 'You do not have permission to publish this page.';
        }

        // This plugin's own rule, asked directly rather than through the hook:
        // it owns the review state and should not depend on having been wired
        // back to itself to know its own mind.
        $refusal = $this->reviewRefusalFor($target['page'], $target['locale']);
        if ($refusal !== null) {
            return $refusal;
        }

        // And then everybody else's. Asked through the process-wide gate so a
        // second gating plugin — an embargo, a legal hold — is not bypassed by
        // publishing as a release rather than one page at a time.
        return PublishGate::ambient()->refusalFor(
            ContentKey::page($target['page'], $target['locale'] !== '' ? $target['locale'] : null),
            $user
        );
    }

    /**
     * The pages a release names, normalised and de-duplicated.
     *
     * Accepts `{"pages": [{"page": "home", "locale": "en"}, ...]}` and the
     * shorthand `{"pages": ["home", "about"], "locale": "en"}`, because a release
     * of four translations and a release of four pages in one language are both
     * ordinary and neither should have to be written the long way.
     *
     * @param array<string, mixed> $input
     * @return array{targets: list<array{page: string, locale: string}>, error: ?string}
     */
    private function releaseTargets(array $input): array
    {
        $pages = $input['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            return ['targets' => [], 'error' => 'A release needs at least one page.'];
        }

        $fallbackLocale = $this->reviewLocale($this->safeLocaleRef($this->stringField($input, 'locale')));

        $targets = [];
        $seen = [];
        foreach ($pages as $entry) {
            if (is_string($entry)) {
                $entry = ['page' => $entry];
            }
            if (!is_array($entry)) {
                return ['targets' => [], 'error' => 'Every page in a release must name a page.'];
            }

            $page = $this->safePageRef($this->stringField($entry, 'page'));
            if ($page === '') {
                return ['targets' => [], 'error' => 'Every page in a release must name a page.'];
            }

            $locale = $this->safeLocaleRef($this->stringField($entry, 'locale'));
            $locale = $locale !== '' ? $this->reviewLocale($locale) : $fallbackLocale;

            // Naming the same document twice is a mistake in the caller, not an
            // instruction to publish it twice.
            $id = $page . ':' . $locale;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $targets[] = ['page' => $page, 'locale' => $locale];
        }

        return ['targets' => $targets, 'error' => null];
    }

    /**
     * @param array<string, mixed> $user
     * @param list<array{page: string, locale: string}> $published
     * @param list<array<string, mixed>> $failed
     * @return array<string, mixed>
     */
    private function recordRelease(array $user, array $published, array $failed): array
    {
        $contentService = $this->pluginManager->getContentService();

        $now = new \DateTimeImmutable();
        $data = [
            'releasedBy' => $this->identityKey($user),
            'releasedByName' => $this->displayName($user),
            'releasedAt' => $now->format('Y-m-d\TH:i:s.uP'),
            'published' => $published,
            'failed' => $failed,
        ];

        $contentService->save(Content::create(
            ContentKey::fromString(self::RELEASE_TYPE . ':' . $this->slug($now)),
            $data
        ));

        return $data;
    }

    /* ------------------------------------------------------- review state -- */

    /**
     * A page's review record, or the "nothing is happening" shape.
     *
     * Never null, so every caller reads `state` the same way and none of them
     * has to remember that an absent document and a finished review mean the
     * same thing to the gate.
     *
     * @return array<string, mixed>
     */
    public function reviewFor(string $page, string $locale): array
    {
        $contentService = $this->pluginManager->getContentService();
        $document = $contentService?->get($this->reviewKey($page, $locale));

        if ($document === null) {
            return $this->emptyReview($page, $locale);
        }

        return $this->presentReview($document->data, $page, $locale);
    }

    /**
     * Every review still waiting on somebody, newest request first.
     *
     * @return list<array<string, mixed>>
     */
    private function openReviews(): array
    {
        $contentService = $this->pluginManager->getContentService();
        if ($contentService === null) {
            return [];
        }

        $out = [];
        foreach ($contentService->all(self::REVIEW_TYPE) as $document) {
            $data = $document->data;
            if (!$this->isOpen(is_string($data['state'] ?? null) ? $data['state'] : '')) {
                continue;
            }
            $out[] = $this->presentReview(
                $data,
                is_string($data['page'] ?? null) ? $data['page'] : '',
                is_string($data['locale'] ?? null) ? $data['locale'] : ''
            );
        }

        usort($out, static fn (array $a, array $b): int => strcmp(
            (string) $b['requestedAt'],
            (string) $a['requestedAt']
        ));

        return $out;
    }

    /**
     * Move a page's review to its terminal published state, if there is one to
     * move. Called from the after-publish hook, so it must be silent about a
     * page that was never in review — most publishes are.
     *
     * @param array<string, mixed> $user
     */
    private function closeReview(string $page, string $locale, array $user): void
    {
        $locale = $this->reviewLocale($locale);
        $review = $this->reviewFor($page, $locale);
        $state = $review['state'] ?? '';

        if ($state === '' || $state === self::STATE_PUBLISHED) {
            return;
        }

        $review['state'] = self::STATE_PUBLISHED;
        $review['history'] = $this->appendHistory($review, [
            'state' => self::STATE_PUBLISHED,
            'by' => $user === [] ? '' : $this->identityKey($user),
            'byName' => $user === [] ? '' : $this->displayName($user),
            'at' => $this->timestamp(),
            'note' => '',
        ]);

        $this->writeReview($page, $locale, $review);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function writeReview(string $page, string $locale, array $data): array
    {
        $contentService = $this->pluginManager->getContentService();
        $key = $this->reviewKey($page, $locale);
        $existing = $contentService->get($key);

        $data['page'] = $page;
        $data['locale'] = $locale;

        // Derived, never stored: `open` is a reading of `state`, and keeping a
        // copy of it on disk would be a second answer able to disagree with the
        // first — the same two-sources-of-truth mistake draft-and-publish
        // removed when it deleted the `status` field.
        unset($data['open']);

        $contentService->save(
            $existing !== null ? $existing->update($data) : Content::create($key, $data)
        );

        return $this->presentReview($data, $page, $locale);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    private function appendHistory(array $data, array $entry): array
    {
        $history = $data['history'] ?? null;
        $history = is_array($history) ? array_values($history) : [];
        $history[] = $entry;

        return $history;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function presentReview(array $data, string $page, string $locale): array
    {
        $string = static fn (string $field): ?string
            => is_string($data[$field] ?? null) ? $data[$field] : null;

        $state = $string('state') ?? '';

        return [
            'page' => $page,
            'locale' => $locale,
            // The empty string, not a made-up state name: "there is no review"
            // is the absence of one, and inventing a word for it would give the
            // gate a fifth thing to have an opinion about.
            'state' => $state,
            'open' => $this->isOpen($state),
            'requestedBy' => $string('requestedBy') ?? '',
            'requestedByName' => $string('requestedByName') ?? '',
            'requestedAt' => $string('requestedAt') ?? '',
            'note' => $string('note') ?? '',
            'reviewer' => $string('reviewer') ?? '',
            'decidedBy' => $string('decidedBy'),
            'decidedByName' => $string('decidedByName'),
            'decidedAt' => $string('decidedAt'),
            'decisionNote' => $string('decisionNote'),
            'history' => is_array($data['history'] ?? null) ? array_values($data['history']) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReview(string $page, string $locale): array
    {
        return $this->presentReview([], $page, $locale);
    }

    /** The two states that are waiting on somebody, and therefore block. */
    private function isOpen(string $state): bool
    {
        return $state === self::STATE_IN_REVIEW || $state === self::STATE_CHANGES_REQUESTED;
    }

    private function reviewGateEnabled(): bool
    {
        $contentService = $this->pluginManager->getContentService();
        $settings = $contentService?->get(
            ContentKey::fromString(self::SETTINGS_TYPE . ':' . self::SETTINGS_SLUG)
        );

        // Off unless a document says otherwise, so a site that installed this
        // plugin for presence and comments never discovers it cannot publish.
        return ($settings?->data['enabled'] ?? false) === true;
    }

    private function reviewKey(string $page, string $locale): ContentKey
    {
        $slug = $page . ($locale !== '' ? '.' . $locale : '');

        return ContentKey::fromString(self::REVIEW_TYPE . ':' . $slug);
    }

    /**
     * The language a review is filed under.
     *
     * Unlike presence and comments, this must agree exactly with what core hands
     * the gate — and core always names a concrete language, because a key always
     * has one. A review requested with the locale left off would otherwise be
     * filed under no language and never found when the default language's page
     * was published.
     */
    private function reviewLocale(string $locale): string
    {
        if ($locale !== '') {
            return $locale;
        }

        $contentService = $this->pluginManager->getContentService();

        return $contentService instanceof ContentService
            ? $contentService->defaultLocale()->code
            : '';
    }

    /**
     * The page service this plugin publishes through.
     *
     * Given no configured locale list, which means "no restriction": the pages
     * in a release already exist, so the language has been accepted once
     * already, and a plugin second-guessing the site's language configuration
     * would refuse a page core is perfectly willing to publish.
     */
    private function pageService(): ?PageService
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $contentService = $this->pluginManager->getContentService();
        if (!$contentService instanceof ContentService || !class_exists(JsonSectionTypeRepository::class)) {
            return null;
        }

        return $this->pages = new PageService(
            $contentService,
            new JsonSectionTypeRepository($this->sectionTypesPath())
        );
    }

    /**
     * Where this site's section types are declared.
     *
     * A site's own `config/sections/` when it has one, the installation's
     * otherwise — the same fallback the kernel applies, mirrored here because
     * a plugin resolving schema differently from the core that validates
     * against it would render pages the validator would refuse.
     */
    private function sectionTypesPath(): string
    {
        $own = $this->pluginManager->getSiteRoot() . '/config/sections';

        return is_dir($own) ? $own : $this->pluginManager->getBasePath() . '/config/sections';
    }

    private function timestamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
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

        $sessions = new SessionStore($this->pluginManager->getDataPath() . '/sessions');

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
     * Whether the caller may decide a review, or publish a release.
     *
     * Stricter than {@see mayCollaborate()} by one capability, and deliberately
     * so: an approval is a licence to publish, and a release *is* publishing.
     * The right bar is therefore "could this person have published it
     * themselves" — anything less would let an account approve a change into
     * production that it was not trusted to put there directly.
     *
     * In the shipped role map editors and administrators hold both capabilities
     * and nobody else holds either, so today this refuses exactly who
     * {@see mayCollaborate()} refuses. It is written as two questions anyway,
     * because the role map is data and the reason each half is required outlives
     * the current values.
     *
     * @param array<string, mixed>|null $user
     */
    private function mayReview(?array $user): bool
    {
        return $this->mayCollaborate($user)
            && Role::fromName(is_string($user['role'] ?? null) ? $user['role'] : null)
                ->can(Capability::PublishContent);
    }

    /**
     * Whether the account administers the site rather than merely edits it.
     *
     * Asked as a capability rather than by comparing the role name, so a site
     * that reshapes its roles keeps one answer to the question instead of two
     * that can drift apart. `settings.manage` is the capability, because both
     * things this guards — arming the gate, and cancelling somebody else's
     * review — are decisions about how the site is run, not about a page.
     *
     * @param array<string, mixed> $user
     */
    private function isAdministrator(array $user): bool
    {
        return Role::fromName(is_string($user['role'] ?? null) ? $user['role'] : null)
            ->can(Capability::ManageSettings);
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
