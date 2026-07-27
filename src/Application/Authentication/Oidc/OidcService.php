<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication\Oidc;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Identity\Oidc\AccountLinkDecision;
use Click\Cms\Domain\Identity\Oidc\AuthorizationRequest;
use Click\Cms\Domain\Identity\Oidc\IdTokenClaims;
use Click\Cms\Domain\Identity\Oidc\IdTokenVerifier;
use Click\Cms\Infrastructure\Http\JsonHttp;
use RuntimeException;

/**
 * The single sign-on flow: send somebody to their identity provider, and work
 * out who came back.
 *
 * Two methods, and the gap between them is the whole security model. {@see
 * begin()} generates three one-time secrets and hands back where to send the
 * browser; {@see complete()} refuses anything that does not match them. Nothing
 * the callback supplies is trusted on its own — the code, the state, even the
 * ID token are all checked against something the server generated and kept.
 */
final class OidcService
{
    public function __construct(
        private readonly OidcSettings $settings,
        private readonly ProviderMetadata $metadata,
        private readonly ContentService $content,
        private readonly JsonHttp $http = new JsonHttp(),
    ) {}

    /**
     * Start a sign-in.
     *
     * @return array{url: string, request: AuthorizationRequest}
     */
    public function begin(): array
    {
        $request = AuthorizationRequest::generate();

        return [
            'url' => $request->authorizationUrl(
                $this->metadata->authorizationEndpoint(),
                $this->settings->clientId,
                $this->settings->redirectUri,
                $this->settings->scope,
                $this->settings->extraAuthorizationParameters,
            ),
            // The caller stores this in the pending session. It never travels
            // anywhere the browser can reach.
            'request' => $request,
        ];
    }

    /**
     * Finish a sign-in, returning the account it belongs to.
     *
     * @param string $code  The authorization code the provider sent back.
     * @param string $state The state the provider sent back.
     * @return array{username: string, role: ?string}
     * @throws RuntimeException with a reason for the log.
     */
    public function complete(string $code, string $state, AuthorizationRequest $request): array
    {
        // First, and before anything is exchanged. A mismatched state means this
        // callback belongs to a different sign-in — which is exactly the login
        // CSRF attack, where an attacker's half-finished login is handed to a
        // victim whose browser completes it and is signed in as the attacker.
        if (!$request->matchesState($state)) {
            throw new RuntimeException('This sign-in did not start here.');
        }

        $claims = $this->exchange($code, $request);

        return $this->resolveAccount($claims);
    }

    /**
     * Trade the code for an ID token, and verify it.
     */
    private function exchange(string $code, AuthorizationRequest $request): IdTokenClaims
    {
        $response = $this->http->postFormJson($this->metadata->tokenEndpoint(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->settings->redirectUri,
            'client_id' => $this->settings->clientId,
            'client_secret' => $this->settings->clientSecret,
            // The proof that this is the same sign-in that asked for the code.
            'code_verifier' => $request->codeVerifier,
        ]);

        $idToken = $response['id_token'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            // An access token without an ID token is OAuth, not OpenID Connect,
            // and an access token says what somebody may do rather than who they
            // are. Signing anyone in on one would be authenticating with an
            // authorisation artefact — the confusion at the root of a good
            // number of real breaches.
            throw new RuntimeException('The identity provider returned no ID token.');
        }

        $verifier = new IdTokenVerifier($this->settings->issuer, $this->settings->clientId);

        try {
            return $verifier->verify($idToken, $this->metadata->keys(), $request->nonce, time());
        } catch (RuntimeException $e) {
            // One retry, and only for the one failure a key rotation causes.
            // Retrying on any failure would turn every forged token into a
            // request to the provider.
            if (!str_contains($e->getMessage(), 'signing key the provider does not publish')) {
                throw $e;
            }

            return $verifier->verify($idToken, $this->metadata->keys(forceRefresh: true), $request->nonce, time());
        }
    }

    /**
     * Which local account this is, creating or linking one if the site allows.
     *
     * @return array{username: string, role: ?string}
     */
    private function resolveAccount(IdTokenClaims $claims): array
    {
        // The email lookup happens unconditionally and the policy decides what
        // to make of it. That is deliberate: an address matching a local account
        // is a refusal even at a site that has not opted in to email linking,
        // because the alternative — falling through to provisioning — creates a
        // second account with the same address and leaves two people believing
        // they share one.
        $decision = $this->settings->linkPolicy->decide(
            $claims,
            $this->usernameLinkedTo($claims->subject),
            $this->usernameWithEmail($claims->email),
        );

        if ($decision->isRefusal()) {
            throw new RuntimeException((string) $decision->reason);
        }

        $role = $this->settings->roleFor($claims->raw);

        return match ($decision->outcome) {
            AccountLinkDecision::SIGN_IN => $this->signIn((string) $decision->username, $claims, $role),
            AccountLinkDecision::ADOPT => $this->adopt((string) $decision->username, $claims, $role),
            default => $this->create($claims, $role ?? (string) $decision->role),
        };
    }

    /**
     * @return array{username: string, role: ?string}
     */
    private function signIn(string $username, IdTokenClaims $claims, ?string $role): array
    {
        $account = $this->content->user($username);

        if ($account === null) {
            throw new RuntimeException('The linked account no longer exists.');
        }

        $changes = ['ssoLastLoginAt' => gmdate('c')];

        // Only when the site configured a mapping. Without one, an
        // administrator's hand-set role is left alone rather than being
        // overwritten on every login by a default nobody chose.
        if ($role !== null && $role !== ($account->data['role'] ?? null)) {
            $changes['role'] = $role;
        }

        $account->update($changes);
        $this->content->save($account);

        return ['username' => $username, 'role' => $account->data['role'] ?? null];
    }

    /**
     * @return array{username: string, role: ?string}
     */
    private function adopt(string $username, IdTokenClaims $claims, ?string $role): array
    {
        $account = $this->content->user($username);

        if ($account === null) {
            throw new RuntimeException('The account being linked no longer exists.');
        }

        $account->update(['ssoSubject' => $claims->subject]);
        $this->content->save($account);

        return $this->signIn($username, $claims, $role);
    }

    /**
     * @return array{username: string, role: ?string}
     */
    private function create(IdTokenClaims $claims, string $role): array
    {
        $username = $this->availableUsername($claims);

        $this->content->save(Content::create($this->content->userKey($username), [
            'username' => $username,
            'displayName' => $claims->name ?? $username,
            'email' => $claims->email ?? '',
            'role' => $role,
            'status' => 'active',
            // No password field at all, rather than an unusable placeholder. An
            // account created by single sign-on has no local password, and
            // `AuthController` already refuses an account whose hash is missing
            // — deliberately, with no fallback — so this cannot be signed into
            // with a guess.
            'ssoSubject' => $claims->subject,
            'ssoProvisionedAt' => gmdate('c'),
            'createdAt' => gmdate('c'),
        ]));

        return ['username' => $username, 'role' => $role];
    }

    private function usernameLinkedTo(string $subject): ?string
    {
        foreach ($this->content->all('user') as $account) {
            $stored = $account->data['ssoSubject'] ?? null;

            // Constant time, because this is a secret-ish identifier compared in
            // authentication code.
            if (is_string($stored) && $stored !== '' && hash_equals($stored, $subject)) {
                return (string) ($account->data['username'] ?? $account->key->slug);
            }
        }

        return null;
    }

    private function usernameWithEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        foreach ($this->content->all('user') as $account) {
            $stored = $account->data['email'] ?? null;

            if (is_string($stored) && $stored !== '' && strcasecmp($stored, $email) === 0) {
                return (string) ($account->data['username'] ?? $account->key->slug);
            }
        }

        return null;
    }

    /**
     * A username nobody is using, derived from what the provider said.
     *
     * Derived rather than taken: the local address space is `[A-Za-z0-9._-]`
     * (see `ContentKeyRules`), and a provider is free to hand back a display
     * name containing anything at all.
     */
    private function availableUsername(IdTokenClaims $claims): string
    {
        $seed = $claims->email !== null ? explode('@', $claims->email)[0] : $claims->subject;
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $seed));
        $base = trim($base, '-.') ?: 'user';
        $base = substr($base, 0, 40);

        if ($this->content->user($base) === null) {
            return $base;
        }

        // A collision means a different person already holds the name. Suffixing
        // is the only safe move: reusing it would be handing over their account.
        for ($n = 2; $n < 1000; $n++) {
            if ($this->content->user("{$base}-{$n}") === null) {
                return "{$base}-{$n}";
            }
        }

        throw new RuntimeException('No username could be derived for this account.');
    }
}
