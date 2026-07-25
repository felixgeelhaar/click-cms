<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

/**
 * Authentication as one veto and four announcements.
 *
 * {@see PublishGate} and {@see ContentGate} gave plugins the write lifecycle of a
 * document. Signing in was left out of both, and it is the gap that blocks the
 * three things people most want to add to a CMS's identity layer: a second
 * factor, an audit trail shipped off the box, and an alert when an account is
 * being ground at. None of those are expressible against `api.routes` or
 * `web.render`, which can only add, and none are reachable from the content
 * hooks, because a session is not a content document.
 *
 * That absence of a storage seam is why these five hooks are fired from explicit
 * call sites in {@see \Click\Cms\Http\AuthController} rather than from a
 * decorator. The content events are safe in storage precisely because no write
 * path can miss them; here there is nothing equivalent to decorate, so the
 * honest thing is to name the one place authentication happens and fire from it.
 * `AuthController::login()` is that place — there is no second implementation of
 * the password check, and the throttle, the spray guard and the session all live
 * behind it.
 *
 * ## The hooks
 *
 * | Hook | Kind | Fires |
 * |---|---|---|
 * | {@see BEFORE_LOGIN}  | **veto**  | credentials accepted, session not yet created |
 * | {@see LOGGED_IN}     | announce  | after the session exists |
 * | {@see LOGIN_FAILED}  | announce  | after an attempt was refused |
 * | {@see LOGGED_OUT}    | announce  | after a session was destroyed |
 * | {@see LOCKED_OUT}    | announce  | the moment a failure establishes a lockout |
 *
 * ## The rules, unchanged from the two gates before it
 *
 *  - **A veto refuses by returning `['allowed' => false, 'reason' => '…']`.**
 *  - **Anything else is silence, and silence permits.** `null`, `[]`, a missing
 *    `allowed`, a plugin that never implements the method — all "no opinion".
 *  - **A plugin that throws has no opinion.** Logged, and the sign-in proceeds.
 *  - **The first refusal wins**, in dependency order.
 *  - **Announcements ignore return values.**
 *
 * Fail-open matters more here than anywhere else in the codebase, and it is not
 * a stylistic carry-over. A second-factor plugin that threw on every attempt
 * would, under fail-closed, lock every account out of the site permanently — and
 * the only way to disable a plugin is through the admin UI, which requires
 * signing in. The failure would be unrecoverable without shell access to the
 * server. Against that, the cost of failing open is one sign-in that skipped a
 * second factor by a password that was still verified, on the record in the
 * error log. A plugin whose refusals matter must be a plugin that works.
 *
 * ## Where the veto sits, and why exactly there
 *
 * {@see BEFORE_LOGIN} fires *after* the site-wide spray ceiling, *after* the
 * per-account lockout, *after* the password is verified and *after* the account
 * is confirmed active — and before the session is created. Every one of those is
 * deliberate:
 *
 *  - **After the lockout and the ceiling**, so a plugin can neither weaken them
 *    nor be reached by an attempt they have already refused. A hook that ran
 *    first would let an attacker drive plugin work — an SMS per attempt, a
 *    webhook per attempt — straight through a limit that exists to stop exactly
 *    that, and would hand plugin code the usernames of a spray in progress.
 *  - **After the password check**, so a plugin is only ever asked about an
 *    attempt that has already proved the first factor. Asking earlier means
 *    asking on every drive-by guess, and tells the plugin nothing it can act on:
 *    "should this person provide a second factor" is not answerable before the
 *    first one is known to be right.
 *  - **Before the session**, because a refusal that left somebody signed in
 *    would not be a refusal.
 *
 * There is deliberately no way for a plugin to *force* a sign-in. Permitting is
 * the default, so an "allow" return could only ever override another plugin's
 * refusal, and it would turn a bug in any plugin into an authentication bypass.
 *
 * ## What the payloads carry, and what they deliberately do not
 *
 * A password, a hash and a session identifier appear in none of them, not even
 * truncated, and that is enforced by {@see describe()}: the acting account is
 * reduced through an **allowlist**, so a field added to a user document — or to
 * the session — is invisible to plugins until somebody decides it should be
 * visible. Users are ordinary content documents in this CMS, so a payload built
 * by forwarding "the user" would be handing every plugin a bcrypt hash on every
 * sign-in.
 *
 * {@see LOGIN_FAILED} carries the attempted `username` and a `reason` drawn from
 * a closed set, and the set is exactly as coarse as the HTTP response. *"No such
 * account"* and *"wrong password"* are both {@see FAILED_CREDENTIALS}, because
 * the login flow answers both with the same `401`, words a lockout and a spray
 * refusal identically, and hashes usernames before writing them to the throttle
 * file so the CMS never keeps a list of accounts somebody has been probing. A
 * plugin that logged the difference to a file, a webhook or a dashboard would
 * undo all of that and hand an enumerator the one distinction the flow spends
 * effort hiding. A plugin that genuinely needs to know whether an account exists
 * holds a content service and can look it up under its own name — an explicit,
 * attributable act, not a fact core volunteers to every listener.
 *
 * The source address is also absent. A plugin runs inside the request and can
 * read `$_SERVER` for itself; curating an address here would make a header an
 * attacker can set look like something core vouched for.
 *
 * Nor is the request body passed to {@see BEFORE_LOGIN}. It holds the password.
 * A second-factor plugin reads its own field from the request it is already in;
 * core cannot hand over a filtered body without inventing a denylist, and a
 * denylist is one renamed field away from leaking the credential.
 *
 * ## Cost when nothing is listening
 *
 * {@see listensTo()} answers from plugin metadata already in memory, so a caller
 * can skip building a payload and skip the extra state reads that fill one — see
 * `AuthController::recordFailedLogin()`, where the lockout transition is only
 * measured if somebody is listening for it.
 */
final class AuthGate
{
    /**
     * Vetoable. The credentials are good; a plugin may still refuse until
     * something else is satisfied.
     */
    public const BEFORE_LOGIN = 'auth.before_login';

    /**
     * Advisory. Somebody is now signed in.
     *
     * Separate from {@see BEFORE_LOGIN} for the reason `content.published` is
     * separate from `content.before_publish`: work done in the veto is work spent
     * on a sign-in that may still be refused. Only this hook means it happened.
     */
    public const LOGGED_IN = 'auth.logged_in';

    /** Advisory. An attempt was refused, with a reason from the closed set below. */
    public const LOGIN_FAILED = 'auth.login_failed';

    /** Advisory. A session was destroyed. A logout by nobody is silent. */
    public const LOGGED_OUT = 'auth.logged_out';

    /**
     * Advisory. A failure just put an account over its threshold.
     *
     * Fires on the transition rather than on every refused attempt while the
     * lock holds, so an alert is one alert and not one per attempt for the next
     * quarter of an hour.
     */
    public const LOCKED_OUT = 'auth.locked_out';

    /** Wrong password, no such account, or an account with no usable hash. */
    public const FAILED_CREDENTIALS = 'invalid_credentials';

    /** The account exists and the password was right, but it is not active. */
    public const FAILED_INACTIVE = 'account_inactive';

    /** A plugin refused at {@see BEFORE_LOGIN}. */
    public const FAILED_REFUSED = 'refused_by_plugin';

    /**
     * The fields of an account a plugin is allowed to see.
     *
     * An allowlist, so `password`, `email`, and whatever a future user document
     * carries are absent by construction rather than by everyone remembering.
     * `role` is here because "require a second factor of administrators" is the
     * first thing anyone builds on this hook; `mustChangePassword` because a
     * sign-in that can only reach the password form is worth distinguishing in
     * an audit trail.
     */
    private const ACCOUNT_FIELDS = ['role', 'mustChangePassword'];

    /**
     * The gate every caller that was handed none falls back to.
     *
     * Ambient for the same reason {@see PublishGate}'s is: the controller that
     * signs people in is constructed by the kernel with no plugin manager in
     * reach, and injection at that one call site is not something this layer can
     * reach into. Explicit construction stays the preferred seam and is what the
     * tests use.
     */

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
     * The point is that a caller can skip building a payload — and, for
     * {@see LOCKED_OUT}, skip the two reads of the lockout file that establish
     * whether this failure was the one that tipped the account over.
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
     * Why this sign-in must not complete, or null to let it through.
     *
     * @param string $username The name the attempt was made under, as submitted.
     * @param array<string, mixed> $account The stored account, reduced by
     *        {@see describe()} before any plugin sees it.
     */
    public function refusalForLogin(string $username, array $account, bool $remember): ?string
    {
        if (!$this->listensTo(self::BEFORE_LOGIN)) {
            return null;
        }

        $answers = $this->ask(self::BEFORE_LOGIN, [
            'username' => $username,
            'remember' => $remember,
        ] + self::describe($account));

        foreach ($answers as $plugin => $answer) {
            if (!is_array($answer) || ($answer['allowed'] ?? null) !== false) {
                continue;
            }

            $reason = $answer['reason'] ?? null;
            $reason = is_string($reason) && trim($reason) !== ''
                ? trim($reason)
                // A refusal with no reason is still a refusal — swallowing it
                // would be the silent failure this codebase keeps removing — but
                // whoever is locked out is at least told who to ask.
                : "Signing in was refused by the \"{$plugin}\" plugin, which gave no reason.";

            // The caller is told the reason; the operator is told the reason
            // *and* which plugin gave it, for which account. A refusal is a
            // security-relevant event and the error log is the only place core
            // keeps one, so it is worth a line even though a working second
            // factor will write one on every step-up.
            error_log(sprintf(
                '[%s] plugin "%s" refused sign-in as "%s": %s',
                self::BEFORE_LOGIN,
                $plugin,
                $username,
                $reason
            ));

            return $reason;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $account
     */
    public function announceLoggedIn(string $username, array $account, bool $remember): void
    {
        if (!$this->listensTo(self::LOGGED_IN)) {
            return;
        }

        $this->ask(self::LOGGED_IN, [
            'username' => $username,
            'remember' => $remember,
        ] + self::describe($account));
    }

    /**
     * @param string $reason One of the `FAILED_*` constants. Deliberately no
     *        finer than the HTTP response — see the class docblock.
     */
    public function announceLoginFailed(string $username, string $reason): void
    {
        if (!$this->listensTo(self::LOGIN_FAILED)) {
            return;
        }

        $this->ask(self::LOGIN_FAILED, [
            'username' => $username,
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<string, mixed> $account The session's account view, reduced
     *        the same way a sign-in's is.
     */
    public function announceLoggedOut(string $username, array $account = []): void
    {
        if (!$this->listensTo(self::LOGGED_OUT)) {
            return;
        }

        $this->ask(self::LOGGED_OUT, ['username' => $username] + self::describe($account));
    }

    public function announceLockedOut(string $username, int $retryAfter): void
    {
        if (!$this->listensTo(self::LOCKED_OUT)) {
            return;
        }

        $this->ask(self::LOCKED_OUT, [
            'username' => $username,
            'retryAfter' => $retryAfter,
        ]);
    }

    /**
     * Reduce an account to what a plugin is entitled to know about it.
     *
     * Public because the payloads' honesty depends on every caller using the
     * same reduction, and a private one would be quietly re-implemented.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public static function describe(array $account): array
    {
        $facts = [];
        foreach (self::ACCOUNT_FIELDS as $field) {
            if (isset($account[$field]) && is_scalar($account[$field])) {
                $facts[$field] = $account[$field];
            }
        }

        if (array_key_exists('mustChangePassword', $facts)) {
            // Stored as a flag that is cleared by being set to null, so the
            // truthiness is the fact and the storage detail is not.
            $facts['mustChangePassword'] = (bool) $facts['mustChangePassword'];
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> One entry per plugin that answered.
     */
    private function ask(string $hook, array $payload): array
    {
        if ($this->dispatch === null) {
            return [];
        }

        try {
            $answers = ($this->dispatch)($hook, $payload);
        } catch (\Throwable $e) {
            // The dispatcher isolates each plugin, so reaching here means the
            // dispatch itself failed — a half-booted kernel, say. Same rule as
            // everywhere else: authentication survives and the reason is on
            // record. This is the last line of defence against a plugin fault
            // locking everybody out of the site.
            error_log(sprintf('[%s] dispatch failed: %s', $hook, $e->getMessage()));

            return [];
        }

        return is_array($answers) ? $answers : [];
    }
}
