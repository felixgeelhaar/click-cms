<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Event;

use DateTimeImmutable;

class Event
{
    public readonly string $name;
    public readonly DateTimeImmutable $occurredAt;
    public readonly array $payload;

    public function __construct(
        string $name,
        array $payload = []
    ) {
        $this->name = $name;
        $this->occurredAt = new DateTimeImmutable();
        $this->payload = $payload;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}

class PluginDiscoveredEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('plugin.discovered', $payload);
    }
}

class PluginActivatedEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('plugin.activated', $payload);
    }
}

class PluginDeactivatedEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('plugin.deactivated', $payload);
    }
}

class PluginInstalledEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('plugin.installed', $payload);
    }
}

class PluginUninstalledEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('plugin.uninstalled', $payload);
    }
}

class ContentCreatedEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('content.created', $payload);
    }
}

class ContentUpdatedEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('content.updated', $payload);
    }
}

class ContentDeletedEvent extends Event
{
    public function __construct(array $payload)
    {
        parent::__construct('content.deleted', $payload);
    }
}
