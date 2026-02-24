<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_relationships extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/relationships';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'relationships';
    }

    public function getPluginName(): string
    {
        return 'Content Relationships';
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
            'GET /api/relationships' => [$this, 'listRelationships'],
            'POST /api/relationships' => [$this, 'createRelationship'],
            'GET /api/relationships/:id' => [$this, 'getRelationship'],
            'PUT /api/relationships/:id' => [$this, 'updateRelationship'],
            'DELETE /api/relationships/:id' => [$this, 'deleteRelationship'],
            'GET /api/relationships/page/:pageId' => [$this, 'getPageRelationships'],
            'GET /api/relationships/types' => [$this, 'getRelationshipTypes'],
        ];
    }

    private function loadRelationships(): array
    {
        $file = $this->dataDir . '/relationships.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveRelationships(array $relationships): void
    {
        $file = $this->dataDir . '/relationships.json';
        file_put_contents($file, json_encode($relationships, JSON_PRETTY_PRINT));
    }

    public function getRelationshipTypes(): array
    {
        return [
            ['id' => 'related', 'name' => 'Related', 'icon' => 'link'],
            ['id' => 'parent', 'name' => 'Parent', 'icon' => 'arrow-up'],
            ['id' => 'child', 'name' => 'Child', 'icon' => 'arrow-down'],
            ['id' => 'sibling', 'name' => 'Sibling', 'icon' => 'arrows-left-right'],
            ['id' => 'featured', 'name' => 'Featured', 'icon' => 'star'],
            ['id' => 'recommended', 'name' => 'Recommended', 'icon' => 'thumbs-up'],
        ];
    }

    public function listRelationships(): array
    {
        return $this->loadRelationships();
    }

    public function getRelationship(array $params): array
    {
        $relationships = $this->loadRelationships();
        $id = $params['id'] ?? null;
        
        foreach ($relationships as $relationship) {
            if ($relationship['id'] === $id) {
                return $relationship;
            }
        }
        
        return ['error' => 'Relationship not found'];
    }

    public function getPageRelationships(array $params): array
    {
        $pageId = $params['pageId'] ?? null;
        $type = $_GET['type'] ?? null;
        $direction = $_GET['direction'] ?? 'both';
        
        $relationships = $this->loadRelationships();
        
        return array_values(array_filter($relationships, function ($r) use ($pageId, $direction, $type) {
            if ($direction === 'outbound' && $r['source_id'] !== $pageId) {
                return false;
            }
            if ($direction === 'inbound' && $r['target_id'] !== $pageId) {
                return false;
            }
            if ($type && $r['relation_type'] !== $type) {
                return false;
            }
            return true;
        }));
    }

    public function createRelationship(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['source_id', 'target_id', 'relation_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                return ['error' => "Missing required field: $field"];
            }
        }

        $id = bin2hex(random_bytes(16));
        $relationship = [
            'id' => $id,
            'source_id' => $data['source_id'],
            'target_id' => $data['target_id'],
            'relation' => $data['relation'] ?? 'references',
            'relation_type' => $data['relation_type'],
            'metadata' => $data['metadata'] ?? [],
            'created_at' => date('c'),
        ];

        $relationships = $this->loadRelationships();
        $relationships[] = $relationship;
        $this->saveRelationships($relationships);

        return $relationship;
    }

    public function updateRelationship(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $params['id'] ?? null;
        
        $relationships = $this->loadRelationships();
        
        foreach ($relationships as &$relationship) {
            if ($relationship['id'] === $id) {
                if (isset($data['relation'])) {
                    $relationship['relation'] = $data['relation'];
                }
                if (isset($data['relation_type'])) {
                    $relationship['relation_type'] = $data['relation_type'];
                }
                if (isset($data['metadata'])) {
                    $relationship['metadata'] = $data['metadata'];
                }
                $this->saveRelationships($relationships);
                return $relationship;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Relationship not found'];
    }

    public function deleteRelationship(array $params): array
    {
        $id = $params['id'] ?? null;
        $relationships = $this->loadRelationships();
        
        $filtered = array_filter($relationships, function ($r) use ($id) {
            return $r['id'] !== $id;
        });
        
        $this->saveRelationships(array_values($filtered));
        
        return ['success' => true];
    }
}
