<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_rest_api extends \Click\Cms\Application\Plugin\BasePlugin
{
    public function getPluginId(): string
    {
        return 'rest-api';
    }

    public function getPluginName(): string
    {
        return 'REST API';
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
        // What this plugin is, honestly stated.
        //
        // The public *delivery* of pages — reading published content anonymously
        // for a headless front end — is core's now: `GET /api/pages` and
        // `GET /api/pages/:slug` in CoreApiRoutes serve published documents
        // without a session, respecting draft-and-publish by reading only what
        // is in `content/`. So this plugin registers no page routes at all: a
        // second copy would be shadowed by core (which is matched first in
        // Application::handleApiRequest) and, worse, this plugin's copies were
        // the pre-languages, locale-blind versions that would answer wrongly if
        // they ever ran.
        //
        // Removed for exactly that reason: `GET /api/pages/:slug/versions`,
        // `GET /api/pages/:slug/versions/:versionId` and the matching restore
        // POST. Core registers all three (locale-aware), matches first, and so
        // the plugin's never executed — dead routes that read as live.
        //
        // What is left below is not delivery. User and plugin management are
        // management endpoints the admin UI depends on, and core does not own
        // them yet; they live here only because nothing has moved them into
        // core. `GET /api/info` is a small public API index. None of these is a
        // reason a delivery API exists, which is worth stating plainly rather
        // than letting the plugin's name imply a read surface it no longer adds.
        return [
            // Users
            'GET /api/users' => [$this, 'getUsers'],
            'GET /api/users/:username' => [$this, 'getUser'],
            'POST /api/users' => [$this, 'createUser'],
            'PUT /api/users/:username' => [$this, 'updateUser'],
            'DELETE /api/users/:username' => [$this, 'deleteUser'],

            // Plugins
            'GET /api/plugins' => [$this, 'getPlugins'],
            'GET /api/plugins/:id' => [$this, 'getPlugin'],
            'POST /api/plugins/:id/activate' => [$this, 'activatePlugin'],
            'POST /api/plugins/:id/deactivate' => [$this, 'deactivatePlugin'],

            // Plugin Dependencies
            'GET /api/plugins/dependencies' => [$this, 'getPluginDependencies'],

            // System
            'GET /api/info' => [$this, 'getInfo'],
        ];
    }

    public function getInfo(): array
    {
        return [
            'data' => [
                'name' => 'Click CMS',
                'version' => '0.1.0',
                'endpoints' => [
                    'pages' => '/api/pages',
                    'users' => '/api/users',
                    'media' => '/api/media',
                    'plugins' => '/api/plugins',
                ],
            ],
        ];
    }

    public function getPlugins(): array
    {
        $plugins = $this->pluginManager->all();
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;

        $result = [];

        foreach ($plugins as $plugin) {
            $pluginData = [
                'id' => $plugin->id->value,
                'name' => $plugin->name,
                'description' => $plugin->description ?? '',
                'version' => $plugin->version->value,
                'author' => $plugin->author ?? '',
                'state' => $plugin->state->value,
                'dependencies' => $plugin->dependencies ?? [],
                'hooks' => $plugin->hooks ?? [],
            ];

            $result[] = $pluginData;
        }

        return ['data' => $result];
    }

    public function getPlugin(string $id): array
    {
        try {
            $pluginId = \Click\Cms\Domain\ValueObjects\PluginId::fromString($id);
        } catch (\Exception $e) {
            return ['error' => 'Invalid plugin ID', 'status' => 400];
        }

        $plugin = $this->pluginManager->get($pluginId);

        if ($plugin === null) {
            return ['error' => 'Plugin not found', 'status' => 404];
        }

        $basePath = $this->pluginManager->getBasePath();
        $pluginPath = $basePath . '/plugins/' . $plugin->id->value;

        $changelog = null;
        $changelogPath = $pluginPath . '/CHANGELOG.md';
        if (file_exists($changelogPath)) {
            $changelog = file_get_contents($changelogPath);
        }

        $signature = null;
        $signaturePath = $pluginPath . '/signature.txt';
        if (file_exists($signaturePath)) {
            $signature = file_get_contents($signaturePath);
        }

        $manifest = null;
        $manifestPath = $pluginPath . '/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
        }

        return ['data' => [
            'id' => $plugin->id->value,
            'name' => $plugin->name,
            'description' => $plugin->description ?? '',
            'version' => $plugin->version->value,
            'author' => $plugin->author ?? '',
            'state' => $plugin->state->value,
            'dependencies' => $plugin->dependencies ?? [],
            'hooks' => $plugin->hooks ?? [],
            'changelog' => $changelog,
            'signature' => $signature,
            'manifest' => $manifest,
        ]];
    }

    public function activatePlugin(string $id): array
    {
        try {
            $pluginId = \Click\Cms\Domain\ValueObjects\PluginId::fromString($id);
        } catch (\Exception $e) {
            return ['error' => 'Invalid plugin ID', 'status' => 400];
        }

        $result = $this->pluginManager->activate($pluginId);

        if (!$result['success']) {
            return ['error' => $result['error'], 'status' => 400];
        }

        return ['data' => ['activated' => true, 'id' => $id]];
    }

    public function deactivatePlugin(string $id): array
    {
        try {
            $pluginId = \Click\Cms\Domain\ValueObjects\PluginId::fromString($id);
        } catch (\Exception $e) {
            return ['error' => 'Invalid plugin ID', 'status' => 400];
        }

        $result = $this->pluginManager->deactivate($pluginId);

        if (!$result['success']) {
            return ['error' => $result['error'], 'status' => 400];
        }

        return ['data' => ['deactivated' => true, 'id' => $id]];
    }

    public function getUsers(): array
    {
        $contentService = $this->pluginManager->getContentService();
        $users = $contentService->all('user');

        return [
            'data' => array_map(fn($u) => $this->sanitizeUser($u->toArray()), $users),
        ];
    }

    public function getUser(string $username): array
    {
        $contentService = $this->pluginManager->getContentService();
        $user = $contentService->user($username);

        if (!$user) {
            return ['error' => 'User not found', 'status' => 404];
        }

        return ['data' => $this->sanitizeUser($user->toArray())];
    }

    public function createUser(): array
    {
        $data = $this->getJsonBody();

        if (!isset($data['email'])) {
            return ['error' => 'Email required', 'status' => 400];
        }

        if (empty($data['password'])) {
            return ['error' => 'Password required', 'status' => 400];
        }

        $passwordValidation = $this->validatePassword($data['password']);
        if ($passwordValidation !== null) {
            return ['error' => $passwordValidation, 'status' => 400];
        }

        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        $username = $data['username'] ?? $this->slugify($data['email']);

        $contentService = $this->pluginManager->getContentService();

        if ($contentService->user($username)) {
            return ['error' => 'User already exists', 'status' => 409];
        }

        $content = \Click\Cms\Domain\Content\Content::create(
            \Click\Cms\Domain\ValueObjects\ContentKey::user($username),
            $data
        );

        $contentService->save($content);

        $this->pluginManager->executeHook('user_create', [
            'username' => $username,
            'email' => $data['email'] ?? '',
            'role' => $data['role'] ?? 'editor',
        ]);

        return ['data' => $content->toArray(), 'status' => 201];
    }

    public function updateUser(string $username): array
    {
        $data = $this->getJsonBody();
        $contentService = $this->pluginManager->getContentService();

        $user = $contentService->user($username);

        if (!$user) {
            return ['error' => 'User not found', 'status' => 404];
        }

        if (isset($data['password']) && $data['password'] !== '') {
            $passwordValidation = $this->validatePassword($data['password']);
            if ($passwordValidation !== null) {
                return ['error' => $passwordValidation, 'status' => 400];
            }
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        } else {
            unset($data['password']);
        }

        $updated = $user->update($data);
        $contentService->save($updated);

        $oldData = $user->toArray();
        $changes = [];
        foreach ($data as $key => $value) {
            if (($oldData[$key] ?? null) !== $value) {
                $changes[$key] = ['old' => $oldData[$key] ?? null, 'new' => $value];
            }
        }

        if (isset($data['role']) && $data['role'] !== ($oldData['role'] ?? '')) {
            $this->pluginManager->executeHook('user_role_change', [
                'username' => $username,
                'old_role' => $oldData['role'] ?? '',
                'new_role' => $data['role'],
            ]);
        }

        // Fires on the user account's `status` field, not a page's. This is
        // deliberately kept: draft-and-publish removed `status` from publishable
        // *documents* (pages), but a user is not publishable, and its status is a
        // separate account state — `active` versus suspended — that core still
        // reads at login (Application.php honours `$userData['status'] ?? 'active'`
        // before letting anyone in). So the field this keys on still exists and
        // the event can still fire; it is not the removed page-publication status.
        if (isset($data['status']) && $data['status'] !== ($oldData['status'] ?? '')) {
            $this->pluginManager->executeHook('user_status_change', [
                'username' => $username,
                'old_status' => $oldData['status'] ?? '',
                'new_status' => $data['status'],
            ]);
        }

        $this->pluginManager->executeHook('user_update', [
            'username' => $username,
            'changes' => $changes,
        ]);

        return ['data' => $this->sanitizeUser($updated->toArray())];
    }

    public function deleteUser(string $username): array
    {
        $contentService = $this->pluginManager->getContentService();

        if (!$contentService->user($username)) {
            return ['error' => 'User not found', 'status' => 404];
        }

        $contentService->delete(\Click\Cms\Domain\ValueObjects\ContentKey::user($username));

        $this->pluginManager->executeHook('user_delete', [
            'username' => $username,
            'deleted_by' => $_SESSION['user']['username'] ?? 'system',
        ]);

        return ['data' => ['deleted' => true, 'username' => $username]];
    }

    private function getJsonBody(): array
    {
        $input = file_get_contents('php://input');

        if (empty($input)) {
            return $_POST;
        }

        $data = json_decode($input, true);

        return $data ?? [];
    }

    private function sanitizeUser(array $user): array
    {
        // Removed rather than nulled. A "password": null in a response reads
        // like an account without one, which is alarming and untrue.
        unset($user['data']['password'], $user['password']);

        return $user;
    }

    private function validatePassword(string $password): ?string
    {
        $minLength = 8;

        if (strlen($password) < $minLength) {
            return 'Password must be at least ' . $minLength . ' characters.';
        }

        if (strlen($password) > 128) {
            return 'Password must be less than 128 characters.';
        }

        return null;
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    public function getPluginDependencies(): array
    {
        $plugins = $this->pluginManager->all();
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;

        $graph = [];

        foreach ($plugins as $plugin) {
            $dependencies = $plugin->dependencies ?? [];

            $depDetails = [];
            foreach ($dependencies as $dep) {
                try {
                    $depPlugin = $this->pluginManager->get(\Click\Cms\Domain\ValueObjects\PluginId::fromString($dep));
                    $depDetails[] = [
                        'id' => $dep,
                        'installed' => $depPlugin !== null,
                        'activated' => $depPlugin !== null && $depPlugin->state === $PluginState::ACTIVATED,
                    ];
                } catch (\Exception $e) {
                    $depDetails[] = [
                        'id' => $dep,
                        'installed' => false,
                        'activated' => false,
                    ];
                }
            }

            $graph[] = [
                'id' => $plugin->id->value,
                'name' => $plugin->name,
                'dependencies' => $depDetails,
                'dependents' => [],
            ];
        }

        foreach ($graph as &$node) {
            foreach ($node['dependencies'] as $dep) {
                foreach ($graph as &$depNode) {
                    if ($depNode['id'] === $dep['id']) {
                        $depNode['dependents'][] = $node['id'];
                    }
                }
            }
        }

        return ['data' => $graph];
    }
}
