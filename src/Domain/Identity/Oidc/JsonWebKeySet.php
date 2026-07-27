<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity\Oidc;

/**
 * The signing keys an identity provider publishes at its JWKS endpoint.
 *
 * Only RSA keys marked for signing are kept. An encryption key or an unfamiliar
 * key type is dropped rather than causing a failure, because a provider
 * legitimately publishes keys this CMS has no use for and refusing the whole set
 * over one of them would mean a working provider that cannot be used.
 *
 * What is *not* dropped quietly is a token naming a key that is not here — see
 * {@see IdTokenVerifier}. A missing key is the difference between "cannot check
 * this signature" and "this signature is fine", and only one of those is safe.
 */
final class JsonWebKeySet
{
    /**
     * @param array<string, JsonWebKey> $keys Keyed by `kid`.
     */
    private function __construct(private readonly array $keys) {}

    /**
     * @param array<string, mixed> $document The parsed JWKS document.
     */
    public static function fromArray(array $document): self
    {
        $keys = [];

        foreach ($document['keys'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = JsonWebKey::fromArray($entry);

            if ($key !== null) {
                $keys[$key->id] = $key;
            }
        }

        return new self($keys);
    }

    public function find(?string $keyId): ?JsonWebKey
    {
        // A provider with exactly one key may omit `kid` from its tokens, and
        // several do. With more than one key an absent `kid` is ambiguous, and
        // guessing would mean trying each until one verified — which is how a
        // retired key goes on being accepted long after it was rotated out.
        if ($keyId === null || $keyId === '') {
            return count($this->keys) === 1 ? reset($this->keys) : null;
        }

        return $this->keys[$keyId] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }
}
