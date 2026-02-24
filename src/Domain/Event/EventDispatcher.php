<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Event;

interface EventListenerInterface
{
    public function handle(Event $event): void;
    
    public function getPriority(): int;
    
    public function handles(): array;
}

abstract class EventListener implements EventListenerInterface
{
    public function getPriority(): int
    {
        return 0;
    }

    public function handles(): array
    {
        return [];
    }
}

class EventDispatcher
{
    private array $listeners = [];
    private array $sortedListeners = [];
    private bool $sorted = false;

    public function register(string $eventName, callable|EventListenerInterface $listener, int $priority = 0): self
    {
        $listenerObj = is_callable($listener) ? new CallableListener($listener, get_class($listener)) : $listener;
        
        $this->listeners[$eventName][] = [
            'listener' => $listenerObj,
            'priority' => $priority,
        ];
        
        $this->sorted = false;
        
        return $this;
    }

    public function registerMany(string $eventName, array $listeners): self
    {
        foreach ($listeners as $listener) {
            $this->register($eventName, $listener);
        }
        
        return $this;
    }

    public function dispatch(Event $event): array
    {
        $this->sortListeners();
        
        $results = [];
        $eventName = $event->name;
        
        if (isset($this->listeners[$eventName])) {
            foreach ($this->listeners[$eventName] as $entry) {
                /** @var EventListenerInterface $listener */
                $listener = $entry['listener'];
                
                try {
                    $result = $listener->handle($event);
                    $results[$eventName][] = [
                        'listener' => get_class($listener),
                        'result' => $result,
                    ];
                } catch (\Throwable $e) {
                    $results[$eventName][] = [
                        'listener' => get_class($listener),
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        if (isset($this->listeners['*'])) {
            foreach ($this->listeners['*'] as $entry) {
                /** @var EventListenerInterface $listener */
                $listener = $entry['listener'];
                
                try {
                    $result = $listener->handle($event);
                    $results['*'][] = [
                        'listener' => get_class($listener),
                        'result' => $result,
                    ];
                } catch (\Throwable $e) {
                    $results['*'][] = [
                        'listener' => get_class($listener),
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    public function dispatchSync(Event $event): void
    {
        $this->dispatch($event);
    }

    public function hasListeners(string $eventName): bool
    {
        return !empty($this->listeners[$eventName] ?? []);
    }

    public function getListeners(string $eventName): array
    {
        $this->sortListeners();
        
        return array_map(
            fn($entry) => $entry['listener'],
            $this->listeners[$eventName] ?? []
        );
    }

    public function unregister(string $eventName, callable|EventListenerInterface $listener): self
    {
        if (!isset($this->listeners[$eventName])) {
            return $this;
        }

        $listenerClass = is_object($listener) ? get_class($listener) : null;
        
        $this->listeners[$eventName] = array_filter(
            $this->listeners[$eventName],
            fn($entry) => $listenerClass !== get_class($entry['listener'])
        );

        if (empty($this->listeners[$eventName])) {
            unset($this->listeners[$eventName]);
        }

        return $this;
    }

    public function unregisterAll(?string $eventName = null): self
    {
        if ($eventName === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$eventName]);
        }
        
        $this->sorted = false;
        
        return $this;
    }

    public function clear(): self
    {
        return $this->unregisterAll();
    }

    private function sortListeners(): void
    {
        if ($this->sorted) {
            return;
        }

        foreach ($this->listeners as $eventName => &$entries) {
            usort($entries, fn($a, $b) => $b['priority'] - $a['priority']);
        }

        $this->sorted = true;
    }
}

class CallableListener implements EventListenerInterface
{
    private $callable;
    private string $className;

    public function __construct(callable $callable, string $className)
    {
        $this->callable = $callable;
        $this->className = $className;
    }

    public function handle(Event $event): void
    {
        ($this->callable)($event);
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handles(): array
    {
        return [];
    }
}
