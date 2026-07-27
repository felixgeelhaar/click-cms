<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Webhook;

use Click\Cms\Domain\Webhook\DeliveryQueue;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use RuntimeException;

/**
 * The delivery queue as one file per delivery under `data/webhooks/queue/`.
 *
 * A file each, unlike the endpoint list next door, because the access pattern is
 * the opposite: many short-lived rows, appended from web requests and updated
 * from a sweep that may be running at the same time. A single JSON array would
 * make every enqueue a read-modify-write of the whole queue, which two
 * concurrent saves would interleave and lose.
 *
 * The filename carries the enqueue time — `<seconds>-<random>.json` — so a
 * directory listing sorts into rough chronological order without opening
 * anything, which is what lets {@see due()} stop early on a large backlog.
 */
final class FileDeliveryQueue implements DeliveryQueue
{
    public function __construct(private readonly string $directory) {}

    public function push(WebhookDelivery $delivery): void
    {
        $this->writeTo($this->pathFor($delivery), $delivery);
    }

    public function due(int $now, int $limit): array
    {
        $found = [];

        foreach ($this->files() as $path) {
            if (count($found) >= $limit) {
                break;
            }

            $delivery = $this->read($path);

            if ($delivery !== null && $delivery->isDueAt($now)) {
                $found[] = $delivery;
            }
        }

        return $found;
    }

    public function update(WebhookDelivery $delivery): void
    {
        $path = $this->existingPathFor($delivery->id);

        // A delivery whose file vanished mid-sweep — pruned by a concurrent run,
        // deleted by hand — is not re-created. Writing it back would resurrect a
        // row somebody removed, and the attempt it records has already happened.
        if ($path === null) {
            return;
        }

        $this->writeTo($path, $delivery);
    }

    public function prune(int $before): int
    {
        $removed = 0;

        foreach ($this->files() as $path) {
            $delivery = $this->read($path);

            // Unreadable rows are pruned too. They cannot be delivered and
            // nothing can report on them, so leaving them is only a slow leak.
            if ($delivery === null) {
                @unlink($path) && $removed++;
                continue;
            }

            if ($delivery->status === WebhookDelivery::PENDING || $delivery->createdAt >= $before) {
                continue;
            }

            @unlink($path) && $removed++;
        }

        return $removed;
    }

    public function recent(int $limit, ?string $status = null): array
    {
        $files = $this->files();

        // Newest first, which is the opposite of the sweep's order: a person
        // looking at a delivery log wants the last thing that happened, and a
        // sweep wants the oldest thing still waiting.
        $files = array_reverse($files);

        $found = [];

        foreach ($files as $path) {
            if (count($found) >= $limit) {
                break;
            }

            $delivery = $this->read($path);

            if ($delivery === null) {
                continue;
            }

            if ($status !== null && $delivery->status !== $status) {
                continue;
            }

            $found[] = $delivery;
        }

        return $found;
    }

    /**
     * Queue files in rough chronological order.
     *
     * `glob` sorts lexicographically, and the zero-padded seconds prefix makes
     * that chronological until the year 33658.
     *
     * @return list<string>
     */
    private function files(): array
    {
        $paths = glob($this->directory . '/*.json');

        return $paths === false ? [] : array_values($paths);
    }

    private function pathFor(WebhookDelivery $delivery): string
    {
        return sprintf('%s/%011d-%s.json', $this->directory, $delivery->createdAt, $delivery->id);
    }

    private function existingPathFor(string $id): ?string
    {
        $matches = glob($this->directory . '/*-' . $id . '.json');

        return is_array($matches) && $matches !== [] ? $matches[0] : null;
    }

    private function read(string $path): ?WebhookDelivery
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? WebhookDelivery::fromArray($decoded) : null;
    }

    private function writeTo(string $path, WebhookDelivery $delivery): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create the webhook queue directory: {$this->directory}");
        }

        $json = json_encode($delivery->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write a webhook delivery: {$path}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to commit a webhook delivery: {$path}");
        }
    }
}
