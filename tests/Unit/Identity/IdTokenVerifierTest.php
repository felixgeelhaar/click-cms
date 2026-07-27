<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Identity;

use Click\Cms\Domain\Identity\Oidc\IdTokenVerifier;
use Click\Cms\Domain\Identity\Oidc\JsonWebKeySet;
use PHPUnit\Framework\TestCase;

/**
 * Verifying the ID token an identity provider hands back.
 *
 * This is the single most security-critical piece of the single sign-on work:
 * the ID token *is* the assertion that somebody is who they say they are, and
 * every check skipped here is a way to sign in as anybody.
 *
 * The failure modes are well known and all of them have been shipped by
 * somebody:
 *
 * - accepting `alg: none`, so an unsigned token is trusted
 * - accepting a token signed with a key the token itself supplied
 * - not checking `aud`, so a token minted for a different application works here
 * - not checking `iss`, so any provider can vouch for anyone
 * - not checking `exp`, so a token stolen last year still works
 * - not checking `nonce`, so a token captured from one login is replayed into
 *   another
 *
 * There is a test below for each.
 */
final class IdTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://id.example.com';
    private const CLIENT_ID = 'click-cms';
    private const NONCE = 'n-0S6_WzA2Mj';

    /** @var array{private: \OpenSSLAsymmetricKey, jwks: JsonWebKeySet} */
    private array $keys;

    protected function setUp(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('ext-openssl is required to verify RS256.');
        }

        $this->keys = $this->makeKeyPair('key-1');
    }

    /**
     * A keypair plus the JWKS an identity provider would publish for it.
     *
     * @return array{private: \OpenSSLAsymmetricKey, jwks: JsonWebKeySet}
     */
    private function makeKeyPair(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $details = openssl_pkey_get_details($key);

        $jwks = JsonWebKeySet::fromArray(['keys' => [[
            'kty' => 'RSA',
            'kid' => $kid,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ]]]);

        return ['private' => $key, 'jwks' => $jwks];
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Mint a token, signing it properly unless told otherwise.
     *
     * @param array<string, mixed> $claimOverrides
     */
    private function token(array $claimOverrides = [], array $headerOverrides = [], ?\OpenSSLAsymmetricKey $signWith = null): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'key-1'] + $headerOverrides;
        foreach ($headerOverrides as $k => $v) {
            $header[$k] = $v;
        }

        $claims = [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'user-42',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => self::NONCE,
            'email' => 'jo@example.com',
        ];

        foreach ($claimOverrides as $k => $v) {
            if ($v === null) {
                unset($claims[$k]);
                continue;
            }
            $claims[$k] = $v;
        }

        $signing = $this->b64((string) json_encode($header)) . '.' . $this->b64((string) json_encode($claims));

        if (($header['alg'] ?? '') === 'none') {
            return $signing . '.';
        }

        openssl_sign($signing, $signature, $signWith ?? $this->keys['private'], OPENSSL_ALGO_SHA256);

        return $signing . '.' . $this->b64($signature);
    }

    private function verifier(): IdTokenVerifier
    {
        return new IdTokenVerifier(self::ISSUER, self::CLIENT_ID);
    }

    /* ----------------------------------------------------------- happy -- */

    public function testAGenuineTokenVerifies(): void
    {
        $claims = $this->verifier()->verify($this->token(), $this->keys['jwks'], self::NONCE, time());

        $this->assertSame('user-42', $claims->subject);
        $this->assertSame('jo@example.com', $claims->email);
    }

    /* -------------------------------------------------------- signature -- */

    /**
     * The oldest JWT hole there is. A token whose header says `alg: none` has
     * no signature at all, and a verifier that honours the header signs in
     * whoever asks.
     */
    public function testAnUnsignedTokenIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/algorithm/i');

        $this->verifier()->verify(
            $this->token(headerOverrides: ['alg' => 'none']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    /**
     * The second-oldest. HS256 is symmetric, so a verifier that accepts it will
     * happily check the signature using the public key as the shared secret —
     * and the public key is public.
     */
    public function testASymmetricAlgorithmIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/algorithm/i');

        $this->verifier()->verify(
            $this->token(headerOverrides: ['alg' => 'HS256']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    public function testATokenSignedWithTheWrongKeyIsRefused(): void
    {
        $other = $this->makeKeyPair('key-1');

        $this->expectExceptionMessageMatches('/signature/i');

        $this->verifier()->verify(
            $this->token(signWith: $other['private']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    public function testATamperedPayloadIsRefused(): void
    {
        $token = $this->token();
        [$header, $payload, $signature] = explode('.', $token);

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['sub'] = 'somebody-else';
        $forged = $header . '.' . $this->b64((string) json_encode($claims)) . '.' . $signature;

        $this->expectExceptionMessageMatches('/signature/i');

        $this->verifier()->verify($forged, $this->keys['jwks'], self::NONCE, time());
    }

    public function testATokenNamingAnUnknownKeyIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/key/i');

        $this->verifier()->verify(
            $this->token(headerOverrides: ['kid' => 'not-a-key-we-know']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    /* ----------------------------------------------------------- claims -- */

    public function testATokenFromAnotherIssuerIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/issuer/i');

        $this->verifier()->verify(
            $this->token(['iss' => 'https://evil.example.com']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    /**
     * A token minted for a different application at the same provider. Without
     * this check, anyone who can get a token from the same identity provider —
     * for any app at all — can present it here.
     */
    public function testATokenForAnotherAudienceIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/audience/i');

        $this->verifier()->verify(
            $this->token(['aud' => 'some-other-app']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    public function testAnAudienceListContainingUsIsAccepted(): void
    {
        $claims = $this->verifier()->verify(
            $this->token(['aud' => ['some-other-app', self::CLIENT_ID]]),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );

        $this->assertSame('user-42', $claims->subject);
    }

    /**
     * When more than one audience is present the provider must say which party
     * the token was actually minted for, and it has to be us.
     */
    public function testAMultiAudienceTokenAuthorisedForSomebodyElseIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/authorized party|audience/i');

        $this->verifier()->verify(
            $this->token(['aud' => ['some-other-app', self::CLIENT_ID], 'azp' => 'some-other-app']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/expired/i');

        $this->verifier()->verify(
            $this->token(['exp' => time() - 3600]),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    public function testATokenIssuedInTheFutureIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/future/i');

        $this->verifier()->verify(
            $this->token(['iat' => time() + 3600]),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    /**
     * Small clock differences between two servers are ordinary and must not
     * refuse every sign-in.
     */
    public function testASmallClockSkewIsTolerated(): void
    {
        $claims = $this->verifier()->verify(
            $this->token(['exp' => time() - 10]),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );

        $this->assertSame('user-42', $claims->subject);
    }

    /**
     * The nonce ties this token to the login that asked for it. Without it, a
     * token captured from one sign-in can be replayed into another.
     */
    public function testATokenWithTheWrongNonceIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/nonce/i');

        $this->verifier()->verify(
            $this->token(['nonce' => 'somebody-elses-nonce']),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    public function testATokenWithNoNonceIsRefusedWhenOneWasRequested(): void
    {
        $this->expectExceptionMessageMatches('/nonce/i');

        $this->verifier()->verify(
            $this->token(['nonce' => null]),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    public function testATokenWithNoSubjectIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/subject/i');

        $this->verifier()->verify(
            $this->token(['sub' => null]),
            $this->keys['jwks'],
            self::NONCE,
            time(),
        );
    }

    /* ------------------------------------------------------- malformed -- */

    public function testSomethingThatIsNotAJwtIsRefused(): void
    {
        foreach (['', 'nonsense', 'a.b', 'a.b.c.d', '...'] as $rubbish) {
            try {
                $this->verifier()->verify($rubbish, $this->keys['jwks'], self::NONCE, time());
                $this->fail("'{$rubbish}' should have been refused");
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
