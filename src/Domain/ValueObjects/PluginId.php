<?php

declare(strict_types=1);

namespace Click\Cms\Domain\ValueObjects;

use InvalidArgumentException;

class PluginId
{
    private function __construct(public readonly string $value) {}

    public static function fromString(string $value): self
    {
        if (empty(trim($value))) {
            throw new InvalidArgumentException('Plugin ID cannot be empty');
        }
        
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            throw new InvalidArgumentException('Plugin ID must contain only lowercase letters, numbers, and hyphens');
        }
        
        return new self($value);
    }

    public static function generate(string $name): self
    {
        $id = strtolower(preg_replace('/[^a-z0-9]/i', '-', $name));
        $id = preg_replace('/-+/', '-', $id);
        $id = trim($id, '-');
        
        return self::fromString($id);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
