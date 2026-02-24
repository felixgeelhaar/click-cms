<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_graphql_api extends \Click\Cms\Application\Plugin\BasePlugin
{
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

    public function handleGraphQL(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['query'])) {
            return ['errors' => [['message' => 'No query provided']]];
        }

        $query = $input['query'];
        $variables = $input['variables'] ?? [];

        try {
            $result = $this->executeQuery($query, $variables);
            return ['data' => $result];
        } catch (\Exception $e) {
            return ['errors' => [['message' => $e->getMessage()]]];
        }
    }

    private function executeQuery(string $query, array $variables): array
    {
        $contentService = $this->pluginManager->getContentService();
        
        // Simple GraphQL-like query parsing
        // Support: { pages { title content } }
        // Support: { page(slug: "home") { title content } }
        
        if (strpos($query, 'pages') !== false && strpos($query, 'page(') === false) {
            // List all pages
            $pages = $contentService->pages();
            $fields = $this->extractFields($query, 'pages');
            
            return [
                'pages' => array_map(fn($p) => $this->filterFields($p->toArray(), $fields), $pages)
            ];
        }
        
        if (preg_match('/page\s*\(\s*slug:\s*["\']([^"\']+)["\']\s*\)/', $query, $matches)) {
            // Get single page
            $slug = $matches[1];
            $page = $contentService->page($slug);
            
            if (!$page) {
                return ['page' => null];
            }
            
            $fields = $this->extractFields($query, 'page');
            return ['page' => $this->filterFields($page->toArray(), $fields)];
        }
        
        if (strpos($query, 'users') !== false && strpos($query, 'user(') === false) {
            // List all users
            $users = $contentService->all('user');
            $fields = $this->extractFields($query, 'users');
            
            return [
                'users' => array_map(fn($u) => $this->filterFields($u->toArray(), $fields), $users)
            ];
        }
        
        if (preg_match('/user\s*\(\s*username:\s*["\']([^"\']+)["\']\s*\)/', $query, $matches)) {
            // Get single user
            $username = $matches[1];
            $user = $contentService->user($username);
            
            if (!$user) {
                return ['user' => null];
            }
            
            $fields = $this->extractFields($query, 'user');
            return ['user' => $this->filterFields($user->toArray(), $fields)];
        }
        
        if (preg_match('/mutation\s*\{[^}]*createPage/', $query)) {
            // Create page mutation
            return $this->handleCreatePage($query, $variables);
        }
        
        return ['error' => 'Query not recognized'];
    }

    private function extractFields(string $query, string $rootField): array
    {
        // Extract fields between { }
        if (preg_match('/' . $rootField . '[^}]*\{([^}]*)\}/s', $query, $matches)) {
            $content = $matches[1];
            // Split by whitespace and filter
            return array_filter(array_map('trim', preg_split('/[\s,]+/', $content)));
        }
        
        return [];
    }

    private function filterFields(array $data, array $fields): array
    {
        if (empty($fields)) {
            return $data;
        }
        
        $result = [];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $result[$field] = $data[$field];
            } elseif (isset($data['data'][$field])) {
                $result[$field] = $data['data'][$field];
            }
        }
        
        return $result;
    }

    private function handleCreatePage(string $query, array $variables): array
    {
        $contentService = $this->pluginManager->getContentService();
        
        // Extract input from query or variables
        $input = $variables['input'] ?? [];
        
        if (!isset($input['title'])) {
            return ['errors' => [['message' => 'Title is required']]];
        }

        $slugInput = trim((string) ($input['slug'] ?? ''));
        if ($slugInput !== '') {
            $slug = $this->slugify($slugInput);
        } else {
            $slug = $this->slugify($input['title'] ?? 'untitled');
        }

        if ($slug === '') {
            $slug = 'untitled';
        }
        
        $content = \Click\Cms\Domain\Content\Content::create(
            \Click\Cms\Domain\ValueObjects\ContentKey::page($slug),
            $input
        );
        
        $contentService->save($content);
        
        return ['createPage' => $content->toArray()];
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }
}
