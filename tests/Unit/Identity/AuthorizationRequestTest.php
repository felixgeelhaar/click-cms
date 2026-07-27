<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Identity;

use Click\Cms\Domain\Identity\Oidc\AuthorizationRequest;
use PHPUnit\Framework\TestCase;

/**
 * The one-time secrets a sign-in generates, and the URL that carries them.
 */
final class AuthorizationRequestTest extends TestCase
{
    public function testEachRequestGetsItsOwnSecrets(): void
    {
        $a = AuthorizationRequest::generate();
        $b = AuthorizationRequest::generate();

        $this->assertNotSame($a->state, $b->state);
        $this->assertNotSame($a->nonce, $b->nonce);
        $this->assertNotSame($a->codeVerifier, $b->codeVerifier);
    }

    /**
     * The three are independent. Deriving one from another — a nonce that is a
     * hash of the state, say — would mean anyone who saw the authorization URL
     * could compute the rest.
     */
    public function testTheThreeSecretsAreNotDerivedFromEachOther(): void
    {
        $request = AuthorizationRequest::generate();

        $this->assertNotSame($request->state, $request->nonce);
        $this->assertNotSame($request->state, $request->codeVerifier);
        $this->assertNotSame($request->nonce, $request->codeVerifier);
    }

    /**
     * S256, never `plain`. A `plain` challenge *is* the verifier, so anyone who
     * intercepted the authorization request could redeem the code — which is
     * the entire attack PKCE exists to stop.
     */
    public function testTheChallengeIsTheSha256OfTheVerifierNotTheVerifier(): void
    {
        $request = AuthorizationRequest::generate();

        $this->assertNotSame($request->codeVerifier, $request->codeChallenge());
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $request->codeVerifier, true)), '+/', '-_'), '='),
            $request->codeChallenge(),
        );
    }

    public function testTheAuthorizationUrlCarriesEverythingTheProviderNeeds(): void
    {
        $request = AuthorizationRequest::generate();

        $url = $request->authorizationUrl(
            'https://id.example.com/authorize',
            'click-cms',
            'https://site.example.com/api/auth/sso/callback',
            'openid profile email',
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('click-cms', $query['client_id']);
        $this->assertSame('https://site.example.com/api/auth/sso/callback', $query['redirect_uri']);
        $this->assertSame($request->state, $query['state']);
        $this->assertSame($request->nonce, $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($request->codeChallenge(), $query['code_challenge']);
    }

    /**
     * The verifier itself must never travel in the authorization request — it is
     * the secret the code exchange later proves knowledge of.
     */
    public function testTheVerifierIsNeverPutInTheUrl(): void
    {
        $request = AuthorizationRequest::generate();

        $url = $request->authorizationUrl('https://id.example.com/authorize', 'c', 'https://s/cb', 'openid');

        $this->assertStringNotContainsString($request->codeVerifier, $url);
    }

    public function testAnEndpointThatAlreadyHasAQueryIsExtended(): void
    {
        $url = AuthorizationRequest::generate()
            ->authorizationUrl('https://id.example.com/authorize?tenant=acme', 'c', 'https://s/cb', 'openid');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('acme', $query['tenant']);
        $this->assertSame('code', $query['response_type']);
    }

    /**
     * A site may add `prompt` or `hd`; it may not quietly replace the redirect
     * this flow will check against.
     */
    public function testSiteAdditionsCannotOverwriteTheFlowsOwnParameters(): void
    {
        $url = AuthorizationRequest::generate()->authorizationUrl(
            'https://id.example.com/authorize',
            'click-cms',
            'https://site.example.com/cb',
            'openid',
            ['prompt' => 'select_account', 'redirect_uri' => 'https://evil.example.com/cb'],
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('https://site.example.com/cb', $query['redirect_uri']);
    }

    /* --------------------------------------------------------- matching -- */

    public function testTheStateItGeneratedMatches(): void
    {
        $request = AuthorizationRequest::generate();

        $this->assertTrue($request->matchesState($request->state));
    }

    public function testAnotherSignInsStateDoesNotMatch(): void
    {
        $request = AuthorizationRequest::generate();

        $this->assertFalse($request->matchesState(AuthorizationRequest::generate()->state));
        $this->assertFalse($request->matchesState(''));
    }

    /* ---------------------------------------------------------- storage -- */

    public function testItRoundTripsThroughTheSession(): void
    {
        $request = AuthorizationRequest::generate();

        $restored = AuthorizationRequest::fromArray($request->toArray());

        $this->assertNotNull($restored);
        $this->assertSame($request->state, $restored->state);
        $this->assertSame($request->nonce, $restored->nonce);
        $this->assertSame($request->codeVerifier, $restored->codeVerifier);
    }

    /**
     * A half-written or hand-edited session must not produce a request with an
     * empty state, which would then match an empty state coming back.
     */
    public function testAnIncompleteStoredRequestIsRefused(): void
    {
        $this->assertNull(AuthorizationRequest::fromArray([]));
        $this->assertNull(AuthorizationRequest::fromArray(['state' => 'a', 'nonce' => 'b']));
        $this->assertNull(AuthorizationRequest::fromArray(['state' => '', 'nonce' => 'b', 'codeVerifier' => 'c']));
    }
}
