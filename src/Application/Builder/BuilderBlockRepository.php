<?php

declare(strict_types=1);

namespace Click\Cms\Application\Builder;

/**
 * Snapshot-only reusable builder blocks stored as one JSON file each.
 *
 * A block is a named deep-copy of a subtree — `{ id, name, root, nodes }` — not
 * a live reference. Inserting one into a page regenerates every node id, so
 * editing or deleting a saved block never rewrites pages that already used it.
 * That is deliberate: authors treat a block like a paste template, not like a
 * shared component library with cascading updates.
 *
 * Files live under `data/builder-blocks/` beside other site-owned state, written
 * the same write-then-rename way as settings and theme choice so a concurrent
 * list never sees a truncated document.
 */
final class BuilderBlockRepository
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Every saved block, ordered by id so the palette does not reshuffle itself
     * between requests on filesystem whim.
     *
     * @return list<array{id: string, name: string, root: string, nodes: array<string, mixed>}>
     */
    public function all(): array
    {
        $entries = @scandir($this->directory);
        if ($entries === false) {
            // No directory yet is a fresh site, not an error.
            return [];
        }

        $blocks = [];
        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            $id = substr($entry, 0, -5);
            $block = $this->find($id);
            if ($block !== null) {
                $blocks[$id] = $block;
            }
        }

        ksort($blocks);

        return array_values($blocks);
    }

    /**
     * @return array{id: string, name: string, root: string, nodes: array<string, mixed>}|null
     */
    public function find(string $id): ?array
    {
        if (!$this->isSafeId($id)) {
            return null;
        }

        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->normalise($decoded, $id);
    }

    /**
     * Persist a new or updated snapshot. Generates a safe id from the name when
     * none is supplied, appending a short random suffix only when the slug is
     * already taken so "Hero" stays `hero` the first time.
     *
     * @param array{id?: string, name: string, root: string, nodes: array<string, mixed>} $block
     * @return array{id: string, name: string, root: string, nodes: array<string, mixed>}|null
     */
    public function save(array $block): ?array
    {
        $name = trim((string) ($block['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $root = $block['root'] ?? null;
        $nodes = $block['nodes'] ?? null;
        if (!is_string($root) || $root === '' || !is_array($nodes) || !isset($nodes[$root])) {
            return null;
        }

        $id = isset($block['id']) ? (string) $block['id'] : '';
        if ($id === '') {
            $id = $this->allocateId($name);
        }
        if (!$this->isSafeId($id)) {
            return null;
        }

        $payload = [
            'id' => $id,
            'name' => $name,
            'root' => $root,
            'nodes' => $nodes,
        ];

        if (!$this->write($id, $payload)) {
            return null;
        }

        return $payload;
    }

    public function delete(string $id): bool
    {
        if (!$this->isSafeId($id)) {
            return false;
        }

        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /* -------------------------------------------------------------- disk -- */

    private function pathFor(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }

    private function isSafeId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) === 1;
    }

    /**
     * Turn a display name into a path-safe slug, then uniquify if that file
     * already exists. The suffix is short on purpose: ids are for the filesystem
     * and API, not something an author types.
     */
    private function allocateId(string $name): string
    {
        $slug = $this->slugify($name);
        if ($slug === '' || !$this->isSafeId($slug)) {
            $slug = 'block';
        }

        if ($this->find($slug) === null) {
            return $slug;
        }

        // Collision: keep the readable stem and append enough entropy that a
        // second save of the same name does not overwrite the first.
        do {
            $candidate = $slug . '-' . bin2hex(random_bytes(3));
        } while ($this->find($candidate) !== null);

        return $candidate;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = preg_replace('/-{2,}/', '-', $slug) ?? '';
        $slug = substr($slug, 0, 40);
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{id: string, name: string, root: string, nodes: array<string, mixed>}|null
     */
    private function normalise(array $decoded, string $id): ?array
    {
        $name = trim((string) ($decoded['name'] ?? ''));
        $root = $decoded['root'] ?? null;
        $nodes = $decoded['nodes'] ?? null;

        if ($name === '' || !is_string($root) || $root === '' || !is_array($nodes) || !isset($nodes[$root])) {
            return null;
        }

        // The filename is the authority on id — a hand-edit that disagrees must
        // not produce two different answers depending on which field is read.
        return [
            'id' => $id,
            'name' => $name,
            'root' => $root,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param array{id: string, name: string, root: string, nodes: array<string, mixed>} $payload
     */
    private function write(string $id, array $payload): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return false;
        }

        $path = $this->pathFor($id);
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            return false;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
