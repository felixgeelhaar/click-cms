<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Application\Plugin\PluginMarketplace;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * The plugin marketplace endpoint: browse a registry, install from it, or
 * upload a ZIP an administrator already has.
 *
 * Installing a plugin means adding executable code, so this controller gates
 * the surface on a capability (and on the marketplace feature flag): browsing
 * needs ManagePlugins and installing (or uploading) needs InstallPlugins, both
 * administrator-only by default, on top of the authentication and CSRF the
 * request pipeline already enforces. The two install paths are not equally
 * trusted, and that is on purpose: a registry install verifies a signed
 * manifest against a configured public key and checks the package checksum,
 * while an uploaded archive is trusted to the administrator who uploaded it.
 * Both extract defensively — every archive entry is validated against path
 * traversal before a byte lands.
 *
 * Pulled out of the kernel because browsing and installing plugins is not the
 * job of the thing that turns requests into responses. Enablement and the
 * capability checks live here too, so Application only routes the path prefix.
 */
final class MarketplaceController
{
    /**
     * @param callable(): (?array<string, mixed>) $currentUser Resolves the
     *        signed-in user for the current request, or null when anonymous.
     */
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly CoreConfig $config,
        private readonly string $basePath,
        private readonly mixed $currentUser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $path, string $method): array
    {
        if (!$this->config->marketplaceEnabled()) {
            return ['status' => 404, 'error' => 'Marketplace disabled'];
        }

        // Installing a plugin is running code on the server, so it is gated on a
        // capability, not merely on being signed in. Authentication and CSRF
        // are already enforced by the request pipeline; this is the authorization
        // step. Browsing the catalogue needs the weaker ManagePlugins; the
        // install POST needs InstallPlugins. Both are administrator-only by
        // default.
        $role = Role::fromName((($this->currentUser)() ?? [])['role'] ?? null);
        $needed = ($method === 'POST') ? Capability::InstallPlugins : Capability::ManagePlugins;
        if (!$role->can($needed)) {
            return ['status' => 403, 'error' => 'You do not have permission to manage plugins.'];
        }

        $action = ltrim(preg_replace('#^marketplace#', '', $path), '/');
        $marketplace = new PluginMarketplace($this->plugins, $this->basePath);
        $registryUrl = $this->config->marketplaceRegistryUrl();
        $publicKey = $this->config->marketplacePublicKey();

        if ($method === 'POST' && $action === 'install') {
            return $this->install($marketplace, $registryUrl, $publicKey);
        }

        if ($method === 'POST' && $action === 'upload') {
            return $this->upload($marketplace);
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
     * Accept a multipart ZIP upload and install it as an unverified plugin.
     *
     * @return array<string, mixed>
     */
    private function upload(PluginMarketplace $marketplace): array
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return ['status' => 400, 'error' => 'No file was uploaded.'];
        }

        $result = $marketplace->uploadPlugin($_FILES['file']);

        if (!($result['success'] ?? false)) {
            return ['status' => 400, 'error' => $result['error'] ?? 'Upload failed'];
        }

        return ['status' => 201, 'data' => $result['plugin'] ?? $result];
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

        $registryConfigured = $registryUrl !== '' && $publicKey !== '';
        $catalog = $marketplace->getRegistryCatalog($registryUrl, $publicKey);

        // When nothing is configured the catalogue helper reports that as an
        // error — useful for callers that always expect a registry, but the
        // admin screen already has an empty state for it. Surfacing the same
        // sentence as a red banner made a fresh install look broken.
        $errors = $registryConfigured ? ($catalog['errors'] ?? []) : [];

        return ['data' => [
            'available' => $catalog['available'] ?? [],
            'errors' => $errors,
            'installed' => $installed,
            'registryConfigured' => $registryConfigured,
            'message' => ($catalog['available'] ?? [])
                ? 'Registry loaded'
                : ($registryConfigured ? 'Registry has nothing on offer' : 'Marketplace catalog not configured'),
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
