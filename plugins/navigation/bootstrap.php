<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_navigation extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/navigation';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'navigation';
    }

    public function getPluginName(): string
    {
        return 'Navigation Builder';
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
            'GET /api/navigations' => [$this, 'listNavigations'],
            'POST /api/navigations' => [$this, 'createNavigation'],
            'GET /api/navigations/:id' => [$this, 'getNavigation'],
            'PUT /api/navigations/:id' => [$this, 'updateNavigation'],
            'DELETE /api/navigations/:id' => [$this, 'deleteNavigation'],
            'GET /api/navigations/:id/export' => [$this, 'exportNavigation'],
            'POST /api/navigations/import' => [$this, 'importNavigation'],
        ];
    }

    private function loadNavigations(): array
    {
        $file = $this->dataDir . '/navigations.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveNavigations(array $navigations): void
    {
        $file = $this->dataDir . '/navigations.json';
        file_put_contents($file, json_encode($navigations, JSON_PRETTY_PRINT));
    }

    public function listNavigations(): array
    {
        return $this->loadNavigations();
    }

    public function createNavigation(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['name'])) {
            http_response_code(400);
            return ['error' => 'Missing name'];
        }

        $id = bin2hex(random_bytes(8));
        
        $navigation = [
            'id' => $id,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? strtolower(preg_replace('/[^a-z0-9-]/', '-', $data['name'])),
            'locations' => $data['locations'] ?? ['header'],
            'items' => $data['items'] ?? [],
            'settings' => $data['settings'] ?? [
                'depth_limit' => 3,
                'mobile_enabled' => true,
                'sticky' => false,
                'transparent' => false,
            ],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $navigations = $this->loadNavigations();
        $navigations[] = $navigation;
        $this->saveNavigations($navigations);

        return $navigation;
    }

    public function getNavigation(array $params): array
    {
        $id = $params['id'] ?? null;
        $navigations = $this->loadNavigations();
        
        foreach ($navigations as $nav) {
            if ($nav['id'] === $id) {
                return $nav;
            }
        }
        
        return ['error' => 'Navigation not found'];
    }

    public function updateNavigation(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $params['id'] ?? null;
        
        $navigations = $this->loadNavigations();
        
        foreach ($navigations as &$nav) {
            if ($nav['id'] === $id) {
                if (isset($data['name'])) {
                    $nav['name'] = $data['name'];
                }
                if (isset($data['slug'])) {
                    $nav['slug'] = $data['slug'];
                }
                if (isset($data['locations'])) {
                    $nav['locations'] = $data['locations'];
                }
                if (isset($data['items'])) {
                    $nav['items'] = $data['items'];
                }
                if (isset($data['settings'])) {
                    $nav['settings'] = array_merge($nav['settings'], $data['settings']);
                }
                $nav['updated_at'] = date('c');
                
                $this->saveNavigations($navigations);
                return $nav;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Navigation not found'];
    }

    public function deleteNavigation(array $params): array
    {
        $id = $params['id'] ?? null;
        $navigations = $this->loadNavigations();
        
        $filtered = array_filter($navigations, function ($nav) use ($id) {
            return $nav['id'] !== $id;
        });
        
        $this->saveNavigations(array_values($filtered));
        
        return ['success' => true];
    }

    public function exportNavigation(array $params): array
    {
        $id = $params['id'] ?? null;
        $navigations = $this->loadNavigations();
        
        foreach ($navigations as $nav) {
            if ($nav['id'] === $id) {
                return [
                    'exported_at' => date('c'),
                    'navigation' => $nav,
                ];
            }
        }
        
        http_response_code(404);
        return ['error' => 'Navigation not found'];
    }

    public function importNavigation(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['navigation'])) {
            http_response_code(400);
            return ['error' => 'Missing navigation data'];
        }

        $nav = $data['navigation'];
        $nav['id'] = bin2hex(random_bytes(8));
        $nav['created_at'] = date('c');
        $nav['updated_at'] = date('c');
        
        if (!empty($data['merge']) && $data['merge']) {
            $navigations = $this->loadNavigations();
            foreach ($navigations as &$existing) {
                if ($existing['slug'] === $nav['slug']) {
                    $existing = array_merge($existing, $nav);
                    $this->saveNavigations($navigations);
                    return $existing;
                }
            }
        }
        
        $navigations = $this->loadNavigations();
        $navigations[] = $nav;
        $this->saveNavigations($navigations);

        return $nav;
    }
}
