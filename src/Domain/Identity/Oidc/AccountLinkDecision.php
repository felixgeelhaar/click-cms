<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity\Oidc;

/**
 * What {@see AccountLinkPolicy} decided about a verified sign-in.
 *
 * Four outcomes, and they are separate rather than a nullable username because
 * three of them require the caller to do different work: sign in, record the
 * link first, create an account first, or refuse with a reason.
 */
final class AccountLinkDecision
{
    public const SIGN_IN = 'sign-in';
    public const ADOPT = 'adopt';
    public const CREATE = 'create';
    public const REFUSE = 'refuse';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $username,
        public readonly ?string $role,
        public readonly ?string $reason,
    ) {}

    /** The account is already linked. */
    public static function signIn(string $username): self
    {
        return new self(self::SIGN_IN, $username, null, null);
    }

    /** An existing account matched; record the link on it, then sign in. */
    public static function adopt(string $username): self
    {
        return new self(self::ADOPT, $username, null, null);
    }

    public static function create(string $role): self
    {
        return new self(self::CREATE, null, $role, null);
    }

    public static function refuse(string $reason): self
    {
        return new self(self::REFUSE, null, null, $reason);
    }

    public function isRefusal(): bool
    {
        return $this->outcome === self::REFUSE;
    }
}
