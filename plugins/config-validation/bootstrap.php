<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_config_validation extends \Click\Cms\Application\Plugin\BasePlugin
{
    private array $errors = [];
    private array $warnings = [];
    private array $validations = [];

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
    }

    public function getPluginId(): string
    {
        return 'config-validation';
    }

    public function getPluginName(): string
    {
        return 'Config Validation';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        $this->validateConfig();
        return empty($this->errors);
    }

    public function deactivate(): bool
    {
        return true;
    }

    public function hook_app_boot(array $params): void
    {
        $this->validateConfig();
        
        if (!empty($this->errors)) {
            $app = $params['app'];
            $app->log('Config validation failed: ' . implode('; ', $this->errors), 'error');
        }
        
        if (!empty($this->warnings)) {
            $app = $params['app'];
            $app->log('Config warnings: ' . implode('; ', $this->warnings), 'warning');
        }
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/config/validate' => [$this, 'serveValidation'],
            'GET /api/config' => [$this, 'serveConfig'],
        ];
    }

    private function validateConfig(): void
    {
        $basePath = $this->pluginManager->getBasePath();
        
        $this->validateCoreConfig($basePath);
        $this->validateEnvOverrides($basePath);
    }

    private function validateCoreConfig(string $basePath): void
    {
        $configPath = $basePath . '/config/core.json';
        
        if (!file_exists($configPath)) {
            $this->errors[] = 'config/core.json not found';
            return;
        }

        $config = json_decode(file_get_contents($configPath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = 'config/core.json has invalid JSON: ' . json_last_error_msg();
            return;
        }

        $this->validations['core_json'] = [
            'status' => 'valid',
            'path' => $configPath,
        ];

        $this->validateAppConfig($config);
        $this->validateAuthConfig($config);
        $this->validateStorageConfig($config);
    }

    private function validateAppConfig(array $config): void
    {
        $app = $config['app'] ?? [];
        
        if (empty($app['name'])) {
            $this->warnings[] = 'app.name is empty';
        }

        if (empty($app['url'])) {
            $this->warnings[] = 'app.url is not set';
        } elseif (!filter_var($app['url'], FILTER_VALIDATE_URL)) {
            $this->warnings[] = 'app.url is not a valid URL';
        }

        if (isset($app['debug']) && !is_bool($app['debug'])) {
            $this->errors[] = 'app.debug must be a boolean';
        }

        if (!empty($app['timezone'])) {
            if (!in_array($app['timezone'], timezone_identifiers_list())) {
                $this->errors[] = 'app.timezone is invalid: ' . $app['timezone'];
            }
        }

        $this->validations['app'] = [
            'name' => $app['name'] ?? null,
            'url' => $app['url'] ?? null,
            'debug' => $app['debug'] ?? false,
        ];
    }

    private function validateAuthConfig(array $config): void
    {
        $auth = $config['core']['auth'] ?? [];
        
        if (isset($auth['sessionTtlSeconds'])) {
            if (!is_int($auth['sessionTtlSeconds']) || $auth['sessionTtlSeconds'] < 60) {
                $this->errors[] = 'auth.sessionTtlSeconds must be >= 60';
            }
        }

        if (isset($auth['idleTimeoutSeconds'])) {
            if (!is_int($auth['idleTimeoutSeconds']) || $auth['idleTimeoutSeconds'] < 60) {
                $this->errors[] = 'auth.idleTimeoutSeconds must be >= 60';
            }
        }

        if (isset($auth['passwordMinLength'])) {
            if (!is_int($auth['passwordMinLength']) || $auth['passwordMinLength'] < 8 || $auth['passwordMinLength'] > 128) {
                $this->errors[] = 'auth.passwordMinLength must be between 8 and 128';
            }
        }

        if (isset($auth['lockoutMaxAttempts'])) {
            if (!is_int($auth['lockoutMaxAttempts']) || $auth['lockoutMaxAttempts'] < 1) {
                $this->errors[] = 'auth.lockoutMaxAttempts must be >= 1';
            }
        }

        $this->validations['auth'] = $auth;
    }

    private function validateStorageConfig(array $config): void
    {
        $storage = $config['storage'] ?? [];
        $validDrivers = ['json', 'sqlite', 'mysql', 'postgres'];
        
        if (!empty($storage['default']) && !in_array($storage['default'], $validDrivers)) {
            $this->warnings[] = 'storage.default uses unknown driver: ' . $storage['default'];
        }

        if ($storage['default'] === 'sqlite') {
            $basePath = $this->pluginManager->getBasePath();
            $dbPath = $basePath . '/data/content.db';
            
            if (!file_exists(dirname($dbPath))) {
                $this->errors[] = 'SQLite data directory does not exist';
            } elseif (!is_writable(dirname($dbPath))) {
                $this->warnings[] = 'SQLite data directory is not writable';
            }
        }
    }

    private function validateEnvOverrides(string $basePath): void
    {
        $envVars = [
            'CLICK_APP_NAME',
            'CLICK_APP_URL',
            'CLICK_DEBUG',
            'CLICK_TIMEZONE',
            'CLICK_API_PREFIX',
            'CLICK_ADMIN_PATH',
            'CLICK_SESSION_TTL',
            'CLICK_IDLE_TIMEOUT',
        ];

        $overrides = [];
        
        foreach ($envVars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $key = str_replace('CLICK_', '', strtolower($var));
                $overrides[$key] = $value;
            }
        }

        if (!empty($overrides)) {
            $this->validations['env_overrides'] = [
                'applied' => array_keys($overrides),
                'count' => count($overrides),
            ];
        }
    }

    public function serveValidation(): array
    {
        $this->validateConfig();
        
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'validations' => $this->validations,
            'timestamp' => time(),
        ];
    }

    public function serveConfig(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/config/core.json';
        
        if (!file_exists($configPath)) {
            return ['error' => 'Config not found'];
        }

        $config = json_decode(file_get_contents($configPath), true);
        
        if (isset($config['core']['auth']['passwordHashKey'])) {
            $config['core']['auth']['passwordHashKey'] = '***REDACTED***';
        }
        
        return ['data' => $config];
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
