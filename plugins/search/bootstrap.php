<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_search extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $indexDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->indexDir = $basePath . '/data/search';
        
        if (!is_dir($this->indexDir)) {
            mkdir($this->indexDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'search';
    }

    public function getPluginName(): string
    {
        return 'Full-Text Search';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        $this->rebuildIndex();
        return true;
    }

    public function deactivate(): bool
    {
        return true;
    }

    public function hook_page_save(array $params): void
    {
        $this->indexPage($params['slug'] ?? '');
    }

    public function hook_page_delete(array $params): void
    {
        $this->removeFromIndex($params['slug'] ?? '');
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/search' => [$this, 'search'],
            'POST /api/search/reindex' => [$this, 'rebuildIndexEndpoint'],
            'GET /api/search/index' => [$this, 'getIndexStatus'],
        ];
    }

    private function rebuildIndex(): void
    {
        $contentService = $this->pluginManager->getContentService();
        $pages = $contentService->pages();
        
        $index = [];
        
        foreach ($pages as $page) {
            $data = $page->data ?? [];
            $slug = $page->slug() ?? '';
            
            if (($data['status'] ?? '') !== 'published') {
                continue;
            }
            
            $index[$slug] = [
                'slug' => $slug,
                'title' => $page->title() ?? '',
                'content' => strip_tags($page->content() ?? ''),
                'excerpt' => $this->generateExcerpt($page->content() ?? '', 160),
                'updated_at' => $data['updated_at'] ?? date('c'),
            ];
        }
        
        $this->saveIndex($index);
    }

    private function indexPage(string $slug): void
    {
        if (empty($slug)) {
            return;
        }
        
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);
        
        if (!$page) {
            $this->removeFromIndex($slug);
            return;
        }
        
        $data = $page->data ?? [];
        
        if (($data['status'] ?? '') !== 'published') {
            return;
        }
        
        $index = $this->loadIndex();
        
        $index[$slug] = [
            'slug' => $slug,
            'title' => $page->title() ?? '',
            'content' => strip_tags($page->content() ?? ''),
            'excerpt' => $this->generateExcerpt($page->content() ?? '', 160),
            'updated_at' => $data['updated_at'] ?? date('c'),
        ];
        
        $this->saveIndex($index);
    }

    private function removeFromIndex(string $slug): void
    {
        if (empty($slug)) {
            return;
        }
        
        $index = $this->loadIndex();
        unset($index[$slug]);
        $this->saveIndex($index);
    }

    private function loadIndex(): array
    {
        $file = $this->indexDir . '/index.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveIndex(array $index): void
    {
        $file = $this->indexDir . '/index.json';
        file_put_contents($file, json_encode($index, JSON_PRETTY_PRINT));
    }

    private function generateExcerpt(string $content, int $length = 160): string
    {
        $content = trim($content);
        
        if (strlen($content) <= $length) {
            return $content;
        }
        
        $excerpt = substr($content, 0, $length);
        $lastSpace = strrpos($excerpt, ' ');
        
        if ($lastSpace !== false) {
            $excerpt = substr($excerpt, 0, $lastSpace);
        }
        
        return $excerpt . '...';
    }

    public function search(): array
    {
        $query = $_GET['q'] ?? $_GET['query'] ?? '';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        if (empty($query)) {
            return ['data' => ['results' => [], 'total' => 0, 'query' => '']];
        }
        
        $query = strtolower(trim($query));
        $queryTerms = preg_split('/\s+/', $query);
        
        $index = $this->loadIndex();
        
        $results = [];
        
        foreach ($index as $slug => $page) {
            $score = $this->calculateScore($page, $queryTerms);
            
            if ($score > 0) {
                $results[] = [
                    'slug' => $slug,
                    'title' => $page['title'],
                    'excerpt' => $page['excerpt'],
                    'score' => $score,
                    'url' => '/' . $slug,
                ];
            }
        }
        
        usort($results, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $total = count($results);
        $results = array_slice($results, $offset, $limit);
        
        return ['data' => [
            'results' => $results,
            'total' => $total,
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ]];
    }

    private function calculateScore(array $page, array $terms): float
    {
        $score = 0.0;
        
        $title = strtolower($page['title'] ?? '');
        $content = strtolower($page['content'] ?? '');
        
        foreach ($terms as $term) {
            if (empty($term)) {
                continue;
            }
            
            $titleCount = substr_count($title, $term);
            $contentCount = substr_count($content, $term);
            
            if ($titleCount > 0) {
                $score += $titleCount * 10;
            }
            
            if ($contentCount > 0) {
                $score += $contentCount;
            }
            
            if (str_starts_with($title, $term)) {
                $score += 5;
            }
            
            if (str_contains($title, $term)) {
                $score += 3;
            }
        }
        
        return $score;
    }

    public function rebuildIndexEndpoint(): array
    {
        $this->rebuildIndex();
        return ['data' => ['reindexed' => true, 'timestamp' => time()]];
    }

    public function getIndexStatus(): array
    {
        $index = $this->loadIndex();
        
        $stats = [
            'total_pages' => count($index),
            'last_updated' => null,
        ];
        
        $lastUpdated = null;
        foreach ($index as $page) {
            $updatedAt = $page['updated_at'] ?? '';
            if ($updatedAt && (!$lastUpdated || $updatedAt > $lastUpdated)) {
                $lastUpdated = $updatedAt;
            }
        }
        
        $stats['last_updated'] = $lastUpdated;
        
        return ['data' => $stats];
    }
}
