<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

use Click\Cms\Domain\Plugin\Plugin;
use Click\Cms\Domain\Plugin\PluginState;
use Click\Cms\Domain\ValueObjects\PluginId;
use Click\Cms\Domain\ValueObjects\PluginVersion;

class PluginMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly string $author,
        public readonly array $dependencies,
        public readonly array $hooks
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? 'Unnamed Plugin',
            description: $data['description'] ?? '',
            version: $data['version'] ?? '1.0.0',
            author: $data['author'] ?? 'Unknown',
            dependencies: $data['dependencies'] ?? [],
            hooks: $data['hooks'] ?? []
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'author' => $this->author,
            'dependencies' => $this->dependencies,
            'hooks' => $this->hooks,
        ];
    }
}
