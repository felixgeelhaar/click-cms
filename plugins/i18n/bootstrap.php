<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_i18n extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/i18n';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'i18n';
    }

    public function getPluginName(): string
    {
        return 'Multi-language Support';
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
            'GET /api/i18n/languages' => [$this, 'listLanguages'],
            'POST /api/i18n/languages' => [$this, 'createLanguage'],
            'PUT /api/i18n/languages/:code' => [$this, 'updateLanguage'],
            'DELETE /api/i18n/languages/:code' => [$this, 'deleteLanguage'],
            'GET /api/i18n/translations' => [$this, 'listTranslations'],
            'GET /api/i18n/translations/:locale' => [$this, 'getTranslations'],
            'PUT /api/i18n/translations/:locale' => [$this, 'updateTranslations'],
            'GET /api/i18n/settings' => [$this, 'getSettings'],
            'PUT /api/i18n/settings' => [$this, 'updateSettings'],
        ];
    }

    private function loadLanguages(): array
    {
        $file = $this->dataDir . '/languages.json';
        
        if (!file_exists($file)) {
            return [
                [
                    'code' => 'en',
                    'name' => 'English',
                    'native' => 'English',
                    'direction' => 'ltr',
                    'enabled' => true,
                    'default' => true,
                ],
            ];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveLanguages(array $languages): void
    {
        $file = $this->dataDir . '/languages.json';
        file_put_contents($file, json_encode($languages, JSON_PRETTY_PRINT));
    }

    private function loadTranslations(string $locale): array
    {
        $file = $this->dataDir . "/translations-{$locale}.json";
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveTranslations(string $locale, array $translations): void
    {
        $file = $this->dataDir . "/translations-{$locale}.json";
        file_put_contents($file, json_encode($translations, JSON_PRETTY_PRINT));
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        
        if (!file_exists($file)) {
            return [
                'default_locale' => 'en',
                'detect_browser' => true,
                'url_strategy' => 'subdirectory',
                'show_switcher' => true,
                'switcher_position' => 'header',
                'fallback_locale' => 'en',
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSettings(array $settings): void
    {
        $file = $this->dataDir . '/settings.json';
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT));
    }

    public function listLanguages(): array
    {
        return $this->loadLanguages();
    }

    public function createLanguage(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['code']) || empty($data['name'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $languages = $this->loadLanguages();
        
        foreach ($languages as $lang) {
            if ($lang['code'] === $data['code']) {
                http_response_code(400);
                return ['error' => 'Language already exists'];
            }
        }

        $language = [
            'code' => $data['code'],
            'name' => $data['name'],
            'native' => $data['native'] ?? $data['name'],
            'direction' => $data['direction'] ?? 'ltr',
            'enabled' => $data['enabled'] ?? true,
            'default' => false,
        ];

        $languages[] = $language;
        $this->saveLanguages($languages);

        $this->saveTranslations($data['code'], []);

        return $language;
    }

    public function updateLanguage(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $code = $params['code'] ?? null;
        
        $languages = $this->loadLanguages();
        
        foreach ($languages as &$language) {
            if ($language['code'] === $code) {
                if (isset($data['name'])) {
                    $language['name'] = $data['name'];
                }
                if (isset($data['native'])) {
                    $language['native'] = $data['native'];
                }
                if (isset($data['direction'])) {
                    $language['direction'] = $data['direction'];
                }
                if (isset($data['enabled'])) {
                    $language['enabled'] = $data['enabled'];
                }
                if (isset($data['default']) && $data['default']) {
                    foreach ($languages as &$l) {
                        $l['default'] = false;
                    }
                    $language['default'] = true;
                }
                
                $this->saveLanguages($languages);
                return $language;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Language not found'];
    }

    public function deleteLanguage(array $params): array
    {
        $code = $params['code'] ?? null;
        $languages = $this->loadLanguages();
        
        if (count($languages) <= 1) {
            http_response_code(400);
            return ['error' => 'Cannot delete the only language'];
        }

        $filtered = array_filter($languages, function ($l) use ($code) {
            return $l['code'] !== $code;
        });
        
        $this->saveLanguages(array_values($filtered));
        
        return ['success' => true];
    }

    public function listTranslations(): array
    {
        $languages = $this->loadLanguages();
        
        return array_map(function ($lang) {
            $translations = $this->loadTranslations($lang['code']);
            return [
                'locale' => $lang['code'],
                'language' => $lang['name'],
                'count' => count($translations),
            ];
        }, $languages);
    }

    public function getTranslations(array $params): array
    {
        $locale = $params['locale'] ?? null;
        
        return [
            'locale' => $locale,
            'translations' => $this->loadTranslations($locale),
        ];
    }

    public function updateTranslations(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $locale = $params['locale'] ?? null;
        
        if (empty($data['translations'])) {
            http_response_code(400);
            return ['error' => 'Missing translations'];
        }

        $this->saveTranslations($locale, $data['translations']);
        
        return [
            'locale' => $locale,
            'count' => count($data['translations']),
        ];
    }

    public function getSettings(): array
    {
        return $this->loadSettings();
    }

    public function updateSettings(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $settings = $this->loadSettings();
        
        $settings = array_merge($settings, $data);
        $this->saveSettings($settings);
        
        return $settings;
    }
}
