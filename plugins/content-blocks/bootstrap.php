<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_content_blocks extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $blocksDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->blocksDir = $basePath . '/data/content-blocks';
        
        if (!is_dir($this->blocksDir)) {
            mkdir($this->blocksDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'content-blocks';
    }

    public function getPluginName(): string
    {
        return 'Content Blocks';
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
            'GET /api/blocks' => [$this, 'getBlocks'],
            'POST /api/blocks' => [$this, 'createBlock'],
            'GET /api/blocks/:slug' => [$this, 'getBlock'],
            'PUT /api/blocks/:slug' => [$this, 'updateBlock'],
            'DELETE /api/blocks/:slug' => [$this, 'deleteBlock'],
        ];
    }

    public function hook_web_render(array $params): ?string
    {
        return $this->renderBlockShortcodes($params);
    }

    private function renderBlockShortcodes(array $params): ?string
    {
        $page = $params['page'] ?? null;
        if (!$page) {
            return null;
        }
        
        $content = $page->content() ?? '';
        
        if (!preg_match_all('/\[block\s+slug=["\']?([^"\'\s\]]+)["\']?\]/', $content, $matches)) {
            return null;
        }
        
        $replacements = [];
        
        foreach ($matches[1] as $key => $slug) {
            $block = $this->loadBlock($slug);
            
            if (!$block) {
                $replacements[$matches[0][$key]] = '<p>Block not found: ' . esc_html($slug) . '</p>';
                continue;
            }
            
            $replacements[$matches[0][$key]] = $this->renderBlock($block);
        }
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function renderBlock(array $block): string
    {
        $html = '<div class="content-block content-block-' . $block['slug'] . '">';
        
        if (!empty($block['content'])) {
            $html .= $block['content'];
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function loadBlocks(): array
    {
        $blocks = [];
        
        $files = glob($this->blocksDir . '/*.json');
        if ($files) {
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $blocks[$data['slug']] = $data;
                }
            }
        }
        
        return $blocks;
    }

    private function loadBlock(string $slug): ?array
    {
        $file = $this->blocksDir . '/' . $slug . '.json';
        
        if (!file_exists($file)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function saveBlock(array $block): void
    {
        $file = $this->blocksDir . '/' . $block['slug'] . '.json';
        file_put_contents($file, json_encode($block, JSON_PRETTY_PRINT));
    }

    public function getBlocks(): array
    {
        return ['data' => array_values($this->loadBlocks())];
    }

    public function getBlock(string $slug): array
    {
        $block = $this->loadBlock($slug);
        
        if (!$block) {
            return ['error' => 'Block not found', 'status' => 404];
        }
        
        return ['data' => $block];
    }

    public function createBlock(): array
    {
        $data = $this->getJsonBody();
        
        $name = $data['name'] ?? '';
        if (empty($name)) {
            return ['error' => 'Block name is required', 'status' => 400];
        }
        
        $slug = $data['slug'] ?? $this->slugify($name);
        
        if ($this->loadBlock($slug)) {
            return ['error' => 'Block with this slug already exists', 'status' => 409];
        }
        
        $block = [
            'slug' => $slug,
            'name' => $name,
            'description' => $data['description'] ?? '',
            'content' => $data['content'] ?? '',
            'fields' => $data['fields'] ?? [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        
        $this->saveBlock($block);
        
        return ['data' => $block, 'status' => 201];
    }

    public function updateBlock(string $slug): array
    {
        $block = $this->loadBlock($slug);
        
        if (!$block) {
            return ['error' => 'Block not found', 'status' => 404];
        }
        
        $data = $this->getJsonBody();
        
        if (isset($data['name'])) {
            $block['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $block['description'] = $data['description'];
        }
        if (isset($data['content'])) {
            $oldContent = $block['content'];
            $block['content'] = $data['content'];
            
            if ($oldContent !== $data['content']) {
                $this->syncBlockUsage($slug, $data['content']);
            }
        }
        if (isset($data['fields'])) {
            $block['fields'] = $data['fields'];
        }
        
        $block['updated_at'] = date('c');
        
        $this->saveBlock($block);
        
        return ['data' => $block];
    }

    public function deleteBlock(string $slug): array
    {
        $file = $this->blocksDir . '/' . $slug . '.json';
        
        if (!file_exists($file)) {
            return ['error' => 'Block not found', 'status' => 404];
        }
        
        unlink($file);
        
        return ['data' => ['deleted' => true, 'slug' => $slug]];
    }

    private function syncBlockUsage(string $slug, string $newContent): void
    {
        $contentService = $this->pluginManager->getContentService();
        $pages = $contentService->pages();
        
        $shortcode = '[block slug="' . $slug . '"]';
        
        foreach ($pages as $page) {
            $content = $page->content() ?? '';
            
            if (str_contains($content, $shortcode)) {
                $data = $page->data;
                $data['needs_block_sync'] = true;
                
                $updated = $page->update($data);
                $contentService->save($updated);
            }
        }
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

function esc_html(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
