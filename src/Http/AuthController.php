<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Authentication\CsrfGuard;
use Click\Cms\Application\Authentication\LoginSprayGuard;
use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Authentication\Oidc\OidcSettings;
use Click\Cms\Application\Authentication\TwoFactorService;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\AuthGate;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Everything that authenticates a person: logging in and out, changing a
 * password, reporting who is signed in, throttling failed attempts, and seeding
 * the one account an installer starts with.
 *
 * This is a bounded context — identity — that had grown up inside the HTTP
 * kernel, some three hundred lines of session-mutating, password-hashing code
 * next to routing and rendering. Pulled out here, it holds its own
 * collaborators and can be reasoned about and tested as the one thing it is.
 * The kernel keeps a single {@see SessionStore} and hands it in, so the session
 * a login writes is the same one every other part of a request reads.
 *
 * Every method returns a response array to hand back, or mutates the session
 * through the store — the controller speaks the same shape the rest of the API
 * does and nothing here touches the wire directly except reading the request
 * body, which is what an HTTP controller is for.
 *
 * It is also where the authentication hooks fire, through {@see AuthGate}. That
 * makes this the one part of the plugin surface with explicit call sites rather
 * than a storage decorator behind it, and the reason is in the gate's docblock:
 * a session is not a content document, so there is nothing to decorate. What
 * makes the call sites trustworthy instead is that there is only one of them —
 * one password check, one place a session is created, one place a failure is
 * counted — and they are all in this file.
 */
final class AuthController
{
    /**
     * The site-wide failure ceiling, built once per request from configuration.
     *
     * Not a constructor argument: the throttle already knows where login state
     * lives on this installation and this controller already holds the
     * configuration, so the guard can be assembled from those two rather than
     * threaded through every place a controller is built.
     */
    private ?LoginSprayGuard $sprayGuard = null;

    /**
     * The plugins' view of authentication.
     *
     * Never null, so no call site has to remember to check: an installation with
     * no plugin system gets a gate with nothing behind it, which permits
     * everything and announces nothing.
     */
    private readonly AuthGate $gate;

    /**
     * @param AuthGate|null $gate Injected by tests. The kernel builds this
     *        controller with no plugin manager in reach, so the default is the
     *        process-wide gate installed at boot.
     */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly LoginThrottle $throttle,
        private readonly ContentService $contentService,
        private readonly CoreConfig $config,
        private readonly string $initialPassword,
        ?AuthGate $gate = null,
        /**
         * The second factor. Optional so the two existing test harnesses and
         * any caller predating it still construct; when absent, no account has
         * a second factor and login behaves exactly as it did.
         */
        private readonly ?TwoFactorService $twoFactor = null,
        /**
         * How single sign-on is configured, so a password login can be refused
         * for an account that belongs to a provider. Optional for the same
         * reason the second factor is: a caller that supplies none gets the
         * behaviour that existed before either.
         */
        private readonly ?OidcSettings $ssoSettings = null,
    ) {
        // An inert gate when none was given: it permits everything and announces
        // nothing, which is exactly right for a CMS constructed without a plugin
        // system in reach. The kernel injects a real one.
        $this->gate = $gate ?? new AuthGate();
    }

    /**
     * Route an `auth/*` request to the operation it names.
     *
     * @return array<string, mixed>
     */
    public function handle(string $path, string $method): array
    {
        $action = ltrim(preg_replace('#^auth/#', '', $path), '/');

        if ($method === 'POST' && $action === 'login') {
            return $this->login();
        }

        if ($method === 'POST' && $action === 'password') {
            return $this->changePassword();
        }

        if ($method === 'POST' && $action === 'logout') {
            return $this->logout();
        }

        // Completing a sign-in that stopped at the second factor. A POST because
        // it establishes a session, and so it carries the CSRF token the pending
        // session issued.
        if ($method === 'POST' && $action === '2fa') {
            return $this->completeTwoFactor();
        }

        if ($method === 'GET' && $action === '2fa') {
            return $this->twoFactorStatus();
        }

        if ($method === 'POST' && $action === '2fa/enrol') {
            return $this->beginTwoFactorEnrolment();
        }

        if ($method === 'POST' && $action === '2fa/confirm') {
            return $this->confirmTwoFactorEnrolment();
        }

        if ($method === 'POST' && $action === '2fa/disable') {
            return $this->disableTwoFactor();
        }

        if ($method === 'GET' && $action === 'me') {
            return $this->me();
        }

        if ($method === 'GET' && $action === 'check') {
            return $this->check();
        }

        return ['status' => 404, 'error' => 'Auth endpoint not found'];
    }

    /**
     * Create the account an installer starts with, once, if it is not there.
     *
     * The account can log in and do exactly one thing until its published,
     * guessable password is replaced: choose a real one.
     */
    public function ensureDefaultAdminUser(): void
    {
        if ($this->contentService->user('admin') !== null) {
            return;
        }

        $admin = [
            'username' => 'admin',
            'displayName' => 'Administrator',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => password_hash($this->initialPassword, PASSWORD_DEFAULT),
            'mustChangePassword' => true,
            'createdAt' => gmdate('c'),
        ];

        $this->contentService->save(Content::create($this->contentService->userKey('admin'), $admin));
    }

    /* --------------------------------------------------------- login -- */

    /**
     * @return array<string, mixed>
     */
    private function login(): array
    {
        $data = $this->jsonBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $remember = (bool) ($data['remember'] ?? false);

        if ($username === '' || $password === '') {
            // Not announced as a failed sign-in: nothing was attempted against
            // any account, and an event with no username in it is noise an
            // alerting plugin would have to learn to ignore.
            return ['status' => 400, 'error' => 'Username and password required'];
        }

        // The site-wide ceiling is consulted before anything touches the named
        // account, and before the password is verified. Verifying first and
        // only then refusing would turn the refusal into an oracle — a wrong
        // guess and a right one would come back differently — and an attacker
        // could go on testing passwords at full speed through a block that
        // announced which of them had worked.
        $spray = $this->checkSprayCooloff();
        if ($spray !== null) {
            return $spray;
        }

        // Neither this refusal nor the one above announces anything to a plugin.
        // Both are re-refusals of attempts already counted, and a hook fired
        // here would be one plugin dispatch per request for as long as an
        // attacker cares to keep knocking — a webhook or an SMS per attempt,
        // driven by whoever is attacking, through the very limit that exists to
        // stop them. The moment worth telling a plugin about is the one where
        // the lockout was established, which `recordFailedLogin()` announces
        // once.
        $lockout = $this->checkLockout($username);
        if ($lockout !== null) {
            return $lockout;
        }

        // Read through the content service rather than guessing at a path. This
        // previously looked in content/users/ (plural) while users are stored
        // under content/user/, so no account was ever found and every login
        // failed — including the default admin the installer creates.
        $account = $this->contentService->user($username);
        if ($account === null) {
            return $this->rejectCredentials($username);
        }

        // Content nests its payload under `data`; the password lives there, not
        // at the top level of the stored document.
        $userData = $account->data;

        $hash = $userData['password'] ?? null;
        if (!is_string($hash) || $hash === '') {
            // No usable hash means the account cannot be authenticated. There is
            // deliberately no fallback: a hardcoded admin/admin escape hatch here
            // would let anyone in whenever a user document lost its password.
            return $this->rejectCredentials($username);
        }

        if (!password_verify($password, $hash)) {
            return $this->rejectCredentials($username);
        }

        // An account that belongs to an identity provider, at a site that has
        // turned local passwords off.
        //
        // Checked after the password is verified, not before. Refusing earlier
        // would answer differently for a right password and a wrong one, which
        // turns this into an oracle for whether a given account exists and is
        // linked — and the whole reason a site sets this is that leaving the
        // organisation should remove access, which an oracle helps nobody with.
        if ($this->passwordLoginIsClosedFor($userData)) {
            $this->gate->announceLoginFailed($username, AuthGate::FAILED_REFUSED);

            return [
                'status' => 403,
                'error' => 'This account signs in through your organisation. Use the single sign-on button.',
            ];
        }

        if (($userData['status'] ?? 'active') !== 'active') {
            // Announced with its own reason, unlike the three above: the caller
            // is already told this apart from a bad password — it is a `403`,
            // not a `401` — so a plugin learning it learns nothing the person
            // holding the credentials does not already know. No failure is
            // counted here, which is the behaviour that was already in place: a
            // disabled account is refused by being disabled, and counting it
            // would let anyone lock a suspended colleague's name out of the
            // throttle as well.
            $this->gate->announceLoginFailed($username, AuthGate::FAILED_INACTIVE);

            return ['status' => 403, 'error' => 'Account is not active'];
        }

        // The one place a plugin can stop a sign-in. Everything an attacker
        // controls has already been checked — the ceiling, the lockout, the
        // password, the account's status — so a plugin is only asked about an
        // attempt that would otherwise have succeeded, and cannot be used to
        // reach past any of those limits. See `AuthGate` for why the answer is
        // read fail-open.
        $refusal = $this->gate->refusalForLogin($username, $userData, $remember);
        if ($refusal !== null) {
            $this->gate->announceLoginFailed($username, AuthGate::FAILED_REFUSED);

            // Counted against this account's own threshold but not against the
            // site-wide ceiling, on the same distinction `recordFailedLogin()`
            // already draws: whoever got here proved the password, so this is
            // not evidence of credential stuffing and must not push a site with
            // a second factor towards refusing logins for everybody. It is
            // still evidence of an unfinished authentication, and leaving it
            // uncounted would make the gate an unmetered surface to retry
            // against — a plugin's own limit is not something core can assume.
            $this->recordFailedLogin($username, false);

            // The reason reaches the caller verbatim. A second factor cannot be
            // asked for without admitting the first one was accepted, and a
            // plugin's reason is the only thing that tells the legitimate user
            // what to do next — a generic "invalid credentials" here would make
            // 2FA unusable. `403` rather than `401` is what separates it from a
            // wrong password: the credentials were right, something else is
            // outstanding. Which plugin refused, and for whom, is in the error
            // log and not in the response.
            return ['status' => 403, 'error' => $refusal];
        }

        // The password was right. If this account carries a second factor, the
        // sign-in stops here and nothing is authenticated yet: a *pending*
        // session is written, holding the name and no `user` key at all, so
        // `SessionStore::user()` keeps answering null and every guard in the
        // application goes on treating the caller as anonymous. Whoever holds
        // the password alone can reach exactly one endpoint — the one that asks
        // for the code.
        //
        // The failure count is deliberately not cleared here. It is cleared when
        // a session actually exists, so a correct password with no second factor
        // cannot be used to keep resetting an account's lockout.
        if ($this->twoFactorRequiredFor($username)) {
            $this->sessions->start([
                'pendingTwoFactor' => $username,
                'pendingRemember' => $remember,
                // Short and absolute. Somebody who walks away mid-login leaves a
                // half-authenticated session behind, and it must expire on its
                // own rather than waiting for the ordinary idle timeout.
                'pendingExpiresAt' => time() + 300,
                'csrfToken' => CsrfGuard::generateToken(),
            ], false);

            return ['data' => [
                'success' => false,
                'twoFactorRequired' => true,
                // The token the next request must carry. It is the pending
                // session's own, so the second step is CSRF-protected like every
                // other state-changing call.
                'csrfToken' => $this->sessions->read()['csrfToken'] ?? null,
            ]];
        }

        $session = $this->establishSession($username, $userData, $remember);

        // Last, once the session exists and the failure count is cleared: the
        // hook says a sign-in happened, so nothing about it may still be pending.
        $this->gate->announceLoggedIn($username, $userData, $remember);

        return ['data' => ['success' => true, 'user' => $session['user']]];
    }

    /**
     * Write the real session and clear the account's failure count.
     *
     * Extracted because there are now two ways to arrive at a signed-in state —
     * straight through, and via the second factor — and a second copy of this is
     * a second place for the session shape to drift. `SessionStore::start()`
     * mints a fresh identifier every time it is called, so promoting a pending
     * session also rotates the cookie, which is the session-fixation defence for
     * the two-step flow.
     *
     * @param array<string, mixed> $userData
     * @return array<string, mixed> The session that was written.
     */
    private function establishSession(string $username, array $userData, bool $remember): array
    {
        $session = [
            'username' => $username,
            'loginTime' => time(),
            'expiresAt' => time() + $this->sessionTtlSeconds($remember),
            'remember' => $remember,
            'lastActivity' => time(),
            'sessionId' => bin2hex(random_bytes(16)),
            'csrfToken' => CsrfGuard::generateToken(),
            'user' => [
                'username' => $userData['username'] ?? $username,
                'displayName' => $userData['displayName'] ?? $username,
                'email' => $userData['email'] ?? '',
                'role' => $userData['role'] ?? 'editor',
                'capabilities' => Role::fromName($userData['role'] ?? null)->capabilityNames(),
                'mustChangePassword' => (bool) ($userData['mustChangePassword'] ?? false),
                'twoFactor' => $this->twoFactor?->isActiveFor($username) ?? false,
            ],
        ];

        $this->sessions->start($session, $remember);
        $this->clearFailedLogin($username);

        return $session;
    }

    private function twoFactorRequiredFor(string $username): bool
    {
        return $this->twoFactor?->isActiveFor($username) ?? false;
    }

    /**
     * Whether this account may no longer sign in with a password.
     *
     * Only for accounts actually linked to the provider. A site that turns on
     * single sign-on still has a local administrator who is not linked, and
     * closing password login for them would lock the site's owner out of their
     * own site the moment the provider had an outage.
     *
     * @param array<string, mixed> $userData
     */
    private function passwordLoginIsClosedFor(array $userData): bool
    {
        if ($this->ssoSettings === null || !$this->ssoSettings->enabled || $this->ssoSettings->allowPasswordLogin) {
            return false;
        }

        $subject = $userData['ssoSubject'] ?? null;

        return is_string($subject) && $subject !== '';
    }

    /* ---------------------------------------------------- second factor -- */

    /**
     * Finish a sign-in that stopped at the second factor.
     *
     * @return array<string, mixed>
     */
    private function completeTwoFactor(): array
    {
        if ($this->twoFactor === null) {
            return ['status' => 404, 'error' => 'Auth endpoint not found'];
        }

        $session = $this->sessions->read();
        $username = $session['pendingTwoFactor'] ?? null;

        if (!is_string($username) || $username === '') {
            return ['status' => 401, 'error' => 'Sign in again.'];
        }

        // Absolute, not idle-based. A half-authenticated session left open on a
        // shared machine must close on its own.
        if ((int) ($session['pendingExpiresAt'] ?? 0) < time()) {
            $this->sessions->clear();

            return ['status' => 401, 'error' => 'That took too long. Sign in again.'];
        }

        // The same ceiling and the same per-account lockout the password step
        // uses. Without this the second factor is six digits with unlimited
        // guesses, which is a worse secret than the password it is defending.
        $spray = $this->checkSprayCooloff();
        if ($spray !== null) {
            return $spray;
        }

        $lockout = $this->checkLockout($username);
        if ($lockout !== null) {
            return $lockout;
        }

        $code = (string) ($this->jsonBody()['code'] ?? '');
        if (trim($code) === '') {
            return ['status' => 400, 'error' => 'Enter the code from your authenticator app.'];
        }

        if (!$this->twoFactor->verifyChallenge($username, $code)) {
            $this->gate->announceLoginFailed($username, AuthGate::FAILED_CREDENTIALS);
            $this->recordFailedLogin($username);

            return ['status' => 401, 'error' => 'That code is not right.'];
        }

        $account = $this->contentService->user($username);
        if ($account === null) {
            // The account went away between the two steps. Nothing to sign in to.
            $this->sessions->clear();

            return ['status' => 401, 'error' => 'Sign in again.'];
        }

        $remember = (bool) ($session['pendingRemember'] ?? false);
        $established = $this->establishSession($username, $account->data, $remember);

        $this->gate->announceLoggedIn($username, $account->data, $remember);

        return ['data' => ['success' => true, 'user' => $established['user']]];
    }

    /**
     * Whether the signed-in account has a second factor, and how many recovery
     * codes it has left.
     *
     * @return array<string, mixed>
     */
    private function twoFactorStatus(): array
    {
        $user = $this->sessions->user();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        if ($this->twoFactor === null) {
            return ['data' => ['available' => false, 'active' => false, 'pending' => false]];
        }

        $enrolment = $this->twoFactor->enrolmentFor((string) ($user['username'] ?? ''));

        return ['data' => [
            'available' => true,
            'active' => $enrolment->isActive(),
            'pending' => $enrolment->isPending(),
            'recoveryCodesLeft' => $enrolment->unusedRecoveryCodeCount(),
        ]];
    }

    /**
     * Issue a secret and recovery codes, and show them once.
     *
     * @return array<string, mixed>
     */
    private function beginTwoFactorEnrolment(): array
    {
        $user = $this->sessions->user();
        if ($user === null || $this->twoFactor === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        $username = (string) ($user['username'] ?? '');
        $enrolment = $this->twoFactor->beginEnrolment($username);

        if ($enrolment === null) {
            // Already protected. Replacing a confirmed second factor without
            // proof would make it removable by anyone holding a borrowed
            // session, which is the thing it exists to prevent.
            return ['status' => 409, 'error' => 'Two-factor authentication is already on for this account. Turn it off first.'];
        }

        return ['data' => $enrolment];
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmTwoFactorEnrolment(): array
    {
        $user = $this->sessions->user();
        if ($user === null || $this->twoFactor === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        $code = (string) ($this->jsonBody()['code'] ?? '');
        $username = (string) ($user['username'] ?? '');

        if (!$this->twoFactor->confirmEnrolment($username, $code)) {
            return ['status' => 422, 'error' => 'That code is not right. Check your authenticator app and try again.'];
        }

        // The session's own copy of the flag would otherwise say "off" until the
        // next sign-in, and the profile screen reads it.
        $this->sessions->merge(['user' => ['twoFactor' => true]]);

        return ['data' => ['success' => true, 'active' => true]];
    }

    /**
     * Turn the second factor off, on proof of the account password.
     *
     * The password is required for the same reason changing a password requires
     * the current one: a borrowed or hijacked session must not be able to strip
     * the protection off the account it borrowed.
     *
     * @return array<string, mixed>
     */
    private function disableTwoFactor(): array
    {
        $user = $this->sessions->user();
        if ($user === null || $this->twoFactor === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        $username = (string) ($user['username'] ?? '');
        $password = (string) ($this->jsonBody()['password'] ?? '');

        if ($password === '') {
            return ['status' => 400, 'error' => 'Enter your password to turn this off.'];
        }

        $account = $this->contentService->user($username);
        $hash = $account?->data['password'] ?? null;

        if (!is_string($hash) || !password_verify($password, $hash)) {
            $this->recordFailedLogin($username, false);

            return ['status' => 403, 'error' => 'That password is not correct.'];
        }

        $this->twoFactor->disable($username);
        $this->sessions->merge(['user' => ['twoFactor' => false]]);

        return ['data' => ['success' => true, 'active' => false]];
    }

    /**
     * Refuse an attempt for a reason the caller is told nothing more about than
     * "invalid credentials", and tell the plugins exactly that much.
     *
     * The three callers are an account that does not exist, an account with no
     * usable hash, and a wrong password. They are one case here because they are
     * one case in the response, and {@see AuthGate} explains at length why a
     * plugin is not given the difference.
     *
     * @return array<string, mixed>
     */
    private function rejectCredentials(string $username): array
    {
        $this->gate->announceLoginFailed($username, AuthGate::FAILED_CREDENTIALS);
        $this->recordFailedLogin($username);

        return ['status' => 401, 'error' => 'Invalid credentials'];
    }

    /**
     * Change the signed-in account's password.
     *
     * Requires the current password even though the session is already
     * authenticated: it is what stops a borrowed or hijacked session from
     * locking the real owner out of their own account.
     *
     * @return array<string, mixed>
     */
    private function changePassword(): array
    {
        $session = $this->sessions->user();
        if ($session === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        $data = $this->jsonBody();
        $current = (string) ($data['currentPassword'] ?? '');
        $new = (string) ($data['newPassword'] ?? '');

        if ($current === '' || $new === '') {
            return ['status' => 400, 'error' => 'Both the current and the new password are required.'];
        }

        $username = (string) ($session['username'] ?? '');
        $account = $this->contentService->user($username);
        if ($account === null) {
            return ['status' => 404, 'error' => 'Account not found'];
        }

        $hash = $account->data['password'] ?? null;
        if (!is_string($hash) || !password_verify($current, $hash)) {
            $this->recordFailedLogin($username, false);
            return ['status' => 403, 'error' => 'The current password is not correct.'];
        }

        $minimum = $this->passwordMinLength();
        if (mb_strlen($new) < $minimum) {
            return ['status' => 422, 'error' => "The new password must be at least {$minimum} characters."];
        }

        if ($new === $current) {
            return ['status' => 422, 'error' => 'The new password must differ from the current one.'];
        }

        // The seeded password is published, so it can never be the answer even
        // if it satisfies the length rule.
        if ($new === $this->initialPassword) {
            return ['status' => 422, 'error' => 'That password cannot be used.'];
        }

        $account->update([
            'password' => password_hash($new, PASSWORD_DEFAULT),
            'mustChangePassword' => null,
            'passwordChangedAt' => gmdate('c'),
        ]);
        $this->contentService->save($account);

        $this->clearFailedLogin($username);
        $this->refreshSessionUser($username);

        return ['data' => ['success' => true]];
    }

    /**
     * Re-read the account into the session after it changes, so a stale flag
     * cannot keep an account locked out of the rest of the API.
     */
    private function refreshSessionUser(string $username): void
    {
        $account = $this->contentService->user($username);
        if ($account === null) {
            return;
        }

        $this->sessions->merge([
            'user' => ['mustChangePassword' => (bool) ($account->data['mustChangePassword'] ?? false)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logout(): array
    {
        // Read before the session is destroyed, announced after: the payload has
        // to be taken while there is still something to take it from, and the
        // hook must not fire until the session is really gone.
        $user = $this->sessions->user() ?? [];

        $this->sessions->clear();

        // A logout by somebody who was not signed in is silent. The endpoint
        // answers success either way — clearing nothing is not an error — but an
        // audit trail with a sign-out that never had a sign-in in it is worse
        // than one without the entry.
        $username = $user['username'] ?? null;
        if (is_string($username) && $username !== '') {
            $this->gate->announceLoggedOut($username, $user);
        }

        return ['data' => ['success' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function me(): array
    {
        $user = $this->sessions->user();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        return ['data' => $user];
    }

    /**
     * @return array<string, mixed>
     */
    private function check(): array
    {
        $session = $this->sessions->read();
        $user = $session['user'] ?? null;

        return ['data' => [
            'authenticated' => $user !== null,
            'user' => $user,
            'expiresAt' => $session['expiresAt'] ?? null,
            'remember' => $session['remember'] ?? false,
            'lastActivity' => $session['lastActivity'] ?? null,
            'sessionId' => $session['sessionId'] ?? null,
            'csrfToken' => $session['csrfToken'] ?? null,
        ]];
    }

    /* ------------------------------------------------------ throttle -- */

    /**
     * @return array<string, mixed>|null A 429 while locked out, else null.
     */
    private function checkLockout(string $username): ?array
    {
        $remaining = $this->throttle->secondsRemaining($username);

        return $remaining === null ? null : $this->tooManyAttempts($remaining);
    }

    /**
     * Refuse every login while the site as a whole is over its failure ceiling.
     *
     * This is the one place the CMS knowingly fails closed. During a spray a
     * legitimate editor arriving with the right password is turned away too,
     * which is a real cost, and it is chosen over the alternative — letting
     * correct credentials through — because that alternative is not a limit at
     * all, only a slower way of telling an attacker which guess was right.
     *
     * What keeps the cost small is that the refusal is never longer than the
     * evidence for it: {@see LoginSprayGuard} recomputes the window on every
     * call instead of latching a deadline, so the door reopens the moment the
     * failures age out. Sessions already established are untouched — everyone
     * signed in when the attack started keeps working — and the ceiling sits
     * far above the number of failures ordinary use produces. An attacker can
     * still deny the login form to newcomers for a quarter of an hour at a
     * time, and that is the residual risk being accepted here.
     *
     * @return array<string, mixed>|null
     */
    private function checkSprayCooloff(): ?array
    {
        $remaining = $this->spray()->secondsRemaining();

        return $remaining === null ? null : $this->tooManyAttempts($remaining);
    }

    /**
     * Word for word what a per-account lockout says, on purpose. Somebody
     * turned away during a spray must not be able to tell from the answer
     * whether the account they named exists, is locked, or was never involved.
     *
     * @return array<string, mixed>
     */
    private function tooManyAttempts(int $remaining): array
    {
        $minutes = (int) ceil($remaining / 60);

        return [
            'status' => 429,
            'error' => "Too many failed attempts. Try again in {$minutes} minute(s).",
            'retryAfter' => $remaining,
        ];
    }

    /**
     * @param bool $anonymous Whether the failure came from someone not yet
     *   signed in. Only those count towards the site-wide ceiling: an editor
     *   with a valid session mistyping their current password is not part of a
     *   credential attack, and counting them would let ordinary clumsiness push
     *   the site towards refusing logins for everybody. Their account's own
     *   lockout still counts it, which is the right scope for that mistake.
     */
    private function recordFailedLogin(string $username, bool $anonymous = true): void
    {
        // Whether this failure is the one that tips the account over is only
        // knowable by asking before and after, and each answer is a read of the
        // lockout file. Nobody listening, nothing measured — the guard is what
        // keeps the announcement from making every failed attempt more
        // expensive than it was.
        $announce = $this->gate->listensTo(AuthGate::LOCKED_OUT);
        $wasLocked = $announce && $this->throttle->isLocked($username);

        $this->throttle->recordFailure($username);

        if ($anonymous) {
            $this->spray()->recordFailure();
        }

        if (!$announce || $wasLocked) {
            return;
        }

        // Only the transition. While a lock holds, every further attempt is
        // refused before it reaches here, but a password change by a signed-in
        // account can still count failures against a locked name — announcing
        // that again would turn one alert into one per attempt.
        $remaining = $this->throttle->secondsRemaining($username);
        if ($remaining !== null) {
            $this->gate->announceLockedOut($username, $remaining);
        }
    }

    private function spray(): LoginSprayGuard
    {
        return $this->sprayGuard ??= $this->throttle->sprayGuard(
            $this->config->sprayMaxFailures(),
            $this->config->sprayWindowSeconds(),
        );
    }

    private function clearFailedLogin(string $username): void
    {
        $this->throttle->clear($username);
    }

    /* -------------------------------------------------------- config -- */

    private function sessionTtlSeconds(bool $remember): int
    {
        return $this->config->sessionTtlSeconds($remember);
    }

    private function passwordMinLength(): int
    {
        return $this->config->passwordMinLength();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return $_POST;
        }

        $data = json_decode($input, true);

        return is_array($data) ? $data : [];
    }
}
