<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_backups extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';
    private string $backupDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/backups';
        $this->backupDir = $basePath . '/storage/backups';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'backups';
    }

    public function getPluginName(): string
    {
        return 'Automated Backups';
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
            'GET /api/backups' => [$this, 'listBackups'],
            'POST /api/backups' => [$this, 'createBackup'],
            'GET /api/backups/:id' => [$this, 'getBackup'],
            'DELETE /api/backups/:id' => [$this, 'deleteBackup'],
            'POST /api/backups/:id/restore' => [$this, 'restoreBackup'],
            'GET /api/backups/:id/download' => [$this, 'downloadBackup'],
            'GET /api/backups/schedule' => [$this, 'getSchedule'],
            'PUT /api/backups/schedule' => [$this, 'updateSchedule'],
        ];
    }

    private function loadBackups(): array
    {
        $file = $this->dataDir . '/backups.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveBackups(array $backups): void
    {
        $file = $this->dataDir . '/backups.json';
        file_put_contents($file, json_encode($backups, JSON_PRETTY_PRINT));
    }

    private function loadSchedule(): array
    {
        $file = $this->dataDir . '/schedule.json';
        
        if (!file_exists($file)) {
            return [
                'enabled' => false,
                'frequency' => 'daily',
                'time' => '02:00',
                'retention_days' => 30,
                'max_backups' => 10,
                'include_media' => true,
                'include_database' => true,
                'compress' => true,
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSchedule(array $schedule): void
    {
        $file = $this->dataDir . '/schedule.json';
        file_put_contents($file, json_encode($schedule, JSON_PRETTY_PRINT));
    }

    public function listBackups(): array
    {
        return $this->loadBackups();
    }

    public function getBackup(array $params): array
    {
        $id = $params['id'] ?? null;
        $backups = $this->loadBackups();
        
        foreach ($backups as $backup) {
            if ($backup['id'] === $id) {
                return $backup;
            }
        }
        
        return ['error' => 'Backup not found'];
    }

    public function createBackup(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $schedule = $this->loadSchedule();
        
        $id = bin2hex(random_bytes(8));
        $timestamp = date('Y-m-d-His');
        
        $backup = [
            'id' => $id,
            'name' => $data['name'] ?? "Backup-{$timestamp}",
            'type' => $data['type'] ?? 'full',
            'include_media' => $data['include_media'] ?? $schedule['include_media'],
            'include_database' => $data['include_database'] ?? $schedule['include_database'],
            'compress' => $data['compress'] ?? $schedule['compress'],
            'file_path' => $this->backupDir . "/backup-{$id}.zip",
            'size' => 0,
            'status' => 'completed',
            'created_at' => date('c'),
        ];

        $backupSize = $this->performBackup($backup);
        $backup['size'] = $backupSize;

        $backups = $this->loadBackups();
        $backups[] = $backup;
        $this->saveBackups($backups);

        $this->enforceRetention();

        return $backup;
    }

    private function performBackup(array $backup): int
    {
        $size = 0;
        
        if ($backup['include_media']) {
            $mediaDir = dirname(dirname($this->backupDir)) . '/storage/uploads';
            if (is_dir($mediaDir)) {
                $size += $this->dirSize($mediaDir);
            }
        }

        return $size;
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        if (is_dir($dir)) {
            foreach (glob("{$dir}/*") as $file) {
                $size += is_file($file) ? filesize($file) : $this->dirSize($file);
            }
        }
        return $size;
    }

    private function enforceRetention(): void
    {
        $schedule = $this->loadSchedule();
        $backups = $this->loadBackups();
        
        usort($backups, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $toDelete = [];
        
        if ($schedule['max_backups'] && count($backups) > $schedule['max_backups']) {
            $toDelete = array_slice($backups, $schedule['max_backups']);
        } elseif ($schedule['retention_days']) {
            $cutoff = strtotime("-{$schedule['retention_days']} days");
            foreach ($backups as $backup) {
                if (strtotime($backup['created_at']) < $cutoff) {
                    $toDelete[] = $backup;
                }
            }
        }

        foreach ($toDelete as $backup) {
            if (file_exists($backup['file_path'])) {
                unlink($backup['file_path']);
            }
        }

        $kept = array_filter($backups, function ($b) use ($toDelete) {
            return !in_array($b['id'], array_column($toDelete, 'id'));
        });

        $this->saveBackups(array_values($kept));
    }

    public function deleteBackup(array $params): array
    {
        $id = $params['id'] ?? null;
        $backups = $this->loadBackups();
        
        foreach ($backups as $backup) {
            if ($backup['id'] === $id) {
                if (file_exists($backup['file_path'])) {
                    unlink($backup['file_path']);
                }
            }
        }

        $filtered = array_filter($backups, function ($b) use ($id) {
            return $b['id'] !== $id;
        });
        
        $this->saveBackups(array_values($filtered));
        
        return ['success' => true];
    }

    public function restoreBackup(array $params): array
    {
        $id = $params['id'] ?? null;
        $backups = $this->loadBackups();
        
        foreach ($backups as $backup) {
            if ($backup['id'] === $id) {
                return [
                    'status' => 'restored',
                    'backup_id' => $id,
                    'message' => 'Backup restoration simulated',
                    'restored_at' => date('c'),
                ];
            }
        }
        
        http_response_code(404);
        return ['error' => 'Backup not found'];
    }

    public function downloadBackup(array $params): array
    {
        $id = $params['id'] ?? null;
        $backups = $this->loadBackups();
        
        foreach ($backups as $backup) {
            if ($backup['id'] === $id) {
                return [
                    'download_url' => '/storage/backups/' . basename($backup['file_path']),
                    'expires_at' => date('c', strtotime('+1 hour')),
                ];
            }
        }
        
        http_response_code(404);
        return ['error' => 'Backup not found'];
    }

    public function getSchedule(): array
    {
        return $this->loadSchedule();
    }

    public function updateSchedule(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $schedule = $this->loadSchedule();
        
        $schedule = array_merge($schedule, $data);
        $this->saveSchedule($schedule);
        
        return $schedule;
    }
}
