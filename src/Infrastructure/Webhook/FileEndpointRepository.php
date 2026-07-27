<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Webhook;

use Click\Cms\Domain\Webhook\EndpointRepository;
use Click\Cms\Domain\Webhook\WebhookEndpoint;
use RuntimeException;

/**
 * The endpoint list as a single JSON file under `data/webhooks/`.
 *
 * One file rather than one per endpoint, unlike the delivery queue next door.
 * The access pattern decides it: every event reads the whole list to find
 * subscribers, so a file per endpoint would be N reads to answer a question one
 * read answers, and there are realistically a handful of endpoints on any site.
 *
 * `data/` and not `content/` for the reason schedules are: this is operational
 * state, not content. It also holds signing secrets, which must not travel in a
 * content export.
 */
final class FileEndpointRepository implements EndpointRepository
{
    private readonly string $path;

    public function __construct(private readonly string $directory)
    {
        $this->path = $this->directory . '/endpoints.json';
    }

    public function all(): array
    {
        $rows = $this->read();

        return array_values(array_map(
            static fn (array $row): WebhookEndpoint => WebhookEndpoint::fromArray($row),
            $rows
        ));
    }

    public function find(string $id): ?WebhookEndpoint
    {
        foreach ($this->all() as $endpoint) {
            if ($endpoint->id === $id) {
                return $endpoint;
            }
        }

        return null;
    }

    public function save(WebhookEndpoint $endpoint): void
    {
        $rows = $this->read();
        $replaced = false;

        foreach ($rows as $index => $row) {
            if (($row['id'] ?? null) === $endpoint->id) {
                $rows[$index] = $endpoint->toArray();
                $replaced = true;
                break;
            }
        }

        if (!$replaced) {
            $rows[] = $endpoint->toArray();
        }

        $this->write(array_values($rows));
    }

    public function delete(string $id): bool
    {
        $rows = $this->read();
        $kept = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['id'] ?? null) !== $id
        ));

        if (count($kept) === count($rows)) {
            return false;
        }

        $this->write($kept);

        return true;
    }

    public function subscribedTo(string $event): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (WebhookEndpoint $e): bool => $e->subscribesTo($event)
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Rows that are not arrays are dropped rather than thrown over: this
        // file is read on the write path of every content save, and a hand-edit
        // that broke one row must not make the site unsaveable.
        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function write(array $rows): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create the webhooks directory: {$this->directory}");
        }

        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write the endpoint list: {$this->path}");
        }

        // The file holds signing secrets, so it is readable by its owner alone
        // rather than by whatever the umask happened to allow. Set on the temp
        // file before the rename, so there is no window in which the real path
        // exists with wider permissions.
        @chmod($tmp, 0o600);

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to commit the endpoint list: {$this->path}");
        }
    }
}
