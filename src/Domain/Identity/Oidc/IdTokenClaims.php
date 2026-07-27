<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity\Oidc;

/**
 * What a verified ID token says about the person who signed in.
 *
 * Only produced by {@see IdTokenVerifier}, and only after every check passed —
 * so holding one of these is the statement "this token was genuine". A struct
 * anyone could construct from an unverified payload would make that guarantee
 * meaningless, which is why the constructor is not public.
 */
final class IdTokenClaims
{
    /**
     * @param array<string, mixed> $raw Every claim, for role mapping.
     */
    private function __construct(
        /** The provider's stable identifier. Never an email — see below. */
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly array $raw,
    ) {}

    /**
     * @param array<string, mixed> $claims
     * @internal Only {@see IdTokenVerifier} may call this.
     */
    public static function verified(array $claims): self
    {
        $email = $claims['email'] ?? null;
        $name = $claims['name'] ?? null;

        return new self(
            (string) $claims['sub'],
            is_string($email) && $email !== '' ? $email : null,
            // Absent means unverified. A provider that does not say has not
            // promised, and the linking rules treat an unverified address as
            // no address at all — otherwise anyone able to set their own email
            // at the provider could claim somebody else's account here.
            ($claims['email_verified'] ?? false) === true,
            is_string($name) && $name !== '' ? $name : null,
            $claims,
        );
    }

    /**
     * A claim by name, for role mapping. Dotted paths are not supported: a
     * configuration language nobody asked for is worse than a limitation
     * somebody can read.
     */
    public function claim(string $name): mixed
    {
        return $this->raw[$name] ?? null;
    }
}
