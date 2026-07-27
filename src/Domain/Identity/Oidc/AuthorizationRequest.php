<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity\Oidc;

/**
 * The one-time secrets a sign-in generates before sending somebody to their
 * identity provider, and the URL that sends them.
 *
 * Three values, each closing a different hole:
 *
 * - **`state`** ties the browser that comes back to the browser that left. It
 *   is what stops login CSRF: without it, an attacker completes half a login
 *   with their own account and hands the callback URL to a victim, whose
 *   browser finishes it and is now signed in *as the attacker* — quietly, into
 *   an account the attacker controls and can read afterwards.
 * - **`nonce`** ties the ID token to this sign-in. Without it a token captured
 *   from one login can be replayed into another.
 * - **`codeVerifier`** (PKCE) ties the code exchange to this sign-in. It was
 *   introduced for mobile apps that cannot keep a client secret, and is used
 *   here anyway because an authorization code that leaks — a referrer header, a
 *   proxy log, a shared browser's history — is otherwise enough on its own.
 *
 * All three are held server-side in the pending session and never trusted from
 * the request. A value the callback supplies and the server merely compares to
 * itself proves nothing.
 */
final class AuthorizationRequest
{
    private function __construct(
        public readonly string $state,
        public readonly string $nonce,
        public readonly string $codeVerifier,
    ) {}

    public static function generate(): self
    {
        return new self(
            self::randomToken(),
            self::randomToken(),
            self::randomToken(),
        );
    }

    /**
     * @param array<string, mixed> $stored The pending session's copy.
     */
    public static function fromArray(array $stored): ?self
    {
        $state = $stored['state'] ?? null;
        $nonce = $stored['nonce'] ?? null;
        $verifier = $stored['codeVerifier'] ?? null;

        if (!is_string($state) || !is_string($nonce) || !is_string($verifier)) {
            return null;
        }

        if ($state === '' || $nonce === '' || $verifier === '') {
            return null;
        }

        return new self($state, $nonce, $verifier);
    }

    /**
     * @return array{state: string, nonce: string, codeVerifier: string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'nonce' => $this->nonce,
            'codeVerifier' => $this->codeVerifier,
        ];
    }

    /**
     * The S256 challenge derived from the verifier.
     *
     * S256 and never `plain`. A `plain` challenge is the verifier itself, so an
     * attacker who intercepted the authorization request has everything needed
     * to redeem the code — which is the entire attack PKCE exists to stop.
     */
    public function codeChallenge(): string
    {
        return self::base64Url(hash('sha256', $this->codeVerifier, true));
    }

    /**
     * Whether the state coming back is the one that went out.
     *
     * Constant time, because this is a secret comparison in authentication code
     * and the habit is worth keeping even where the timing signal is weak.
     */
    public function matchesState(string $candidate): bool
    {
        return hash_equals($this->state, $candidate);
    }

    /**
     * Where to send the browser.
     *
     * @param array<string, string> $extra Provider-specific additions, if a site
     *        configured any.
     */
    public function authorizationUrl(
        string $endpoint,
        string $clientId,
        string $redirectUri,
        string $scope,
        array $extra = [],
    ): string {
        $parameters = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_challenge' => $this->codeChallenge(),
            'code_challenge_method' => 'S256',
        ];

        // Site additions cannot overwrite anything above: `prompt` or `hd` are
        // reasonable things to add, and `redirect_uri` is not.
        foreach ($extra as $name => $value) {
            if (!array_key_exists($name, $parameters)) {
                $parameters[$name] = $value;
            }
        }

        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint . $separator . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 256 bits, base64url. Long enough that guessing is not a strategy, and in
     * an alphabet every provider accepts in a query string unescaped.
     */
    private static function randomToken(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
