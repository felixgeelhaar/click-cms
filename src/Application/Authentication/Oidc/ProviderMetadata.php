<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication\Oidc;

use Click\Cms\Domain\Identity\Oidc\JsonWebKeySet;
use Click\Cms\Infrastructure\Http\JsonHttp;
use RuntimeException;

/**
 * The provider's published configuration and signing keys, fetched and cached.
 *
 * Discovery — `/.well-known/openid-configuration` — is how a provider says where
 * its endpoints are and which keys it signs with. Both change: keys rotate on a
 * schedule providers do not announce, and endpoints move during migrations.
 * Neither can be pinned in configuration without a site breaking at a moment
 * nobody chose.
 *
 * So both are fetched and cached on disk. The cache is what stops a login
 * costing two extra round trips to somebody else's server, and the refresh is
 * what stops a key rotation locking everybody out.
 *
 * ## The rotation problem, and what is done about it
 *
 * A cached JWKS that does not contain the key a token names has two possible
 * explanations: the provider rotated, or the token is forged. Guessing wrong in
 * one direction locks out a whole organisation; guessing wrong in the other
 * accepts forgeries. The answer is neither guess — the keys are re-fetched once,
 * and only once, and the token is then checked against what came back. A forged
 * token costs one extra fetch and is still refused; a rotation is picked up
 * immediately.
 *
 * The once matters: without it, a stream of tokens naming random key ids is a
 * way to make this site hammer its own identity provider.
 */
final class ProviderMetadata
{
    /** How long a discovery document is trusted before it is fetched again. */
    private const DISCOVERY_TTL = 86400;

    /** How long a key set is trusted before it is fetched again. */
    private const JWKS_TTL = 3600;

    private ?bool $refreshedThisRequest = null;

    public function __construct(
        private readonly OidcSettings $settings,
        private readonly string $cacheDirectory,
        private readonly JsonHttp $http = new JsonHttp(),
    ) {}

    /**
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public function discovery(): array
    {
        $cached = $this->readCache('discovery', self::DISCOVERY_TTL);

        if ($cached !== null) {
            return $cached;
        }

        $document = $this->http->getJson($this->settings->issuer . '/.well-known/openid-configuration');

        // The document says who it belongs to, and it has to be who was asked.
        // Without this check a redirect or a misconfigured proxy could hand back
        // some other provider's endpoints, and every token verified afterwards
        // would be checked against that provider's keys.
        if (($document['issuer'] ?? null) !== $this->settings->issuer) {
            throw new RuntimeException('The discovery document names a different issuer than the one configured.');
        }

        $this->writeCache('discovery', $document);

        return $document;
    }

    public function authorizationEndpoint(): string
    {
        return $this->endpoint('authorization_endpoint');
    }

    public function tokenEndpoint(): string
    {
        return $this->endpoint('token_endpoint');
    }

    public function endSessionEndpoint(): ?string
    {
        $value = $this->discovery()['end_session_endpoint'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The provider's signing keys.
     *
     * @param bool $forceRefresh Re-fetch even if the cache is fresh. Used once
     *        per request when a token names an unknown key — see the class
     *        docblock.
     */
    public function keys(bool $forceRefresh = false): JsonWebKeySet
    {
        if ($forceRefresh) {
            // Once per request. Otherwise a stream of tokens naming random key
            // ids becomes a way to make this site hammer its own provider.
            if ($this->refreshedThisRequest === true) {
                return JsonWebKeySet::fromArray($this->readCache('jwks', PHP_INT_MAX) ?? []);
            }

            $this->refreshedThisRequest = true;
        }

        $cached = $forceRefresh ? null : $this->readCache('jwks', self::JWKS_TTL);

        if ($cached !== null) {
            return JsonWebKeySet::fromArray($cached);
        }

        $uri = $this->discovery()['jwks_uri'] ?? null;

        if (!is_string($uri) || $uri === '') {
            throw new RuntimeException('The identity provider publishes no jwks_uri.');
        }

        $document = $this->http->getJson($uri);
        $this->writeCache('jwks', $document);

        return JsonWebKeySet::fromArray($document);
    }

    private function endpoint(string $name): string
    {
        $value = $this->discovery()[$name] ?? null;

        if (!is_string($value) || !str_starts_with(strtolower($value), 'https://')) {
            throw new RuntimeException("The identity provider publishes no usable {$name}.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $name, int $ttl): ?array
    {
        $path = $this->pathFor($name);

        if (!is_file($path)) {
            return null;
        }

        $age = time() - (int) @filemtime($path);

        if ($age > $ttl) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function writeCache(string $name, array $document): void
    {
        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0o775, true) && !is_dir($this->cacheDirectory)) {
            // A cache that cannot be written is a performance problem, not a
            // correctness one — every request simply fetches. Not worth failing
            // a sign-in over.
            return;
        }

        $path = $this->pathFor($name);
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, json_encode($document, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * One cache per issuer, keyed by a hash of it, so changing the configured
     * provider cannot read the previous one's cached keys.
     */
    private function pathFor(string $name): string
    {
        $key = substr(hash('sha256', $this->settings->issuer), 0, 16);

        return "{$this->cacheDirectory}/{$key}-{$name}.json";
    }
}
