<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Plugin;

use Click\Cms\Domain\ValueObjects\PluginId;
use Click\Cms\Domain\ValueObjects\PluginVersion;

class Plugin
{
    private function __construct(
        public readonly PluginId $id,
        public readonly string $name,
        public readonly string $description,
        public readonly PluginVersion $version,
        public readonly string $author,
        public readonly array $dependencies,
        public readonly array $hooks,
        public readonly string $path,
        public readonly PluginState $state
    ) {}

    public static function create(
        PluginId $id,
        string $name,
        string $description,
        PluginVersion $version,
        string $author,
        array $dependencies,
        array $hooks,
        string $path
    ): self {
        return new self(
            id: $id,
            name: $name,
            description: $description,
            version: $version,
            author: $author,
            dependencies: $dependencies,
            hooks: $hooks,
            path: $path,
            state: PluginState::DISCOVERED
        );
    }

    public function activate(): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            description: $this->description,
            version: $this->version,
            author: $this->author,
            dependencies: $this->dependencies,
            hooks: $this->hooks,
            path: $this->path,
            state: PluginState::ACTIVATED
        );
    }

    public function deactivate(): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            description: $this->description,
            version: $this->version,
            author: $this->author,
            dependencies: $this->dependencies,
            hooks: $this->hooks,
            path: $this->path,
            state: PluginState::DEACTIVATED
        );
    }
}
