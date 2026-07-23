<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

/**
 * A read-only delivery API for published pages, shaped like GraphQL.
 *
 * This deliberately does much less than the version it replaces, because that
 * version did dangerous things:
 *
 *  - It answered `{ user(username: "admin") { password } }`. A user document
 *    holds the password hash under `data.password`, and the field filter reached
 *    straight into `data`, so any caller who reached this endpoint could read
 *    every account's hash. Behind authentication that is already a privilege
 *    escalation — an author reading the admin's hash — and it made the endpoint
 *    impossible to expose for the one thing a delivery API is for.
 *  - It carried a `createPage` mutation that called storage directly, bypassing
 *    the schema validation every other write goes through. That let arbitrary,
 *    undeclared content be written, which is the exact guarantee the schema
 *    exists to hold.
 *
 * A delivery API serves published content to a front end that has no account. It
 * has no business reading accounts and no business writing anything. So this
 * exposes exactly two queries — `pages` and `page(slug:)` — over a fixed
 * allowlist of fields, reads only what is published, and never writes. With that
 * surface it is safe to answer anonymously, which is registered in the core
 * kernel's public allowlist.
 */
class Plugin_graphql_api extends \Click\Cms\Application\Plugin\BasePlugin
{
    /**
     * The only fields a query may ask for. Everything else is refused, so a new
     * internal field on a document can never become readable here by accident —
     * which is precisely how the password hash used to be reachable.
     */
    private const PUBLIC_FIELDS = ['slug', 'title', 'sections', 'locale', 'updatedAt'];

    public function getPluginId(): string
    {
        return 'graphql';
    }

    public function getPluginName(): string
    {
        return 'GraphQL API';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'POST /api/graphql' => [$this, 'handleGraphQL'],
            'GET /api/graphql' => [$this, 'handleGraphQL'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handleGraphQL(): array
    {
        $input = json_decode((string) file_get_contents('php://input'), true);

        // A GET, or a POST with the query in the URL, so the endpoint is usable
        // from a browser and from a client that sends the query either way.
        $query = is_array($input) && isset($input['query'])
            ? (string) $input['query']
            : (string) ($_GET['query'] ?? '');

        if (trim($query) === '') {
            return ['errors' => [['message' => 'No query provided.']]];
        }

        // A mutation reaching a read-only delivery endpoint is a category error,
        // not a syntax one: say so rather than trying to parse it.
        if (preg_match('/\bmutation\b/', $query) === 1) {
            return ['errors' => [['message' => 'This endpoint is read-only. Content is written through the management API.']]];
        }

        try {
            return ['data' => $this->executeQuery($query)];
        } catch (\Throwable $e) {
            return ['errors' => [['message' => $e->getMessage()]]];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function executeQuery(string $query): array
    {
        $contentService = $this->pluginManager->getContentService();

        // page(slug: "...") — a single published page. Checked before the plural
        // so "pages" inside "page(" does not match the list branch.
        if (preg_match('/page\s*\(\s*slug:\s*["\']([^"\']+)["\']\s*\)/', $query, $matches) === 1) {
            $fields = $this->requestedFields($query, 'page');

            // page() reads the published document only — the same source the
            // public site renders from — so a working copy never leaks here.
            $page = $contentService->page($matches[1]);

            return ['page' => $page === null ? null : $this->project($page->toArray(), $fields)];
        }

        // pages — every published page.
        if (preg_match('/\bpages\b/', $query) === 1) {
            $fields = $this->requestedFields($query, 'pages');

            return [
                'pages' => array_map(
                    fn ($p): array => $this->project($p->toArray(), $fields),
                    $contentService->pages()
                ),
            ];
        }

        // Named so a caller who tried `users` or `user(...)` learns those are
        // gone on purpose, rather than getting a bare "not recognized".
        if (preg_match('/\busers?\b/', $query) === 1) {
            throw new \RuntimeException('Accounts are not readable through the delivery API.');
        }

        throw new \RuntimeException('Only the "pages" and "page(slug:)" queries are supported.');
    }

    /**
     * The fields a query selected, narrowed to the ones a delivery caller is
     * allowed to see. An unknown or internal field is dropped rather than
     * refused, which keeps a harmless typo from failing an otherwise valid query
     * while still making the field unreadable.
     *
     * @return list<string>
     */
    private function requestedFields(string $query, string $root): array
    {
        if (preg_match('/' . preg_quote($root, '/') . '[^{]*\{([^}]*)\}/s', $query, $matches) !== 1) {
            // No selection set named: return the whole allowlist rather than
            // nothing, so `{ pages }` is a valid "everything public".
            return self::PUBLIC_FIELDS;
        }

        $selected = array_filter(array_map('trim', preg_split('/[\s,]+/', $matches[1]) ?: []));

        return array_values(array_intersect(self::PUBLIC_FIELDS, $selected));
    }

    /**
     * Build the response object from a content array, taking only allowlisted
     * fields and looking them up at the top level or under `data` — never
     * anywhere else, so nothing outside the allowlist can be reached.
     *
     * @param array<string, mixed> $content
     * @param list<string>         $fields
     * @return array<string, mixed>
     */
    private function project(array $content, array $fields): array
    {
        $flat = array_merge($content, is_array($content['data'] ?? null) ? $content['data'] : []);

        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $flat)) {
                $out[$field] = $flat[$field];
            }
        }

        return $out;
    }
}
