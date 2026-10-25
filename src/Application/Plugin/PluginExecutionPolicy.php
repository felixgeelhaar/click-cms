<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

/** Deployment-controlled admission, not a PHP sandbox. Unset preserves existing sites. */
final class PluginExecutionPolicy
{
    private function __construct(private readonly ?array $allowedIds) {}

    public static function fromEnvironment(): self
    {
        return self::fromValue(getenv('CLICK_ALLOWED_PLUGINS'));
    }

    public static function fromValue(string|false $value): self
    {
        if ($value === false) {
            return new self(null);
        }
        if (trim($value) === '') {
            return new self([]);
        }
        $ids = array_map('trim', explode(',', $value));
        foreach ($ids as $id) {
            // A typo must never silently broaden which code is admitted.
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $id) !== 1) {
                return new self([]);
            }
        }
        return new self(array_values(array_unique($ids)));
    }

    public function mode(): string
    {
        return $this->allowedIds === null ? 'unrestricted' : ($this->allowedIds === [] ? 'disabled' : 'allowlist');
    }

    public function allows(string $id): bool
    {
        return $this->allowedIds === null || in_array($id, $this->allowedIds, true);
    }
}
