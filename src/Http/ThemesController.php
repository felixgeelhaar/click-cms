<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Theme\ThemeInstaller;
use Click\Cms\Application\Theme\ThemeRepository;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * Listing the installed themes and choosing which one the site wears.
 *
 * Core for the same reason plugin management is: the admin UI's Themes page
 * depends on it, so it cannot live in a plugin a site might disable — and a site
 * that could not reach its own theme switcher would have no way back from a
 * theme it did not like.
 *
 * The endpoints are gated differently on purpose. Listing is a read any
 * signed-in account may make; an editor seeing which theme is live is not a
 * risk, and hiding it would only make the admin UI lie about why the page is
 * empty. Activating and uploading change what every visitor can see (or what
 * can be activated next), so they need {@see Capability::ManageSettings} —
 * the same bar as the other site-wide switches, because that is what they are.
 *
 * Like the other controllers this one is thin: discovery, validation and
 * persistence all live in {@see ThemeRepository}; ZIP install lives in
 * {@see ThemeInstaller}.
 */
final class ThemesController
{
    /**
     * @param callable(): array<string, mixed> $currentUser Resolves the signed-in
     *        user for the current request, or [] when anonymous.
     */
    public function __construct(
        private readonly ThemeRepository $themes,
        private readonly mixed $currentUser,
        private readonly ?ThemeInstaller $installer = null,
    ) {
    }

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
            'GET /api/themes' => [$this, 'list'],
            'POST /api/themes/activate' => [$this, 'activate'],
            'POST /api/themes/upload' => [$this, 'upload'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        if ($this->user() === []) {
            return ApiFault::of(401, 'Not authenticated', 'unauthenticated');
        }

        $active = $this->themes->active();

        return [
            'data' => [
                'active' => $active?->id,
                'themes' => array_map(
                    fn ($theme): array => $theme->toArray() + [
                        'active' => $active !== null && $theme->id === $active->id,
                        // The exact URL a page would link, cache-busting query and
                        // all, so the admin can show what it will serve rather than
                        // rebuilding the rule in JavaScript and drifting from it.
                        'stylesheetUrl' => $this->themes->stylesheetUrl($theme),
                    ],
                    $this->themes->all()
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(): array
    {
        $user = $this->user();
        if ($user === []) {
            return ApiFault::of(401, 'Not authenticated', 'unauthenticated');
        }
        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ApiFault::of(403, 'You do not have permission to change the theme.', 'forbidden');
        }

        $body = $this->jsonBody();
        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '') {
            return ApiFault::of(400, 'Name the theme to activate.', 'bad_request');
        }

        // 404 rather than 400: the request is well formed, the theme is simply
        // not installed — most often because it was deleted from `themes/` while
        // somebody had the admin screen open.
        if (!$this->themes->activate($id)) {
            return ApiFault::of(404, 'Theme not found', 'not_found');
        }

        $active = $this->themes->active();

        return ['data' => [
            'activated' => true,
            'active' => $active?->id,
            'stylesheetUrl' => $active !== null ? $this->themes->stylesheetUrl($active) : null,
        ]];
    }

    /**
     * Accept a multipart ZIP and install it under `themes/`.
     *
     * @return array<string, mixed>
     */
    public function upload(): array
    {
        $user = $this->user();
        if ($user === []) {
            return ApiFault::of(401, 'Not authenticated', 'unauthenticated');
        }
        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ApiFault::of(403, 'You do not have permission to install themes.', 'forbidden');
        }
        if ($this->installer === null) {
            return ApiFault::of(503, 'Theme install is not available.', 'unavailable');
        }
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return ApiFault::of(400, 'No file was uploaded.', 'bad_request');
        }

        $result = $this->installer->upload($_FILES['file']);
        if (!($result['success'] ?? false)) {
            return ApiFault::of(400, $result['error'] ?? 'Upload failed', 'bad_request');
        }

        return ['status' => 201, 'data' => $result['theme'] ?? $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        return ($this->currentUser)();
    }

    /**
     * The request body, with the same `$_POST` fallback the other controllers
     * use — `php://input` cannot be written in-process, so without it the
     * endpoint could only ever be exercised through a real web server.
     *
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return $_POST;
        }

        $decoded = json_decode($input, true);

        return is_array($decoded) ? $decoded : [];
    }
}
