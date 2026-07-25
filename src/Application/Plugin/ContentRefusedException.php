<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

use Click\Cms\Domain\ValueObjects\ContentKey;
use RuntimeException;

/**
 * A write or delete a plugin refused.
 *
 * Thrown rather than quietly doing nothing, for the same reason
 * {@see \Click\Cms\Infrastructure\Storage\StorageAuthorizationException} is:
 * a change that was refused and a change that succeeded must not look identical
 * to the caller. Returning normally from a `save()` that wrote nothing is how an
 * editor comes to believe their work is on disk when a plugin dropped it.
 *
 * Publishing can answer its refusals in-band because `PageService::publish()`
 * returns a result array with an `error` and a status. `save()` returns `void`,
 * so at this layer there is no in-band channel and an exception is the only way
 * to say no without lying. It carries the hook, the key and the plugin's reason
 * so a handler above can turn the refusal into a `409` — the request is well
 * formed and the caller is entitled to make it; what is wrong is the document.
 */
final class ContentRefusedException extends RuntimeException
{
    private function __construct(
        public readonly string $hook,
        public readonly ContentKey $key,
        public readonly string $reason,
    ) {
        // The reason is the message, because the reason is what an editor needs
        // to read. Prefixing it with machinery would bury it.
        parent::__construct($reason);
    }

    public static function refused(string $hook, ContentKey $key, string $reason): self
    {
        return new self($hook, $key, $reason);
    }
}
