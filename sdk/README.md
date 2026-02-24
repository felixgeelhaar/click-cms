# Click CMS SDK

SDK for developing plugins for Click CMS.

## Installation

```bash
composer require click/cms-sdk
```

## Quick Start

### 1. Create Plugin Directory

```
plugins/my-plugin/
├── plugin.json
└── bootstrap.php
```

### 2. Define plugin.json

```json
{
    "name": "My Plugin",
    "description": "A custom plugin for Click CMS",
    "version": "1.0.0",
    "author": "Your Name",
    "dependencies": ["json-storage"],
    "hooks": ["api.routes", "storage.init"]
}
```

### 3. Create bootstrap.php

```php
<?php

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_my_plugin extends \Click\Cms\Application\Plugin\BasePlugin
{
    public function getPluginId(): string
    {
        return 'my-plugin';
    }

    public function getPluginName(): string
    {
        return 'My Plugin';
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

    // Define API routes
    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/my-plugin' => [$this, 'getData'],
            'POST /api/my-plugin' => [$this, 'createData'],
        ];
    }

    public function getData(): array
    {
        return ['data' => ['message' => 'Hello!']];
    }

    public function createData(): array
    {
        $body = json_decode(file_get_contents('php://input'), true);
        
        return ['data' => ['created' => true, 'input' => $body]];
    }

    // Storage hook - called when storage is initialized
    public function hook_storage_init(array $params): ?\Click\Cms\Domain\Storage\StorageInterface
    {
        return null; // Use default storage
    }
}
```

## Available Hooks

| Hook | Description |
|------|-------------|
| `api.routes` | Define REST API endpoints |
| `storage.init` | Provide custom storage backend |
| `content.created` | React when content is created |
| `content.updated` | React when content is updated |
| `content.deleted` | React when content is deleted |

## Lifecycle Methods

- `install()` - Called when plugin is installed
- `activate()` - Called when plugin is activated
- `deactivate()` - Called when plugin is deactivated
- `uninstall()` - Called when plugin is uninstalled

## Accessing Services

```php
public function getData(): array
{
    // Access content service
    $contentService = $this->pluginManager->getContentService();
    $pages = $contentService->pages();
    
    return ['data' => array_map(fn($p) => $p->toArray(), $pages)];
}
```

## Creating Content

```php
public function createPage(string $title, string $content): void
{
    $contentService = $this->pluginManager->getContentService();
    
    $content = \Click\Cms\Domain\Content\Content::create(
        \Click\Cms\Domain\ValueObjects\ContentKey::page('my-page'),
        ['title' => $title, 'content' => $content, 'status' => 'published']
    );
    
    $contentService->save($content);
}
```

## API Reference

### BasePlugin Methods

- `getPluginId()` - Return unique plugin ID
- `getPluginName()` - Return human-readable name
- `getPluginDir()` - Get plugin directory path
- `getConfig($key, $default)` - Get configuration value
- `setConfig(array)` - Set configuration
