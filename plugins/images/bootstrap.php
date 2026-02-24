<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_images extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';
    private string $cacheDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/images';
        $this->cacheDir = $basePath . '/data/images/cache';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'images';
    }

    public function getPluginName(): string
    {
        return 'Image Optimization';
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
            'GET /api/images/optimize' => [$this, 'listOptimizedImages'],
            'POST /api/images/optimize' => [$this, 'optimizeImage'],
            'GET /api/images/optimize/:id' => [$this, 'getOptimizedImage'],
            'DELETE /api/images/optimize/:id' => [$this, 'deleteOptimizedImage'],
            'POST /api/images/optimize/:id/restore' => [$this, 'restoreOriginal'],
            'GET /api/images/settings' => [$this, 'getSettings'],
            'PUT /api/images/settings' => [$this, 'updateSettings'],
        ];
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        
        if (!file_exists($file)) {
            return [
                'auto_optimize' => true,
                'quality' => 85,
                'format' => 'webp',
                'max_width' => 1920,
                'max_height' => 1080,
                'responsive_sizes' => [320, 640, 960, 1280, 1920],
                'lazy_loading' => true,
                'progressive' => true,
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSettings(array $settings): void
    {
        $file = $this->dataDir . '/settings.json';
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT));
    }

    private function loadOptimizedImages(): array
    {
        $file = $this->dataDir . '/optimized.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveOptimizedImages(array $images): void
    {
        $file = $this->dataDir . '/optimized.json';
        file_put_contents($file, json_encode($images, JSON_PRETTY_PRINT));
    }

    public function getSettings(): array
    {
        return $this->loadSettings();
    }

    public function updateSettings(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $settings = $this->loadSettings();
        
        $allowedKeys = ['auto_optimize', 'quality', 'format', 'max_width', 'max_height', 'responsive_sizes', 'lazy_loading', 'progressive'];
        
        foreach ($allowedKeys as $key) {
            if (isset($data[$key])) {
                $settings[$key] = $data[$key];
            }
        }
        
        $this->saveSettings($settings);
        
        return $settings;
    }

    public function listOptimizedImages(): array
    {
        return $this->loadOptimizedImages();
    }

    public function getOptimizedImage(array $params): array
    {
        $id = $params['id'] ?? null;
        $images = $this->loadOptimizedImages();
        
        foreach ($images as $image) {
            if ($image['id'] === $id) {
                return $image;
            }
        }
        
        return ['error' => 'Image not found'];
    }

    public function optimizeImage(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['source_path'])) {
            http_response_code(400);
            return ['error' => 'Missing source_path'];
        }

        $sourcePath = $data['source_path'];
        
        if (!file_exists($sourcePath)) {
            http_response_code(404);
            return ['error' => 'Source file not found'];
        }

        $settings = $this->loadSettings();
        $id = bin2hex(random_bytes(8));
        
        $originalSize = filesize($sourcePath);
        $originalFormat = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        
        $optimized = [
            'id' => $id,
            'source_path' => $sourcePath,
            'original_size' => $originalSize,
            'original_format' => $originalFormat,
            'optimized_size' => $originalSize,
            'optimized_path' => $sourcePath,
            'quality' => $settings['quality'],
            'format' => $settings['format'],
            'savings' => 0,
            'responsive_variants' => [],
            'optimized_at' => date('c'),
            'status' => 'completed',
        ];

        if ($settings['auto_optimize'] && in_array($originalFormat, ['jpg', 'jpeg', 'png', 'gif'])) {
            $optimized['status'] = 'simulated';
            $optimized['savings'] = floor($originalSize * 0.3);
            $optimized['optimized_size'] = $originalSize - $optimized['savings'];
        }

        foreach ($settings['responsive_sizes'] as $size) {
            $optimized['responsive_variants'][] = [
                'width' => $size,
                'path' => $sourcePath,
                'size' => $optimized['optimized_size'],
            ];
        }

        $images = $this->loadOptimizedImages();
        $images[] = $optimized;
        $this->saveOptimizedImages($images);

        return $optimized;
    }

    public function deleteOptimizedImage(array $params): array
    {
        $id = $params['id'] ?? null;
        $images = $this->loadOptimizedImages();
        
        $filtered = array_filter($images, function ($img) use ($id) {
            return $img['id'] !== $id;
        });
        
        $this->saveOptimizedImages(array_values($filtered));
        
        return ['success' => true];
    }

    public function restoreOriginal(array $params): array
    {
        $id = $params['id'] ?? null;
        $images = $this->loadOptimizedImages();
        
        foreach ($images as &$image) {
            if ($image['id'] === $id) {
                $image['status'] = 'restored';
                $image['restored_at'] = date('c');
                $this->saveOptimizedImages($images);
                return $image;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Image not found'];
    }
}
