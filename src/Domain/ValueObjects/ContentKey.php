<?php

declare(strict_types=1);

namespace Click\Cms\Domain\ValueObjects;

use InvalidArgumentException;

class ContentKey
{
    private function __construct(
        public readonly string $type,
        public readonly string $slug
    ) {}

    public static function fromString(string $key): self
    {
        $parts = explode(':', $key, 2);
        
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('ContentKey must be in format "type:slug"');
        }
        
        [$type, $slug] = $parts;
        
        if (empty(trim($type)) || empty(trim($slug))) {
            throw new InvalidArgumentException('ContentKey type and slug cannot be empty');
        }
        
        return new self($type, $slug);
    }

    public static function page(string $slug): self
    {
        return new self('page', $slug);
    }

    public static function user(string $slug): self
    {
        return new self('user', $slug);
    }

    public static function media(string $slug): self
    {
        return new self('media', $slug);
    }

    public function toString(): string
    {
        return "{$this->type}:{$this->slug}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
