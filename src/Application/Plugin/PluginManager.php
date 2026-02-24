<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

use Click\Cms\Domain\Plugin\Plugin;
use Click\Cms\Domain\ValueObjects\PluginId;
use Click\Cms\Domain\ValueObjects\PluginVersion;
use Click\Cms\Domain\Event\EventDispatcher;

class_alias(\Click\Cms\Domain\Plugin\PluginState::class, 'PluginStateAlias');

class PluginManager
{
    private array $plugins = [];
    private string $pluginsPath;
    private string $stateFile;
    private array $state = [];
    private array $excludedIds = [];
    private array $excludedDirs = [];
    private ?EventDispatcher $eventDispatcher = null;
    private ?object $contentService = null;

    public function __construct(
        string $pluginsPath,
        ?string $dataPath = null,
        array $excludedIds = [],
        array $excludedDirs = []
    )
    {
        $this->pluginsPath = rtrim($pluginsPath, '/');
        $this->stateFile = ($dataPath ?? dirname($pluginsPath)) . '/plugin-state.json';
        $this->excludedIds = $excludedIds;
        $this->excludedDirs = $excludedDirs;
        $this->loadState();
    }

    public function setEventDispatcher(EventDispatcher $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    public function setContentService(object $service): void
    {
        $this->contentService = $service;
    }

    public function getContentService(): ?object
    {
        return $this->contentService;
    }

    public function getBasePath(): string
    {
        return dirname($this->pluginsPath);
    }

    public function getPluginsPath(): string
    {
        return $this->pluginsPath;
    }

    public function discover(): array
    {
        $this->plugins = [];
        
        if (!is_dir($this->pluginsPath)) {
            return [];
        }

        $directories = glob($this->pluginsPath . '/*', GLOB_ONLYDIR);
        
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        
        foreach ($directories as $dir) {
            if ($this->isExcludedDir($dir)) {
                continue;
            }
            $metadata = $this->loadMetadata($dir);
            if ($metadata === null) {
                continue;
            }

            $pluginId = PluginId::generate($metadata->name);
            if ($this->isExcludedId($pluginId->value)) {
                continue;
            }
            $savedState = $this->state[$pluginId->value] ?? [];
            $state = isset($savedState['activated']) && $savedState['activated'] 
                ? $PluginState::ACTIVATED 
                : $PluginState::DISCOVERED;

            $plugin = Plugin::create(
                id: $pluginId,
                name: $metadata->name,
                description: $metadata->description,
                version: PluginVersion::fromString($metadata->version),
                author: $metadata->author,
                dependencies: $metadata->dependencies,
                hooks: $metadata->hooks,
                path: $dir
            );

            if ($state === $PluginState::ACTIVATED) {
                $plugin = $plugin->activate();
            }

            $this->plugins[$pluginId->value] = $plugin;
        }

        $this->sortByDependencies();
        
        return array_values($this->plugins);
    }

    public function get(PluginId $id): ?Plugin
    {
        return $this->plugins[$id->value] ?? null;
    }

    public function all(): array
    {
        return array_values($this->plugins);
    }

    public function activate(PluginId $id): array
    {
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        
        $plugin = $this->get($id);
        
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        if ($plugin->state === $PluginState::ACTIVATED) {
            return ['success' => false, 'error' => 'Plugin already activated'];
        }

        if (!$this->checkDependencies($plugin)) {
            $missing = $this->getMissingDependencies($plugin);
            return ['success' => false, 'error' => 'Missing dependencies: ' . implode(', ', $missing)];
        }

        $bootstrap = $this->loadBootstrap($plugin);
        if ($bootstrap !== null && method_exists($bootstrap, 'activate')) {
            $result = $bootstrap->activate();
            if ($result === false) {
                return ['success' => false, 'error' => 'Activation failed'];
            }
        }

        $this->plugins[$id->value] = $plugin->activate();
        $this->state[$id->value] = ['activated' => true, 'activated_at' => date('c')];
        $this->saveState();

        $this->dispatchEvent('plugin.activated', ['plugin' => $plugin]);

        return ['success' => true, 'plugin' => $this->plugins[$id->value]];
    }

    public function deactivate(PluginId $id): array
    {
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        
        $plugin = $this->get($id);
        
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        if ($plugin->state !== $PluginState::ACTIVATED) {
            return ['success' => false, 'error' => 'Plugin not activated'];
        }

        $dependentPlugins = $this->getDependentPlugins($plugin);
        if (!empty($dependentPlugins)) {
            $names = array_map(fn($p) => $p->name, $dependentPlugins);
            return ['success' => false, 'error' => 'Cannot deactivate - other plugins depend on it: ' . implode(', ', $names)];
        }

        $bootstrap = $this->loadBootstrap($plugin);
        if ($bootstrap !== null && method_exists($bootstrap, 'deactivate')) {
            $bootstrap->deactivate();
        }

        $this->plugins[$id->value] = $plugin->deactivate();
        $this->state[$id->value] = ['activated' => false, 'deactivated_at' => date('c')];
        $this->saveState();

        $this->dispatchEvent('plugin.deactivated', ['plugin' => $plugin]);

        return ['success' => true, 'plugin' => $this->plugins[$id->value]];
    }

    public function install(PluginId $id): array
    {
        $plugin = $this->get($id);
        
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        if (isset($this->state[$id->value]['installed'])) {
            return ['success' => false, 'error' => 'Plugin already installed'];
        }

        $bootstrap = $this->loadBootstrap($plugin);
        if ($bootstrap !== null && method_exists($bootstrap, 'install')) {
            $result = $bootstrap->install();
            if ($result === false) {
                return ['success' => false, 'error' => 'Installation failed'];
            }
        }

        $this->state[$id->value] = array_merge(
            $this->state[$id->value] ?? [],
            ['installed' => true, 'installed_at' => date('c')]
        );
        $this->saveState();

        $this->dispatchEvent('plugin.installed', ['plugin' => $plugin]);

        return ['success' => true];
    }

    public function uninstall(PluginId $id): array
    {
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        
        $plugin = $this->get($id);
        
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        if ($plugin->state === $PluginState::ACTIVATED) {
            return ['success' => false, 'error' => 'Deactivate plugin first'];
        }

        $bootstrap = $this->loadBootstrap($plugin);
        if ($bootstrap !== null && method_exists($bootstrap, 'uninstall')) {
            $bootstrap->uninstall();
        }

        unset($this->state[$id->value]);
        $this->saveState();

        $this->dispatchEvent('plugin.uninstalled', ['plugin' => $plugin]);

        return ['success' => true];
    }

    public function getActive(): array
    {
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        return array_filter($this->plugins, fn($p) => $p->state === $PluginState::ACTIVATED);
    }

    public function getHooks(PluginId $id): array
    {
        $plugin = $this->get($id);
        return $plugin?->hooks ?? [];
    }

    public function executeHook(string $hookName, array $params = []): array
    {
        $results = [];
        $normalizedHook = str_replace('_', '.', $hookName);
        
        foreach ($this->getActive() as $plugin) {
            $pluginHasHook = in_array($hookName, $plugin->hooks) || in_array($normalizedHook, $plugin->hooks);
            
            if (!$pluginHasHook) {
                continue;
            }

            $bootstrap = $this->loadBootstrap($plugin);
            if ($bootstrap === null) {
                continue;
            }

            $methodName = str_replace('.', '_', 'hook_' . $hookName);
            if (method_exists($bootstrap, $methodName)) {
                $results[$plugin->name] = $bootstrap->$methodName($params);
            }
        }

        return $results;
    }

    private function loadBootstrap(Plugin $plugin): ?object
    {
        $bootstrapFile = $plugin->path . '/bootstrap.php';
        
        if (!file_exists($bootstrapFile)) {
            return null;
        }

        require_once $bootstrapFile;

        $className = 'Plugin_' . str_replace('-', '_', $plugin->id->value);
        
        if (!class_exists($className)) {
            return null;
        }

        return new $className($this);
    }

    private function loadMetadata(string $dir): ?PluginMetadata
    {
        $metadataFile = $dir . '/plugin.json';
        
        if (!file_exists($metadataFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($metadataFile), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return PluginMetadata::fromArray($data);
    }

    private function checkDependencies(Plugin $plugin): bool
    {
        return empty($this->getMissingDependencies($plugin));
    }

    private function getMissingDependencies(Plugin $plugin): array
    {
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        $missing = [];
        
        foreach ($plugin->dependencies as $dep) {
            $depId = PluginId::fromString($dep);
            
            if (!isset($this->plugins[$depId->value])) {
                $missing[] = $dep;
                continue;
            }
            
            if ($this->plugins[$depId->value]->state !== $PluginState::ACTIVATED) {
                $missing[] = $dep;
            }
        }

        return $missing;
    }

    private function getDependentPlugins(Plugin $plugin): array
    {
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        $dependent = [];
        
        foreach ($this->plugins as $p) {
            if ($p->state !== $PluginState::ACTIVATED) {
                continue;
            }
            
            if (in_array($plugin->id->value, $p->dependencies)) {
                $dependent[] = $p;
            }
        }
        
        return $dependent;
    }

    private function sortByDependencies(): void
    {
        $sorted = [];
        $visited = [];
        
        $sort = function(Plugin $plugin) use (&$sorted, &$visited, &$sort) {
            if (isset($visited[$plugin->id->value])) {
                return;
            }
            
            $visited[$plugin->id->value] = true;
            
            foreach ($plugin->dependencies as $dep) {
                $depId = PluginId::fromString($dep);
                if (isset($this->plugins[$depId->value])) {
                    $sort($this->plugins[$depId->value]);
                }
            }
            
            $sorted[$plugin->id->value] = $plugin;
        };
        
        foreach ($this->plugins as $plugin) {
            $sort($plugin);
        }
        
        $this->plugins = $sorted;
    }

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $this->state = json_decode(file_get_contents($this->stateFile), true) ?? [];
        }
    }

    private function isExcludedId(string $id): bool
    {
        return in_array($id, $this->excludedIds, true);
    }

    private function isExcludedDir(string $dir): bool
    {
        $base = basename($dir);
        return in_array($base, $this->excludedDirs, true);
    }

    private function saveState(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->stateFile, json_encode($this->state, JSON_PRETTY_PRINT));
    }

    private function dispatchEvent(string $name, array $payload = []): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        $event = new \Click\Cms\Domain\Event\Event($name, $payload);
        $this->eventDispatcher->dispatch($event);
    }
}
