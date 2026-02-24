<?php

declare(strict_types=1);

namespace Click\Cms\Application\Event;

use Click\Cms\Domain\Event\Event;
use Click\Cms\Domain\Event\EventDispatcher;

class EventBus
{
    private EventDispatcher $dispatcher;
    private array $eventHistory = [];

    public function __construct(EventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function publish(Event $event): array
    {
        $this->eventHistory[] = $event;
        
        return $this->dispatcher->dispatch($event);
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): self
    {
        $this->dispatcher->register($eventName, $handler, $priority);
        
        return $this;
    }

    public function hasListeners(string $eventName): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    public function getHistory(): array
    {
        return $this->eventHistory;
    }

    public function clearHistory(): self
    {
        $this->eventHistory = [];
        
        return $this;
    }

    public function getHistoryByType(string $eventName): array
    {
        return array_filter(
            $this->eventHistory,
            fn($event) => $event->name === $eventName
        );
    }
}
