<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\ValueObjects\ContentKey;
use RuntimeException;

/**
 * A mutating storage operation the authorizer refused to allow.
 *
 * Thrown rather than returning false or silently doing nothing, because a
 * write that was denied and a write that failed are different facts and the
 * caller must be able to tell them apart. Swallowing the denial — writing
 * nothing and reporting success — is the silent degradation this codebase
 * keeps being bitten by: the editor would believe their change was saved when
 * policy had quietly dropped it on the floor.
 *
 * It carries the operation and key so a handler above can turn the refusal
 * into the right response (a 403, an audit note) without having to reconstruct
 * what was being attempted.
 */
final class StorageAuthorizationException extends RuntimeException
{
    private function __construct(
        public readonly string $operation,
        public readonly ContentKey $key,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function denied(string $operation, ContentKey $key): self
    {
        return new self(
            $operation,
            $key,
            sprintf(
                'Storage operation "%s" was denied for "%s" by the authorizer.',
                $operation,
                $key->toString(),
            ),
        );
    }
}
