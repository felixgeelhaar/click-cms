<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_import extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';
    private string $exportDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/import';
        $this->exportDir = $basePath . '/storage/exports';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'import';
    }

    public function getPluginName(): string
    {
        return 'Import / Export';
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
            'GET /api/import/formats' => [$this, 'listFormats'],
            'POST /api/import/preview' => [$this, 'previewImport'],
            'POST /api/import/execute' => [$this, 'executeImport'],
            'GET /api/import/history' => [$this, 'listImportHistory'],
            'POST /api/export/pages' => [$this, 'exportPages'],
            'POST /api/export/media' => [$this, 'exportMedia'],
            'POST /api/export/full' => [$this, 'fullExport'],
            'GET /api/export/download/:id' => [$this, 'downloadExport'],
        ];
    }

    private function loadImportHistory(): array
    {
        $file = $this->dataDir . '/history.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveImportHistory(array $history): void
    {
        $file = $this->dataDir . '/history.json';
        file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
    }

    public function listFormats(): array
    {
        return [
            [
                'id' => 'json',
                'name' => 'JSON',
                'description' => 'Native Click CMS JSON format',
                'extensions' => ['json'],
                'import' => true,
                'export' => true,
            ],
            [
                'id' => 'csv',
                'name' => 'CSV',
                'description' => 'Comma-separated values',
                'extensions' => ['csv'],
                'import' => true,
                'export' => true,
            ],
            [
                'id' => 'wordpress',
                'name' => 'WordPress WXR',
                'description' => 'WordPress eXtended RSS',
                'extensions' => ['xml'],
                'import' => true,
                'export' => false,
            ],
            [
                'id' => 'markdown',
                'name' => 'Markdown',
                'description' => 'Markdown with frontmatter',
                'extensions' => ['md', 'markdown'],
                'import' => true,
                'export' => true,
            ],
        ];
    }

    public function previewImport(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['format']) || empty($data['content'])) {
            http_response_code(400);
            return ['error' => 'Missing format or content'];
        }

        $preview = [
            'format' => $data['format'],
            'total_rows' => 0,
            'valid_rows' => 0,
            'errors' => [],
            'sample' => [],
        ];

        return $preview;
    }

    public function executeImport(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['format']) || empty($data['content'])) {
            http_response_code(400);
            return ['error' => 'Missing format or content'];
        }

        $id = bin2hex(random_bytes(8));
        
        $import = [
            'id' => $id,
            'format' => $data['format'],
            'type' => $data['type'] ?? 'pages',
            'status' => 'completed',
            'total_rows' => 0,
            'imported' => 0,
            'errors' => [],
            'started_at' => date('c'),
            'completed_at' => date('c'),
        ];

        $history = $this->loadImportHistory();
        $history[] = $import;
        
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }
        
        $this->saveImportHistory($history);

        return $import;
    }

    public function listImportHistory(): array
    {
        return $this->loadImportHistory();
    }

    public function exportPages(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $id = bin2hex(random_bytes(8));
        $filename = "pages-export-{$id}.json";
        $filepath = $this->exportDir . '/' . $filename;
        
        $export = [
            'id' => $id,
            'type' => 'pages',
            'format' => $data['format'] ?? 'json',
            'filename' => $filename,
            'filepath' => $filepath,
            'status' => 'ready',
            'record_count' => 0,
            'created_at' => date('c'),
        ];

        $content = [
            'version' => '1.0',
            'exported_at' => date('c'),
            'type' => 'pages',
            'records' => [],
        ];

        file_put_contents($filepath, json_encode($content, JSON_PRETTY_PRINT));
        $export['record_count'] = 0;

        return $export;
    }

    public function exportMedia(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $id = bin2hex(random_bytes(8));
        $filename = "media-export-{$id}.zip";
        $filepath = $this->exportDir . '/' . $filename;
        
        $export = [
            'id' => $id,
            'type' => 'media',
            'format' => 'zip',
            'filename' => $filename,
            'filepath' => $filepath,
            'status' => 'ready',
            'file_count' => 0,
            'size' => 0,
            'created_at' => date('c'),
        ];

        return $export;
    }

    public function fullExport(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $id = bin2hex(random_bytes(8));
        $filename = "full-export-{$id}.zip";
        $filepath = $this->exportDir . '/' . $filename;
        
        $export = [
            'id' => $id,
            'type' => 'full',
            'format' => 'zip',
            'filename' => $filename,
            'filepath' => $filepath,
            'status' => 'ready',
            'includes' => $data['includes'] ?? ['pages', 'media', 'settings'],
            'created_at' => date('c'),
        ];

        return $export;
    }

    public function downloadExport(array $params): array
    {
        $id = $params['id'] ?? null;
        
        return [
            'download_url' => "/storage/exports/export-{$id}.zip",
            'expires_at' => date('c', strtotime('+1 hour')),
        ];
    }
}
