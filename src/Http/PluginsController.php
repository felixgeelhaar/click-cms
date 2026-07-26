<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Plugin\PluginState;
use Click\Cms\Domain\ValueObjects\PluginId;
use Throwable;

/**
 * Listing plugins and turning them on and off.
 *
 * Core for the same reason user management is: the admin UI's Plugins page
 * depends on it, so it cannot live in a plugin a site might disable. It was in
 * the `rest-api` plugin — a delivery API holding plugin management, which is the
 * fake optionality core.md warns against. The kernel's {@see ApiGuard} refuses a
 * non-administrator any state-changing plugin request before these run.
 */
final class PluginsController
{
    /** Where this installation lives in URL space. */
    private readonly BasePath $urlBase;

    public function __construct(
        private readonly PluginManager $plugins,
        // Null means the domain root, so a caller that never heard of prefixes
        // is unaffected.
        ?BasePath $urlBase = null,
    ) {
        $this->urlBase = $urlBase ?? BasePath::root();
    }

    /**
     * @return array<string, array{string, callable}>
     */
    public function routes(): array
    {
        return [
            'GET /api/plugins' => [$this, 'list'],
            'GET /api/plugins/dependencies' => [$this, 'dependencies'],
            'GET /api/plugins/:id' => [$this, 'get'],
            'POST /api/plugins/:id/activate' => [$this, 'activate'],
            'POST /api/plugins/:id/deactivate' => [$this, 'deactivate'],
            'GET /api/info' => [$this, 'info'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return [
            'data' => array_map(
                static fn ($p): array => [
                    'id' => $p->id->value,
                    'name' => $p->name,
                    'description' => $p->description ?? '',
                    'version' => $p->version->value,
                    'author' => $p->author ?? '',
                    'state' => $p->state->value,
                    'dependencies' => $p->dependencies ?? [],
                    'hooks' => $p->hooks ?? [],
                ],
                $this->plugins->all()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $plugin = $this->resolve($id);
        if ($plugin === null) {
            return ['status' => 404, 'error' => 'Plugin not found'];
        }

        $dir = $this->plugins->getBasePath() . '/plugins/' . $plugin->id->value;

        return ['data' => [
            'id' => $plugin->id->value,
            'name' => $plugin->name,
            'description' => $plugin->description ?? '',
            'version' => $plugin->version->value,
            'author' => $plugin->author ?? '',
            'state' => $plugin->state->value,
            'dependencies' => $plugin->dependencies ?? [],
            'hooks' => $plugin->hooks ?? [],
            'changelog' => $this->fileOrNull($dir . '/CHANGELOG.md'),
            'signature' => $this->fileOrNull($dir . '/signature.txt'),
            'manifest' => $this->jsonFileOrNull($dir . '/manifest.json'),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(string $id): array
    {
        return $this->toggle($id, activate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(string $id): array
    {
        return $this->toggle($id, activate: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function dependencies(): array
    {
        $graph = [];
        foreach ($this->plugins->all() as $plugin) {
            $deps = [];
            foreach ($plugin->dependencies ?? [] as $dep) {
                $depPlugin = $this->resolve($dep);
                $deps[] = [
                    'id' => $dep,
                    'installed' => $depPlugin !== null,
                    'activated' => $depPlugin !== null && $depPlugin->state === PluginState::ACTIVATED,
                ];
            }
            $graph[] = [
                'id' => $plugin->id->value,
                'name' => $plugin->name,
                'dependencies' => $deps,
                'dependents' => [],
            ];
        }

        foreach ($graph as &$node) {
            foreach ($node['dependencies'] as $dep) {
                foreach ($graph as &$other) {
                    if ($other['id'] === $dep['id']) {
                        $other['dependents'][] = $node['id'];
                    }
                }
                unset($other);
            }
        }
        unset($node);

        return ['data' => $graph];
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return ['data' => [
            'name' => 'Click CMS',
            'version' => '0.1.0',
            // Addresses a client is meant to call, so they are spelt the way
            // this installation is reached rather than the way its source
            // writes them.
            'endpoints' => [
                'pages' => $this->urlBase->url('/api/pages'),
                'users' => $this->urlBase->url('/api/users'),
                'media' => $this->urlBase->url('/api/media'),
                'plugins' => $this->urlBase->url('/api/plugins'),
            ],
        ]];
    }

    /* -------------------------------------------------------- helpers -- */

    private function resolve(string $id): ?object
    {
        try {
            return $this->plugins->get(PluginId::fromString($id));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toggle(string $id, bool $activate): array
    {
        try {
            $pluginId = PluginId::fromString($id);
        } catch (Throwable) {
            return ['status' => 400, 'error' => 'Invalid plugin ID'];
        }

        $result = $activate
            ? $this->plugins->activate($pluginId)
            : $this->plugins->deactivate($pluginId);

        if (!($result['success'] ?? false)) {
            return ['status' => 400, 'error' => $result['error'] ?? 'Operation failed'];
        }

        $key = $activate ? 'activated' : 'deactivated';

        return ['data' => [$key => true, 'id' => $id]];
    }

    private function fileOrNull(string $path): ?string
    {
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonFileOrNull(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
