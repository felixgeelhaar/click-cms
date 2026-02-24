<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';
require_once __DIR__ . '/../../src/Infrastructure/Storage/SqliteStorage.php';

use Click\Cms\Infrastructure\Storage\SqliteStorage;

class Plugin_sqlite_storage extends \Click\Cms\Application\Plugin\BasePlugin
{
    private ?SqliteStorage $storage = null;

    public function getPluginId(): string
    {
        return 'sqlite-storage';
    }

    public function getPluginName(): string
    {
        return 'SQLite Storage';
    }

    public function install(): bool
    {
        $dbPath = $this->getDbPath();
        $dir = dirname($dbPath);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return true;
    }

    public function activate(): bool
    {
        $this->storage = new SqliteStorage($this->getDbPath());
        return true;
    }

    public function deactivate(): bool
    {
        $this->storage = null;
        return true;
    }

    public function hook_storage_init(array $params): ?\Click\Cms\Domain\Storage\StorageInterface
    {
        if ($this->storage === null) {
            $this->storage = new SqliteStorage($this->getDbPath());
        }
        
        return $this->storage;
    }

    private function getDbPath(): string
    {
        $configPath = $this->getConfig('database_path', 'data/content.db');
        return $this->pluginManager->getBasePath() . '/' . $configPath;
    }
}
