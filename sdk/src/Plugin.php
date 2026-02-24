<?php

declare(strict_types=1);

namespace Click\Cms\Sdk;

abstract class Plugin
{
    protected $manager;
    protected array $config = [];

    public function __construct($manager)
    {
        $this->manager = $manager;
    }

    public function getPluginDir(): string
    {
        return $this->manager->getBasePath() . '/plugins/' . $this->getPluginId();
    }

    public function getDataDir(): string
    {
        return $this->manager->getBasePath() . '/data/' . $this->getPluginId();
    }

    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function config(string $key, $default = null)
    {
        return $this->getConfig($key, $default);
    }

    abstract public function getPluginId(): string;

    abstract public function getPluginName(): string;

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
}
