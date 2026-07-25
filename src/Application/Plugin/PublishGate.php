<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * The one place a plugin is allowed to stop something core was about to do.
 *
 * Until this existed the plugin contract could only *add* — `api.routes`
 * collects endpoints, `web.render` transforms markup — and neither can answer
 * "may this happen at all". An editorial review workflow is the case that
 * demands it: the whole point of asking for approval is that publishing without
 * one is refused, and that refusal has to reach the act of publishing rather
 * than a single HTTP handler, or every other caller walks straight past it.
 *
 * The contract, in full:
 *
 *  - Core fires {@see HOOK} with `key`, `type`, `slug`, `locale` and the acting
 *    `user`, and a plugin implements `hook_content_before_publish(array $params)`.
 *  - A plugin refuses by returning an array whose `allowed` is exactly `false`,
 *    with `reason` explaining why in the editor's terms. The reason is what the
 *    caller is told, so it must name the state that is blocking, not merely say
 *    no.
 *  - **Anything else is silence, and silence permits.** `null`, an empty array,
 *    a missing `allowed`, a plugin that does not implement the hook at all — all
 *    mean "no opinion". Fail-open is deliberate here: a plugin that has nothing
 *    to say about publishing must never be able to stop it by accident.
 *  - **A plugin that throws has no opinion either.** The throw is logged and the
 *    publish proceeds. The alternative — a broken plugin making a site
 *    unpublishable, with the editor given a stack trace instead of a page — is
 *    strictly worse than a gate that failed to gate, because the site owner can
 *    see and fix a page that went live early and cannot fix a CMS that has
 *    stopped working. A plugin whose refusals matter must be one that works.
 *  - **The first refusal wins.** Plugins are dispatched in dependency order, so
 *    which one answers is stable rather than a race, and the editor is told one
 *    reason rather than a list they have to read to find the first problem.
 *
 * There is deliberately no way for a plugin to *force* a publish. Permitting is
 * the default, so an "allow" return would only ever override another plugin's
 * refusal, and a gate that any plugin can switch off is not a gate.
 */
final class PublishGate
{
    /** Vetoable. Fired before anything is promoted to the live site. */
    public const HOOK = 'content.before_publish';

    /**
     * Advisory. Fired after a promotion succeeded, and its results are ignored —
     * by then there is nothing left to refuse.
     *
     * It exists because "clear the review once the change is live" is only
     * correct *after* the publish landed. Doing that work inside the vetoing
     * hook would spend an approval on a publish that then failed in storage,
     * leaving a page unapproved, unpublished, and with no record of why.
     */
    public const PUBLISHED_HOOK = 'content.published';

    /**
     * The gate every caller that was handed none falls back to.
     *
     * An ambient default rather than an injected collaborator because
     * {@see \Click\Cms\Application\Content\PageService} is also constructed
     * lazily by the HTTP layer, which has no plugin manager to hand it and no
     * business acquiring one. Construction-time injection stays the preferred
     * seam and is what the tests use; this is the fallback that keeps the gate
     * from being bypassed by the path an editor actually takes.
     */
    private static ?self $ambient = null;

    /**
     * @param (\Closure(string, array<string, mixed>): mixed)|null $dispatch How
     *        to reach the plugins. Null is a gate with nothing behind it, which
     *        permits everything — the right behaviour for a CMS booted without
     *        a plugin system at all.
     */
    public function __construct(private readonly ?\Closure $dispatch = null) {}

    public static function ambient(): self
    {
        return self::$ambient ??= new self();
    }

    /** Install the process-wide gate, or pass null to forget it (tests, CLI). */
    public static function useAmbient(?self $gate): void
    {
        self::$ambient = $gate;
    }

    /**
     * Why this publish must not happen, or null to let it through.
     *
     * @param array<string, mixed> $user The account doing the publishing, so a
     *        plugin can answer questions about *who* — an approval by the person
     *        who requested it is not an approval.
     */
    public function refusalFor(ContentKey $key, array $user): ?string
    {
        $opinions = $this->ask(self::HOOK, $key, $user);

        foreach ($opinions as $plugin => $opinion) {
            $refusal = $this->refusalIn($opinion, (string) $plugin);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * Tell the plugins the promotion happened. Nothing is returned because
     * nothing a listener says can change what is already live.
     *
     * @param array<string, mixed> $user
     */
    public function announcePublished(ContentKey $key, array $user): void
    {
        $this->ask(self::PUBLISHED_HOOK, $key, $user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed> One entry per plugin that answered.
     */
    private function ask(string $hook, ContentKey $key, array $user): array
    {
        if ($this->dispatch === null) {
            return [];
        }

        try {
            $opinions = ($this->dispatch)($hook, [
                'key' => $key->toString(),
                // The parts as well as the whole, so a plugin does not have to
                // re-parse a key format it is not the owner of.
                'type' => $key->type,
                'slug' => $key->slug,
                'locale' => $key->locale->code,
                'user' => $user,
            ]);
        } catch (\Throwable $e) {
            // The dispatcher isolates each plugin, so reaching here means the
            // dispatch itself failed — a half-booted kernel, say. Same rule
            // applies: publishing survives, and the reason is on the record.
            error_log(sprintf('[%s] dispatch failed: %s', $hook, $e->getMessage()));

            return [];
        }

        return is_array($opinions) ? $opinions : [];
    }

    /**
     * Read one plugin's answer. Only an explicit `allowed: false` is a refusal;
     * every other shape is a plugin with nothing to say.
     */
    private function refusalIn(mixed $opinion, string $plugin): ?string
    {
        if (!is_array($opinion) || ($opinion['allowed'] ?? null) !== false) {
            return null;
        }

        $reason = $opinion['reason'] ?? null;
        if (is_string($reason) && trim($reason) !== '') {
            return trim($reason);
        }

        // A refusal with no reason is still a refusal — swallowing it would be
        // the silent failure this codebase keeps having to remove — but the
        // editor is at least told which plugin to go and ask.
        return "The \"{$plugin}\" plugin refused to publish this, and gave no reason.";
    }
}
