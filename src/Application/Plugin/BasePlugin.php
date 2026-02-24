<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

use Click\Cms\Application\Plugin\PluginManager;

abstract class BasePlugin
{
    protected PluginManager $pluginManager;
    protected array $config = [];

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
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

    public function uninstall(): bool
    {
        return true;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getPluginDir(): string
    {
        return $this->pluginManager->getBasePath() . '/plugins/' . $this->getPluginId();
    }

    abstract public function getPluginId(): string;

    abstract public function getPluginName(): string;
}
