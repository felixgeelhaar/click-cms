<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Application\Plugin\PluginMarketplace;

/**
 * The plugin marketplace endpoint: browse a registry and install from it.
 *
 * Installing a plugin means adding executable code, so the kernel's ApiGuard
 * already refuses anyone without the install capability before this runs. The
 * two install paths are not equally trusted, and that is on purpose: a registry
 * install verifies a signed manifest against a configured public key, while an
 * uploaded archive verifies nothing — an administrator-only action, matching
 * what other CMSs do, but a stated decision rather than an accident.
 *
 * Pulled out of the kernel because browsing and installing plugins is not the
 * job of the thing that turns requests into responses.
 */
final class MarketplaceController
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly CoreConfig $config,
        private readonly string $basePath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $path, string $method): array
    {
        $action = ltrim(preg_replace('#^marketplace#', '', $path), '/');
        $marketplace = new PluginMarketplace($this->plugins, $this->basePath);
        $registryUrl = $this->config->marketplaceRegistryUrl();
        $publicKey = $this->config->marketplacePublicKey();

        if ($method === 'POST' && $action === 'install') {
            return $this->install($marketplace, $registryUrl, $publicKey);
        }

        if ($method !== 'GET') {
            return ['status' => 405, 'error' => 'Method not allowed'];
        }

        return $this->catalog($marketplace, $registryUrl, $publicKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function install(PluginMarketplace $marketplace, string $registryUrl, string $publicKey): array
    {
        $data = $this->jsonBody();
        $pluginId = $data['id'] ?? null;
        if ($pluginId === null) {
            return ['status' => 400, 'error' => 'Plugin id is required'];
        }

        $result = $marketplace->installFromRegistry($registryUrl, $publicKey, $pluginId, $data['version'] ?? null);

        if (!($result['success'] ?? false)) {
            return ['status' => 400, 'error' => $result['error'] ?? 'Install failed'];
        }

        return ['data' => $result['plugin'] ?? $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(PluginMarketplace $marketplace, string $registryUrl, string $publicKey): array
    {
        $installed = array_map(
            static fn ($p): array => [
                'id' => $p->id->value,
                'name' => $p->name,
                'description' => $p->description,
                'version' => $p->version->value,
                'state' => $p->state->value,
            ],
            $this->plugins->all()
        );

        $catalog = $marketplace->getRegistryCatalog($registryUrl, $publicKey);

        return ['data' => [
            'available' => $catalog['available'] ?? [],
            'errors' => $catalog['errors'] ?? [],
            'installed' => $installed,
            'message' => ($catalog['available'] ?? [])
                ? 'Registry loaded'
                : 'Marketplace catalog not configured',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return $_POST;
        }

        $data = json_decode($input, true);

        return is_array($data) ? $data : [];
    }
}
