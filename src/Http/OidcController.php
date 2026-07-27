<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Authentication\Oidc\OidcService;
use Click\Cms\Application\Authentication\Oidc\OidcSettings;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Identity\Oidc\AuthorizationRequest;
use Click\Cms\Domain\Identity\Role;
use Throwable;

/**
 * The two endpoints single sign-on needs: one that sends the browser away, and
 * one that receives it back.
 *
 * ## Why the reasons are not shown
 *
 * Every failure here answers the same way, and the specific reason goes to the
 * error log. A callback is reachable by anyone — it is a plain GET, and the
 * whole point is that a browser arrives at it unauthenticated — so a response
 * that distinguished "no such linked account" from "provisioning is off" from
 * "that email already exists here" would describe the site's configuration and
 * its user list to whoever is probing.
 *
 * The one exception is the state check, which fails identically for an attack
 * and for somebody who left the tab open too long. That message says to try
 * again, because for the honest case it is the only useful instruction.
 */
final class OidcController
{
    public function __construct(
        private readonly OidcSettings $settings,
        private readonly SessionStore $sessions,
        private readonly ContentService $content,
        private readonly ?OidcService $service = null,
        private readonly ?BasePath $urlBase = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $path, string $method): array
    {
        $action = ltrim(preg_replace('#^auth/sso/?#', '', $path), '/');

        if ($method === 'GET' && $action === 'start') {
            return $this->start();
        }

        if ($method === 'GET' && $action === 'callback') {
            return $this->callback();
        }

        // Read by the login screen before it draws anything, so it knows whether
        // to offer the button and whether the password fields are any use.
        if ($method === 'GET' && ($action === 'status' || $action === '')) {
            return ['data' => $this->status()];
        }

        return ['status' => 404, 'error' => 'Auth endpoint not found'];
    }

    /**
     * What the login screen needs to know: whether to offer the button, and what
     * to call it.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'enabled' => $this->settings->enabled,
            'label' => $this->settings->buttonLabel,
            // So the login screen can hide the password fields at a site that
            // has turned local passwords off, rather than offering a form that
            // always refuses.
            'passwordLogin' => !$this->settings->enabled || $this->settings->allowPasswordLogin,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function start(): array
    {
        if (!$this->settings->enabled || $this->service === null) {
            return ['status' => 404, 'error' => 'Single sign-on is not configured on this site.'];
        }

        try {
            $begin = $this->service->begin();
        } catch (Throwable $e) {
            error_log("click-cms sso: could not start a sign-in: {$e->getMessage()}");

            return ['status' => 502, 'error' => 'Single sign-on is not available right now.'];
        }

        // The three one-time secrets live in a pending session, server-side.
        // A `state` echoed back from a cookie the browser also holds proves
        // nothing — the browser is the thing being checked.
        $this->sessions->start([
            'ssoPending' => $begin['request']->toArray(),
            'ssoExpiresAt' => time() + 600,
        ], false);

        return ['redirect' => $begin['url']];
    }

    /**
     * @return array<string, mixed>
     */
    private function callback(): array
    {
        if (!$this->settings->enabled || $this->service === null) {
            return ['status' => 404, 'error' => 'Single sign-on is not configured on this site.'];
        }

        $session = $this->sessions->read();
        $pending = $session['ssoPending'] ?? null;
        $request = is_array($pending) ? AuthorizationRequest::fromArray($pending) : null;

        if ($request === null || (int) ($session['ssoExpiresAt'] ?? 0) < time()) {
            $this->sessions->clear();

            return $this->failed('That sign-in could not be completed. Please start again.');
        }

        // The provider reports its own refusals here — the person pressed
        // cancel, or is not entitled to this application. Not an error worth a
        // 500, and the provider's own text is not echoed back: it is somebody
        // else's content, and it would land in this site's output.
        if (isset($_GET['error'])) {
            $this->sessions->clear();
            error_log('click-cms sso: the provider refused: ' . (string) $_GET['error']);

            return $this->failed('Your identity provider did not complete the sign-in.');
        }

        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;

        if (!is_string($code) || !is_string($state) || $code === '' || $state === '') {
            $this->sessions->clear();

            return $this->failed('That sign-in could not be completed. Please start again.');
        }

        try {
            $account = $this->service->complete($code, $state, $request);
        } catch (Throwable $e) {
            // The reason to the log, never to the browser. See the class
            // docblock.
            error_log("click-cms sso: sign-in refused: {$e->getMessage()}");
            $this->sessions->clear();

            return $this->failed('You could not be signed in. Ask an administrator if this continues.');
        }

        $this->establishSession($account['username']);

        return ['redirect' => $this->urlBase?->url('/admin/') ?? '/admin/'];
    }

    /**
     * Sign in the resolved account.
     *
     * `SessionStore::start()` mints a fresh identifier, so the pending session
     * that carried the one-time secrets is replaced rather than promoted —
     * which is the session-fixation defence for this flow.
     */
    private function establishSession(string $username): void
    {
        $account = $this->content->user($username);
        $data = $account?->data ?? [];

        $this->sessions->start([
            'username' => $username,
            'loginTime' => time(),
            'expiresAt' => time() + 28800,
            'remember' => false,
            'lastActivity' => time(),
            'sessionId' => bin2hex(random_bytes(16)),
            'csrfToken' => \Click\Cms\Application\Authentication\CsrfGuard::generateToken(),
            'user' => [
                'username' => $username,
                'displayName' => $data['displayName'] ?? $username,
                'email' => $data['email'] ?? '',
                'role' => $data['role'] ?? 'viewer',
                'capabilities' => Role::fromName($data['role'] ?? null)->capabilityNames(),
                // An account that signed in through a provider has no local
                // password to change, so it must never be held at the
                // change-password screen — which would be a wall with no door.
                'mustChangePassword' => false,
                'sso' => true,
            ],
        ], false);
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $message): array
    {
        // Back to the login screen with the message in the query, because the
        // browser arrives here by redirect and there is no JSON client to read
        // a body.
        $base = $this->urlBase?->url('/admin/') ?? '/admin/';

        return ['redirect' => $base . '?ssoError=' . rawurlencode($message)];
    }
}
