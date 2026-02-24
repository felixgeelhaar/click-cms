<?php

declare(strict_types=1);

namespace Click\Cms\Sdk;

class_alias('Click\Cms\Domain\Plugin\PluginState', 'PluginState');

require_once __DIR__ . '/../vendor/autoload.php';

class_alias('Click\Cms\Application\Plugin\BasePlugin', 'BasePlugin');

if (!class_exists('Plugin_my_plugin')) {
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

        public function hook_api_routes(array $params): array
        {
            return [
                'GET /api/my-plugin' => [$this, 'getMyData'],
            ];
        }

        public function getMyData(): array
        {
            return ['data' => ['message' => 'Hello from my plugin!']];
        }
    }
}
