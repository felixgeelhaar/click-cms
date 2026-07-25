<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Update\UpdateService;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * Seeing and applying updates to the CMS itself.
 *
 * Every endpoint here, including the two that only read, is gated on
 * {@see Capability::InstallPlugins}. That is deliberate and stronger than it
 * first looks: installing a release is running new code with the site's own
 * privileges, which is the same act as installing a plugin and belongs behind
 * the same permission. The reads are gated too because the feed URL, the
 * pending version and the update history describe what software this server
 * runs and where it fetches code from — reconnaissance an editor has no reason
 * to be handed.
 *
 * Core rather than a plugin, for the reason the plugin endpoints are: a site
 * that has disabled the wrong plugin must still be able to take a security
 * release.
 */
final class UpdatesController
{
    /**
     * @param callable(): array<string, mixed> $currentUser Resolves the signed-in
     *        user for the current request, or [] when anonymous.
     */
    public function __construct(
        private readonly UpdateService $updates,
        private readonly CoreConfig $config,
        private readonly mixed $currentUser,
    ) {
    }

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
            'GET /api/updates' => [$this, 'status'],
            'POST /api/updates/check' => [$this, 'check'],
            'POST /api/updates/apply' => [$this, 'apply'],
            'GET /api/updates/history' => [$this, 'history'],
        ];
    }

    /**
     * What is running, what the policy is, and what is on offer.
     *
     * The check happens on this read rather than only on demand so the page an
     * administrator opens already answers the question they came with.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $denied = $this->denied();
        if ($denied !== null) {
            return $denied;
        }

        return ['data' => $this->state()];
    }

    /**
     * Ask the feed again now. Same shape as {@see status}; a POST because it
     * reaches out to the network on the caller's say-so.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $denied = $this->denied();
        if ($denied !== null) {
            return $denied;
        }

        return ['data' => $this->state()];
    }

    /**
     * Install the release currently on offer.
     *
     * This is the administrator pressing the button, so the policy's
     * "may this happen unattended?" answer is not consulted — but there must
     * still be something to install.
     *
     * @return array<string, mixed>
     */
    public function apply(): array
    {
        $denied = $this->denied();
        if ($denied !== null) {
            return $denied;
        }

        $result = $this->updates->applyApproved(
            $this->config->updateFeedUrl(),
            $this->config->updatePublicKey(),
            $this->config->updatePolicy(),
            $this->config->updateAllowPreRelease(),
        );

        if (!$result['attempted']) {
            return ['status' => 400, 'error' => $result['error'] ?? 'There is no update to install.'];
        }

        if (!$result['success']) {
            // The installer rolls back on failure, so the site is as it was; the
            // administrator needs the reason, not a bare 500.
            return ['status' => 500, 'error' => $result['error'] ?? 'The update could not be installed.'];
        }

        return ['data' => [
            'installed' => true,
            'version' => $result['decision']['release']['version'] ?? null,
            'backup' => $result['backup'],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(): array
    {
        $denied = $this->denied();
        if ($denied !== null) {
            return $denied;
        }

        return ['data' => $this->updates->history()];
    }

    /* -------------------------------------------------------------- helpers -- */

    /**
     * The current picture, shared by the two read-ish endpoints.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $decision = $this->updates->check(
            $this->config->updateFeedUrl(),
            $this->config->updatePublicKey(),
            $this->config->updatePolicy(),
            $this->config->updateAllowPreRelease(),
        );

        return [
            'currentVersion' => $this->updates->currentVersion(),
            'policy' => $this->config->updatePolicy()->value,
            'allowPreRelease' => $this->config->updateAllowPreRelease(),
            'configured' => $this->config->updateFeedUrl() !== '',
            // Why the feed gave nothing, when it did — "already up to date" and
            // "your feed is misconfigured" look identical without it.
            'feedError' => $this->updates->lastFeedError(),
        ] + $decision->toArray();
    }

    /**
     * The refusal for this request, or null when it may proceed.
     *
     * @return array<string, mixed>|null
     */
    private function denied(): ?array
    {
        $user = ($this->currentUser)();
        if ($user === []) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::InstallPlugins)) {
            return ['status' => 403, 'error' => 'You do not have permission to update this site.'];
        }

        return null;
    }
}
