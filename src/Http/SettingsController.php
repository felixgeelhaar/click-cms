<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\Settings;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * Read or change the runtime settings.
 *
 * Reading is allowed to any signed-in user, so the admin UI can show the
 * current mode to everyone who can see the admin. Changing one needs the
 * settings capability, which only an administrator has — turning a site
 * headless takes its public pages away, and that is not an editor's call.
 *
 * Pulled out of the kernel because flipping headless, the site name and
 * free-form editing is not the job of the thing that turns requests into
 * responses. Application only routes the path.
 */
final class SettingsController
{
    /** Loaded on first use when the constructor was given a path. */
    private ?Settings $loaded = null;

    /**
     * @param Settings|string $settings A loaded instance, or the path of
     *        settings.json to load when the first request arrives. An instance
     *        is shared with the rest of the process so a PUT is visible without
     *        a reboot; a path is for a caller that does not hold one yet.
     * @param callable(): (?array<string, mixed>) $currentUser Resolves the
     *        signed-in user for the current request, or null when anonymous.
     * @param (callable(): void)|null $flushRenderCache Called after a PUT.
     *        Settings are not content documents, so the storage decorator that
     *        normally drops the render cache never sees this write. Optional so
     *        a caller with no cache — a unit test — still changes settings.
     */
    public function __construct(
        private readonly Settings|string $settings,
        private readonly mixed $currentUser,
        private readonly mixed $flushRenderCache = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $method): array
    {
        $user = ($this->currentUser)();
        if ($user === null) {
            return ApiFault::of(401, 'Not authenticated', 'unauthenticated');
        }

        if ($method === 'GET') {
            return ['data' => $this->settings()->toArray()];
        }

        if ($method !== 'PUT') {
            return ApiFault::of(405, 'Method not allowed', 'method_not_allowed');
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ApiFault::of(403, 'You do not have permission to change settings.', 'forbidden');
        }

        $data = $this->jsonBody();
        $settings = $this->settings();

        // Only the keys we understand are acted on; an unknown key is ignored
        // rather than stored, so the settings file cannot accrete arbitrary
        // content a client decides to post.
        if (array_key_exists('headless', $data)) {
            $settings->setHeadless((bool) $data['headless']);
        }
        if (array_key_exists('siteName', $data) && is_string($data['siteName'])) {
            $settings->setSiteName($data['siteName']);
        }
        if (array_key_exists('freeformEditing', $data)) {
            $settings->setFreeformEditing((bool) $data['freeformEditing']);
        }

        // The site name is the brand in every page's header, and headless mode
        // changes whether there is a public page at all, so both reach every
        // cached document. Free-form on/off does not change rendered HTML by
        // itself (existing builder pages still render), but flushing keeps the
        // rule simple: a settings write drops the cache.
        if ($this->flushRenderCache !== null) {
            ($this->flushRenderCache)();
        }

        return ['data' => $settings->toArray()];
    }

    private function settings(): Settings
    {
        if ($this->settings instanceof Settings) {
            return $this->settings;
        }

        return $this->loaded ??= Settings::load($this->settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = file_get_contents('php://input');

        if (empty($input)) {
            return $_POST;
        }

        $data = json_decode($input, true);

        return is_array($data) ? $data : [];
    }
}
