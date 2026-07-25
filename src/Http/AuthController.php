<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Authentication\CsrfGuard;
use Click\Cms\Application\Authentication\LoginSprayGuard;
use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
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

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly LoginThrottle $throttle,
        private readonly ContentService $contentService,
        private readonly CoreConfig $config,
        private readonly string $initialPassword,
    ) {}

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
            $this->recordFailedLogin($username);
            return ['status' => 401, 'error' => 'Invalid credentials'];
        }

        // Content nests its payload under `data`; the password lives there, not
        // at the top level of the stored document.
        $userData = $account->data;

        $hash = $userData['password'] ?? null;
        if (!is_string($hash) || $hash === '') {
            // No usable hash means the account cannot be authenticated. There is
            // deliberately no fallback: a hardcoded admin/admin escape hatch here
            // would let anyone in whenever a user document lost its password.
            $this->recordFailedLogin($username);
            return ['status' => 401, 'error' => 'Invalid credentials'];
        }

        if (!password_verify($password, $hash)) {
            $this->recordFailedLogin($username);
            return ['status' => 401, 'error' => 'Invalid credentials'];
        }

        if (($userData['status'] ?? 'active') !== 'active') {
            return ['status' => 403, 'error' => 'Account is not active'];
        }

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
            ],
        ];

        $this->sessions->start($session, $remember);
        $this->clearFailedLogin($username);

        return ['data' => ['success' => true, 'user' => $session['user']]];
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
        $this->sessions->clear();

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
        $this->throttle->recordFailure($username);

        if ($anonymous) {
            $this->spray()->recordFailure();
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
