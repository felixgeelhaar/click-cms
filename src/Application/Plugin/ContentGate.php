<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * The write lifecycle of a content document, expressed as five hooks.
 *
 * {@see PublishGate} opened the door: it proved a plugin can be asked "may this
 * happen" and told "it happened", and it fixed the shape of both answers. This
 * is the same contract applied to the operation *underneath* publishing — the
 * write itself — because publication is only one of the things that happens to a
 * document, and a plugin that can only see promotions is blind to the save that
 * produced them, the delete that removed them and the takedown that reversed
 * them.
 *
 * What that unlocks, concretely: a search index that stays current without
 * re-crawling; a webhook that tells a static front end to rebuild one page; an
 * audit shipper that forwards changes off the box; a validator that refuses a
 * document missing a field its own schema requires. None of those are
 * expressible against `api.routes` and `web.render`, which can only add.
 *
 * ## The hooks
 *
 * | Hook | Kind | Fires |
 * |---|---|---|
 * | {@see BEFORE_SAVE}   | **veto**  | before a document reaches storage |
 * | {@see SAVED}         | announce  | after a write landed |
 * | {@see BEFORE_DELETE} | **veto**  | before a document is removed |
 * | {@see DELETED}       | announce  | after a document was actually removed |
 * | {@see UNPUBLISHED}   | announce  | after a live document was actually taken down |
 *
 * `content.published` is deliberately absent: {@see PublishGate} already fires
 * it from the service layer, where the acting user is known, and firing it a
 * second time from storage would double every listener's bookkeeping.
 *
 * ## The rules, unchanged from PublishGate
 *
 *  - **A veto refuses by returning `['allowed' => false, 'reason' => '…']`.**
 *    The reason reaches the caller, so it must name what is wrong with the
 *    document, not merely say no.
 *  - **Anything else is silence, and silence permits.** `null`, `[]`, a missing
 *    `allowed`, a plugin that never implements the method — all mean "no
 *    opinion". A plugin with nothing to say about a save must not be able to
 *    stop one by accident.
 *  - **A plugin that throws has no opinion.** Logged, and the write proceeds.
 *    A broken extension must not be able to make a site unwritable, and must
 *    not be able to swallow another plugin's refusal — hence isolated dispatch.
 *  - **The first refusal wins**, in dependency order, so which plugin answers
 *    is stable rather than a race.
 *  - **Announcements ignore return values.** There is nothing left to decide.
 *
 * ## What the payload carries, and what it deliberately does not
 *
 * Identity, not content: `key`, `type`, `slug`, `locale`, the acting `user`, and
 * for a save whether the document is new and why it was written. The document's
 * `data` is **not** passed.
 *
 * That is a security decision before it is a design one. Users are stored as
 * ordinary content documents, so a payload that carried `data` would hand every
 * plugin the password hash of every account on every password change — and
 * would keep doing so for whatever secret a future content type happens to
 * hold. A plugin that genuinely needs the body has a content service and can
 * read the key it was just given, under its own name, for the one document it
 * cares about. It is also why {@see identify()} allowlists the actor's fields
 * rather than forwarding the session's idea of a user: adding a field to a
 * session must not silently widen what plugins can see.
 *
 * ## Cost when nothing is listening
 *
 * {@see listensTo()} answers from plugin metadata already in memory — no
 * bootstrap loaded, no file touched — so a site with no listeners pays one
 * cached array lookup per write and never builds a payload at all. The callers
 * are expected to ask first; see {@see \Click\Cms\Infrastructure\Plugin\ContentEventStorage}.
 */
final class ContentGate
{
    /** Vetoable. A plugin may refuse a document before it is written. */
    public const BEFORE_SAVE = 'content.before_save';

    /**
     * Advisory. A write landed.
     *
     * Separate from {@see BEFORE_SAVE} for the same reason `content.published`
     * is separate from `content.before_publish`: work done in the veto is work
     * spent on a write that may still be refused by a layer beneath it or fail
     * on disk. Only this hook means it happened.
     */
    public const SAVED = 'content.saved';

    /** Vetoable. A plugin may refuse a removal — "other pages still link here". */
    public const BEFORE_DELETE = 'content.before_delete';

    /** Advisory. A document was actually removed; a delete that found nothing is silent. */
    public const DELETED = 'content.deleted';

    /** Advisory. A live document was actually taken down. */
    public const UNPUBLISHED = 'content.unpublished';

    /**
     * The fields of the acting account a plugin is allowed to see.
     *
     * An allowlist rather than a denylist, so a field added to the session is
     * invisible here until someone decides it should be visible.
     */
    private const ACTOR_FIELDS = ['username', 'role'];

    /**
     * @param (\Closure(string, array<string, mixed>): mixed)|null $dispatch How to
     *        reach the plugins. Must isolate per plugin. Null is a gate with
     *        nothing behind it: it permits everything and announces nothing,
     *        which is correct for a CMS booted without a plugin system.
     * @param (\Closure(string): bool)|null $listens Whether any active plugin
     *        declares the hook. Null means "assume yes when a dispatcher
     *        exists", which is correct but pays for payloads nobody reads.
     */
    public function __construct(
        private readonly ?\Closure $dispatch = null,
        private readonly ?\Closure $listens = null,
    ) {
    }

    /**
     * Whether firing this hook could reach anybody.
     *
     * The whole point is that a caller can skip building a payload. It answers
     * from declared metadata, so a plugin that declares a hook it never
     * implements still counts as a listener — the dispatch then finds no method
     * and returns nothing, which costs a method_exists and is the right trade
     * against loading every bootstrap to find out.
     */
    public function listensTo(string $hook): bool
    {
        if ($this->dispatch === null) {
            return false;
        }

        if ($this->listens === null) {
            return true;
        }

        return (bool) ($this->listens)($hook);
    }

    /**
     * Why this document must not be written, or null to let it through.
     *
     * @param array<string, mixed> $user The acting account, redacted by {@see identify()}.
     * @param array<string, mixed> $facts Extra context for this write — `created`
     *        and `reason`. Kept out of the constructor so a caller that cannot
     *        cheaply establish them can omit them rather than guess.
     */
    public function refusalForSave(ContentKey $key, array $user, array $facts = []): ?string
    {
        return $this->firstRefusalIn($this->ask(self::BEFORE_SAVE, $key, $user, $facts));
    }

    /**
     * Why this document must not be removed, or null to let it through.
     *
     * @param array<string, mixed> $user
     */
    public function refusalForDelete(ContentKey $key, array $user): ?string
    {
        return $this->firstRefusalIn($this->ask(self::BEFORE_DELETE, $key, $user));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $facts
     */
    public function announceSaved(ContentKey $key, array $user, array $facts = []): void
    {
        $this->ask(self::SAVED, $key, $user, $facts);
    }

    /** @param array<string, mixed> $user */
    public function announceDeleted(ContentKey $key, array $user): void
    {
        $this->ask(self::DELETED, $key, $user);
    }

    /** @param array<string, mixed> $user */
    public function announceUnpublished(ContentKey $key, array $user): void
    {
        $this->ask(self::UNPUBLISHED, $key, $user);
    }

    /**
     * Reduce an acting account to what a plugin is entitled to know about it.
     *
     * Public because the payload's honesty depends on every caller using the
     * same reduction, and a private one would be quietly re-implemented.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function identify(array $user): array
    {
        $actor = [];
        foreach (self::ACTOR_FIELDS as $field) {
            if (isset($user[$field]) && is_scalar($user[$field])) {
                $actor[$field] = $user[$field];
            }
        }

        return $actor;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $facts
     * @return array<string, mixed> One entry per plugin that answered.
     */
    private function ask(string $hook, ContentKey $key, array $user, array $facts = []): array
    {
        if ($this->dispatch === null) {
            return [];
        }

        try {
            $answers = ($this->dispatch)($hook, $facts + [
                'key' => $key->toString(),
                // The parts as well as the whole, so a plugin does not have to
                // re-parse a key format it does not own.
                'type' => $key->type,
                'slug' => $key->slug,
                'locale' => $key->locale->code,
                'user' => self::identify($user),
            ]);
        } catch (\Throwable $e) {
            // The dispatcher isolates each plugin, so reaching here means the
            // dispatch itself failed — a half-booted kernel, say. Same rule as
            // everywhere else: the write survives and the reason is on record.
            error_log(sprintf('[%s] dispatch failed: %s', $hook, $e->getMessage()));

            return [];
        }

        return is_array($answers) ? $answers : [];
    }

    /** @param array<string, mixed> $answers */
    private function firstRefusalIn(array $answers): ?string
    {
        foreach ($answers as $plugin => $answer) {
            if (!is_array($answer) || ($answer['allowed'] ?? null) !== false) {
                continue;
            }

            $reason = $answer['reason'] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                return trim($reason);
            }

            // A refusal with no reason is still a refusal — swallowing it would
            // be the silent failure this codebase keeps removing — but whoever
            // is looking at it is at least told which plugin to go and ask.
            return "The \"{$plugin}\" plugin refused this change, and gave no reason.";
        }

        return null;
    }
}
