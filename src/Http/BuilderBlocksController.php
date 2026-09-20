<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Builder\BuilderBlockRepository;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * Snapshot-only builder blocks: list, save and delete named subtrees.
 *
 * Gated on {@see Capability::UseFreeFormBuilder} — the same bar as editing a
 * free-form page layout — because a saved block is a paste template for that
 * editor, not a site-wide setting. Auth still answers 401 first so an anonymous
 * caller is not told whether free-form is available.
 *
 * Persistence is thin: shape checks happen here; disk lives in
 * {@see BuilderBlockRepository}.
 */
final class BuilderBlocksController
{
    /**
     * @param callable(): array<string, mixed> $currentUser Resolves the signed-in
     *        user for the current request, or [] when anonymous.
     */
    public function __construct(
        private readonly BuilderBlockRepository $blocks,
        private readonly mixed $currentUser,
    ) {
    }

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
            'GET /api/builder/blocks' => [$this, 'list'],
            'POST /api/builder/blocks' => [$this, 'create'],
            'DELETE /api/builder/blocks/:id' => [$this, 'delete'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $denied = $this->gate();
        if ($denied !== null) {
            return $denied;
        }

        return ['data' => $this->blocks->all()];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $denied = $this->gate();
        if ($denied !== null) {
            return $denied;
        }

        $body = $this->jsonBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return ['status' => 400, 'error' => 'Name the block.'];
        }

        $root = $body['root'] ?? null;
        $nodes = $body['nodes'] ?? null;
        $invalid = $this->validateSubtree($root, $nodes);
        if ($invalid !== null) {
            return ['status' => 400, 'error' => $invalid];
        }

        /** @var array<string, mixed> $nodes */
        $saved = $this->blocks->save([
            'name' => $name,
            'root' => (string) $root,
            'nodes' => $nodes,
        ]);
        if ($saved === null) {
            return ['status' => 500, 'error' => 'Could not save the block.'];
        }

        return ['status' => 201, 'data' => $saved];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        $denied = $this->gate();
        if ($denied !== null) {
            return $denied;
        }

        if (!$this->blocks->delete($id)) {
            return ['status' => 404, 'error' => 'Block not found'];
        }

        return ['data' => ['deleted' => true, 'id' => $id]];
    }

    /**
     * Signed-in first, then the free-form capability. Order matters: a 403 to an
     * anonymous caller would advertise that the endpoint exists and what it
     * requires.
     *
     * @return array<string, mixed>|null
     */
    private function gate(): ?array
    {
        $user = $this->user();
        if ($user === []) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }
        if (!Role::fromName($user['role'] ?? null)->can(Capability::UseFreeFormBuilder)) {
            return ['status' => 403, 'error' => 'You do not have permission to manage builder blocks.'];
        }

        return null;
    }

    /**
     * A block is a subtree snapshot: root must name a node in the map, and every
     * entry must carry a children array so the editor can deep-copy without
     * repairing shape on insert.
     *
     * @return string|null Error message, or null when the subtree is usable.
     */
    private function validateSubtree(mixed $root, mixed $nodes): ?string
    {
        if (!is_string($root) || $root === '') {
            return 'The block needs a root node id.';
        }
        // JSON objects arrive as associative arrays; a list would mean the
        // caller sent an array of nodes rather than the id→node map the editor
        // and the public renderer both expect.
        if (!is_array($nodes) || $nodes === [] || array_is_list($nodes)) {
            return 'The block needs a map of nodes.';
        }
        if (!isset($nodes[$root]) || !is_array($nodes[$root])) {
            return 'The root id must point at a node in the map.';
        }

        foreach ($nodes as $id => $node) {
            if (!is_string($id) || $id === '' || !is_array($node)) {
                return 'Each node must be an object keyed by its id.';
            }
            if (!array_key_exists('children', $node) || !is_array($node['children'])) {
                return 'Every node needs a children array.';
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        return ($this->currentUser)();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return $_POST;
        }

        $decoded = json_decode($input, true);

        return is_array($decoded) ? $decoded : [];
    }
}
