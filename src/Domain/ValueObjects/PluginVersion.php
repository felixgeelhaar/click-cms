<?php

declare(strict_types=1);

namespace Click\Cms\Domain\ValueObjects;

use InvalidArgumentException;

class PluginVersion
{
    private function __construct(public readonly string $value) {}

    public static function fromString(string $version): self
    {
        if (!preg_match('/^\d+\.\d+\.\d+(-[a-z0-9.-]+)?$/i', $version)) {
            throw new InvalidArgumentException('Invalid version format. Use semver (e.g., 1.0.0)');
        }
        
        return new self($version);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
