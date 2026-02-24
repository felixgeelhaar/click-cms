<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';
require_once __DIR__ . '/../../src/Infrastructure/Storage/JsonStorage.php';
require_once __DIR__ . '/../../src/Domain/Storage/StorageInterface.php';

use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Domain\Storage\StorageInterface;

class Plugin_json_storage extends \Click\Cms\Application\Plugin\BasePlugin
{
    private ?JsonStorage $storage = null;

    public function getPluginId(): string
    {
        return 'json-storage';
    }

    public function getPluginName(): string
    {
        return 'JSON Storage';
    }

    public function install(): bool
    {
        $storagePath = $this->getContentPath();
        
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
        
        foreach (['page', 'user', 'media'] as $dir) {
            $path = $storagePath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
        
        return true;
    }

    public function activate(): bool
    {
        $this->storage = new JsonStorage($this->getContentPath());
        
        return true;
    }

    public function deactivate(): bool
    {
        $this->storage = null;
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function getStorage(): ?JsonStorage
    {
        return $this->storage;
    }

    public function hook_storage_init(array $params): ?StorageInterface
    {
        if ($this->storage === null) {
            $this->storage = new JsonStorage($this->getContentPath());
        }
        
        return $this->storage;
    }

    private function getContentPath(): string
    {
        return $this->pluginManager->getBasePath() . '/content';
    }
}
