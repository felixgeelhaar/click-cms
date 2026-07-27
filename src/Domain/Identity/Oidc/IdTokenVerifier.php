<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity\Oidc;

use RuntimeException;

/**
 * Checks that an ID token is genuine, current, meant for this site, and tied to
 * the sign-in that asked for it.
 *
 * The most security-critical class in the single sign-on work. The ID token
 * *is* the assertion that somebody is who they claim to be, so every check
 * skipped here is a way to sign in as anybody. Each of the following has been
 * shipped by a real product, and there is a test for each:
 *
 * - **`alg: none`.** A token whose header claims no signature, trusted because
 *   the header was believed.
 * - **Algorithm confusion.** Accepting HS256 and then "verifying" it with the
 *   provider's *public* key as the shared secret — which is public.
 * - **Key confusion.** Verifying against a key the token itself carried
 *   (`jwk`/`jku` headers), which lets the forger supply both.
 * - **No `aud`.** A token minted for a different application at the same
 *   provider works here.
 * - **No `iss`.** Any provider vouches for anyone.
 * - **No `exp`.** A token stolen last year still works.
 * - **No `nonce`.** A token captured from one sign-in replayed into another.
 *
 * The design rule that prevents most of them: **nothing in the token decides
 * how the token is checked.** The algorithm is fixed at RS256 by this class,
 * the issuer and audience come from configuration, and the key comes from the
 * provider's published JWKS. The only thing the header is consulted for is
 * *which* of those published keys — and a `kid` naming a key that is not
 * published is a refusal, never a fallback.
 *
 * Every failure throws with a reason. The reason is for the log, not for the
 * person signing in: a caller that put these in front of an anonymous visitor
 * would be describing the site's configuration to whoever is probing it.
 */
final class IdTokenVerifier
{
    /**
     * The only algorithm accepted, stated here rather than read from the token.
     *
     * RS256 is what OpenID Connect requires every provider to support, so
     * pinning it costs nothing in compatibility and closes both the `none` and
     * the algorithm-confusion holes outright.
     */
    private const ALGORITHM = 'RS256';

    /**
     * Seconds of clock difference tolerated on `exp` and `iat`.
     *
     * Two servers whose clocks differ by a few seconds is ordinary; refusing
     * every sign-in over it would be a support burden with no security gain,
     * since a minute does not meaningfully extend a token's usefulness to
     * somebody who stole it.
     */
    private const LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
    ) {}

    /**
     * @param string $expectedNonce The nonce this sign-in sent to the provider.
     * @throws RuntimeException with the reason, for the log.
     */
    public function verify(string $token, JsonWebKeySet $keys, string $expectedNonce, int $now): IdTokenClaims
    {
        [$header, $claims, $signingInput, $signature] = $this->decompose($token);

        // The algorithm first, and before anything is said about the signature.
        // An `alg: none` token carries an empty signature as well as a bad
        // algorithm, and reporting the empty signature would put the less
        // useful of the two reasons in the log — the defect is that the token
        // claims not to need signing, not that it happens to lack one.
        $this->checkAlgorithm($header);
        $this->checkSignature($header, $keys, $signingInput, $signature);
        $this->checkClaims($claims, $expectedNonce, $now);

        return IdTokenClaims::verified($claims);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decompose(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('The ID token is not a JWT.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = $this->decodeJson($encodedHeader, 'header');
        $claims = $this->decodeJson($encodedClaims, 'claims');
        // An empty or unreadable signature is reported by `checkSignature`, not
        // here, so the algorithm check gets to speak first.
        $signature = self::base64UrlDecode($encodedSignature) ?? '';

        return [$header, $claims, $encodedHeader . '.' . $encodedClaims, $signature];
    }

    /**
     * @param array<string, mixed> $header
     */
    private function checkAlgorithm(array $header): void
    {
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            // One message for `none`, for HS256 and for anything else. The
            // distinction matters to nobody but an attacker probing what this
            // accepts.
            throw new RuntimeException('The ID token is not signed with the required algorithm (RS256).');
        }

        // A token that supplies its own key is a token that verifies itself.
        // Refused rather than ignored, because a provider that sends these is
        // configured in a way somebody should look at.
        if (isset($header['jwk']) || isset($header['jku']) || isset($header['x5u'])) {
            throw new RuntimeException('The ID token tried to supply its own signing key.');
        }
    }

    /**
     * @param array<string, mixed> $header
     */
    private function checkSignature(array $header, JsonWebKeySet $keys, string $signingInput, string $signature): void
    {
        if ($signature === '') {
            throw new RuntimeException('The ID token carries no signature.');
        }

        if ($keys->isEmpty()) {
            throw new RuntimeException('The provider published no usable signing key.');
        }

        $keyId = $header['kid'] ?? null;
        $key = $keys->find(is_string($keyId) ? $keyId : null);

        if ($key === null) {
            // Never a fallback to "try them all". A retired key would go on
            // being accepted long after rotation, which is the whole reason
            // rotation exists.
            throw new RuntimeException('The ID token names a signing key the provider does not publish.');
        }

        $public = openssl_pkey_get_public($key->pem);

        if ($public === false) {
            throw new RuntimeException('The provider\'s signing key could not be read.');
        }

        if (openssl_verify($signingInput, $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The ID token signature is not valid.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function checkClaims(array $claims, string $expectedNonce, int $now): void
    {
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new RuntimeException('The ID token came from a different issuer.');
        }

        $this->checkAudience($claims);

        $expiry = $claims['exp'] ?? null;
        if (!is_numeric($expiry)) {
            throw new RuntimeException('The ID token has expired or states no expiry.');
        }

        if ((int) $expiry + self::LEEWAY_SECONDS < $now) {
            throw new RuntimeException('The ID token has expired.');
        }

        $issuedAt = $claims['iat'] ?? null;
        if (is_numeric($issuedAt) && (int) $issuedAt - self::LEEWAY_SECONDS > $now) {
            throw new RuntimeException('The ID token was issued in the future.');
        }

        // Compared in constant time. The nonce is a secret this sign-in
        // generated, and an early-exit comparison in authentication code is the
        // habit worth not having even where the timing signal is weak.
        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new RuntimeException('The ID token does not carry the nonce this sign-in sent.');
        }

        $subject = $claims['sub'] ?? null;
        if (!is_string($subject) || $subject === '') {
            throw new RuntimeException('The ID token names no subject.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function checkAudience(array $claims): void
    {
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (!in_array($this->clientId, $audiences, true)) {
            throw new RuntimeException('The ID token was minted for a different audience.');
        }

        // `azp` names the party the token was actually minted for. When the
        // provider states it, it must be us — otherwise a token issued to a
        // second application that merely lists us as an audience would sign its
        // holder in here.
        //
        // When it is absent, the token is accepted. OIDC Core 3.1.3.7 makes
        // `azp` a SHOULD rather than a MUST, and refusing without it would turn
        // a spec-compliant provider into one this CMS cannot be used with —
        // which is a real cost against a narrow risk that only arises when a
        // provider both issues multi-audience tokens and declines to say who
        // for. That trade-off is stated here rather than left implicit.
        $party = $claims['azp'] ?? null;

        if (is_string($party) && $party !== $this->clientId) {
            throw new RuntimeException('The ID token names a different authorized party.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment, string $what): array
    {
        $raw = self::base64UrlDecode($segment);

        if ($raw === null) {
            throw new RuntimeException("The ID token {$what} is not valid base64url.");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("The ID token {$what} is not a JSON object.");
        }

        return $decoded;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
