<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_tags extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $taxonomyDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->taxonomyDir = $basePath . '/data/taxonomy';
        
        if (!is_dir($this->taxonomyDir)) {
            mkdir($this->taxonomyDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'tags';
    }

    public function getPluginName(): string
    {
        return 'Tags & Categories';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function deactivate(): bool
    {
        return true;
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/taxonomies' => [$this, 'getTaxonomies'],
            'POST /api/taxonomies' => [$this, 'createTaxonomy'],
            'GET /api/taxonomies/:type' => [$this, 'getTaxonomyTerms'],
            'POST /api/taxonomies/:type/terms' => [$this, 'createTerm'],
            'PUT /api/taxonomies/:type/terms/:slug' => [$this, 'updateTerm'],
            'DELETE /api/taxonomies/:type/terms/:slug' => [$this, 'deleteTerm'],
            'GET /api/taxonomies/:type/terms/:slug/pages' => [$this, 'getTermPages'],
        ];
    }

    private function getTaxonomyTypes(): array
    {
        return [
            'categories' => [
                'name' => 'Categories',
                'hierarchical' => true,
                'slug' => 'category',
            ],
            'tags' => [
                'name' => 'Tags',
                'hierarchical' => false,
                'slug' => 'tag',
            ],
        ];
    }

    private function loadTaxonomies(): array
    {
        $types = $this->getTaxonomyTypes();
        $taxonomies = [];
        
        foreach ($types as $type => $config) {
            $taxonomies[$type] = $config;
            $taxonomies[$type]['terms'] = $this->loadTerms($type);
        }
        
        return $taxonomies;
    }

    private function loadTerms(string $type): array
    {
        $file = $this->taxonomyDir . '/' . $type . '.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveTerms(string $type, array $terms): void
    {
        $file = $this->taxonomyDir . '/' . $type . '.json';
        file_put_contents($file, json_encode($terms, JSON_PRETTY_PRINT));
    }

    public function getTaxonomies(): array
    {
        return ['data' => $this->loadTaxonomies()];
    }

    public function createTaxonomy(): array
    {
        $data = $this->getJsonBody();
        
        $type = $data['type'] ?? '';
        $name = $data['name'] ?? '';
        
        if (empty($type) || empty($name)) {
            return ['error' => 'Type and name are required', 'status' => 400];
        }
        
        if (!in_array($type, ['categories', 'tags'])) {
            return ['error' => 'Invalid taxonomy type', 'status' => 400];
        }
        
        return ['data' => [
            'type' => $type,
            'name' => $name,
            'hierarchical' => $type === 'categories',
        ]];
    }

    public function getTaxonomyTerms(string $type): array
    {
        $terms = $this->loadTerms($type);
        
        return ['data' => $terms];
    }

    public function createTerm(string $type): array
    {
        $data = $this->getJsonBody();
        
        $name = $data['name'] ?? '';
        if (empty($name)) {
            return ['error' => 'Term name is required', 'status' => 400];
        }
        
        $slug = $data['slug'] ?? $this->slugify($name);
        
        $terms = $this->loadTerms($type);
        
        foreach ($terms as $term) {
            if ($term['slug'] === $slug) {
                return ['error' => 'Term with this slug already exists', 'status' => 409];
            }
        }
        
        $term = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'slug' => $slug,
            'description' => $data['description'] ?? '',
            'parent' => $data['parent'] ?? null,
            'count' => 0,
            'created_at' => date('c'),
        ];
        
        $terms[] = $term;
        $this->saveTerms($type, $terms);
        
        return ['data' => $term, 'status' => 201];
    }

    public function updateTerm(string $type, string $slug): array
    {
        $data = $this->getJsonBody();
        $terms = $this->loadTerms($type);
        
        $found = false;
        foreach ($terms as &$term) {
            if ($term['slug'] === $slug) {
                if (isset($data['name'])) {
                    $term['name'] = $data['name'];
                }
                if (isset($data['description'])) {
                    $term['description'] = $data['description'];
                }
                if (isset($data['parent'])) {
                    $term['parent'] = $data['parent'];
                }
                
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['error' => 'Term not found', 'status' => 404];
        }
        
        $this->saveTerms($type, $terms);
        
        return ['data' => ['updated' => true, 'slug' => $slug]];
    }

    public function deleteTerm(string $type, string $slug): array
    {
        $terms = $this->loadTerms($type);
        
        $newTerms = array_filter($terms, fn($t) => $t['slug'] !== $slug);
        
        if (count($newTerms) === count($terms)) {
            return ['error' => 'Term not found', 'status' => 404];
        }
        
        $this->saveTerms($type, array_values($newTerms));
        
        return ['data' => ['deleted' => true, 'slug' => $slug]];
    }

    public function getTermPages(string $type, string $slug): array
    {
        $contentService = $this->pluginManager->getContentService();
        $pages = $contentService->pages();
        
        $matchingPages = [];
        
        foreach ($pages as $page) {
            $data = $page->data ?? [];
            $taxonomies = $data['taxonomies'] ?? [];
            
            $typeTerms = $taxonomies[$type] ?? [];
            
            if (in_array($slug, $typeTerms)) {
                $matchingPages[] = [
                    'slug' => $page->slug(),
                    'title' => $page->title(),
                    'status' => $data['status'] ?? 'draft',
                ];
            }
        }
        
        return ['data' => $matchingPages];
    }

    public function addTermToPage(string $slug, string $termSlug, string $type = 'tags'): void
    {
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);
        
        if (!$page) {
            return;
        }
        
        $data = $page->data;
        
        if (!isset($data['taxonomies'])) {
            $data['taxonomies'] = [];
        }
        
        if (!isset($data['taxonomies'][$type])) {
            $data['taxonomies'][$type] = [];
        }
        
        if (!in_array($termSlug, $data['taxonomies'][$type])) {
            $data['taxonomies'][$type][] = $termSlug;
            
            $updated = $page->update($data);
            $contentService->save($updated);
            
            $this->incrementTermCount($type, $termSlug);
        }
    }

    public function removeTermFromPage(string $slug, string $termSlug, string $type = 'tags'): void
    {
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);
        
        if (!$page) {
            return;
        }
        
        $data = $page->data;
        
        if (!isset($data['taxonomies'][$type])) {
            return;
        }
        
        $key = array_search($termSlug, $data['taxonomies'][$type]);
        
        if ($key !== false) {
            unset($data['taxonomies'][$type][$key]);
            $data['taxonomies'][$type] = array_values($data['taxonomies'][$type]);
            
            $updated = $page->update($data);
            $contentService->save($updated);
            
            $this->decrementTermCount($type, $termSlug);
        }
    }

    private function incrementTermCount(string $type, string $slug): void
    {
        $terms = $this->loadTerms($type);
        
        foreach ($terms as &$term) {
            if ($term['slug'] === $slug) {
                $term['count'] = ($term['count'] ?? 0) + 1;
                break;
            }
        }
        
        $this->saveTerms($type, $terms);
    }

    private function decrementTermCount(string $type, string $slug): void
    {
        $terms = $this->loadTerms($type);
        
        foreach ($terms as &$term) {
            if ($term['slug'] === $slug) {
                $term['count'] = max(0, ($term['count'] ?? 1) - 1);
                break;
            }
        }
        
        $this->saveTerms($type, $terms);
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/\s+/', '-', $text);
        return trim($text, '-');
    }

    private function getJsonBody(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return $_POST;
        }
        $data = json_decode($input, true);
        return $data ?? [];
    }
}
