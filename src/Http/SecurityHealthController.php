<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Plugin\PluginExecutionPolicy;
use Click\Cms\Domain\Update\UpdatePolicy;

/** Read-only, evidence-based configuration checks for an administrator. */
final class SecurityHealthController
{
    /**
     * @param array{phpVersionId?: int, phpVersion?: string, displayErrors?: string|false, exposePhp?: string|false}|null $runtime
     */
    public function __construct(
        private readonly CoreConfig $config,
        private readonly PluginExecutionPolicy $pluginPolicy,
        private readonly ?array $runtime = null,
    ) {}

    /** @return array{data: array{checks: list<array<string, string>>}} */
    public function show(): array
    {
        $checks = [];
        $authEnabled = $this->config->authEnabled();
        $checks[] = [
            'id' => 'authentication',
            'label' => 'Authentication',
            'status' => $authEnabled ? 'good' : 'attention',
            'value' => $authEnabled ? 'enabled' : 'disabled',
            'detail' => $authEnabled
                ? 'The configuration enables authenticated administration.'
                : 'Authentication is disabled in configuration. Enable core.auth.enabled before exposing administration.',
            'action' => 'Review core.auth.enabled in config/core.json.',
            'href' => '',
        ];

        $backupEnabled = $this->config->backupEnabled();
        $checks[] = [
            'id' => 'scheduled-backups',
            'label' => 'Scheduled backups',
            'status' => $backupEnabled ? 'review' : 'attention',
            'value' => $backupEnabled ? 'configured' : 'disabled',
            'detail' => $backupEnabled
                ? sprintf(
                    'Configured every %d hours, retaining %d archives%s. This does not verify a recent archive or a successful restore.',
                    $this->config->backupIntervalHours(),
                    $this->config->backupKeep(),
                    $this->config->backupIncludeMedia() ? ' with media' : ' without media',
                )
                : 'Scheduled backups are disabled. Enable core.backup.enabled and configure the backup command before relying on recoverability.',
            'action' => $backupEnabled
                ? 'Run a restore drill and confirm an off-site copy exists.'
                : 'Enable core.backup.enabled and schedule bin/click-backup.php.',
            'href' => '',
        ];

        $policy = $this->config->updatePolicy();
        [$policyStatus, $policyDetail] = $this->updatePolicyAssessment($policy);
        $checks[] = [
            'id' => 'update-policy',
            'label' => 'Update policy',
            'status' => $policyStatus,
            'value' => $policy->value,
            'detail' => $policyDetail,
            'action' => $policyStatus === 'good'
                ? 'Review available updates in the Updates screen.'
                : 'Review the deployment update policy and keep a tested update path.',
            'href' => '/admin/updates',
        ];

        $deploymentManaged = $this->config->deploymentManaged();
        $checks[] = [
            'id' => 'deployment-management',
            'label' => 'Deployment management',
            'status' => 'good',
            'value' => $deploymentManaged ? 'deployment-managed' : 'application-managed',
            'detail' => $deploymentManaged
                ? 'The deployment environment manages code updates; the CMS updater and marketplace are disabled.'
                : 'The CMS updater and marketplace follow their configured policies.',
            'action' => $deploymentManaged
                ? 'Review the deployment pipeline and its rollback procedure.'
                : 'Review the CMS update and marketplace policy.',
            'href' => $deploymentManaged ? '' : '/admin/updates',
        ];

        $runtime = $this->runtimeValues();
        $phpIssues = [];
        if ($runtime['phpVersionId'] < 80100) {
            $phpIssues[] = 'PHP ' . $runtime['phpVersion'] . ' is below the supported minimum of 8.1.';
        }
        if ($this->isEnabled($runtime['displayErrors'])) {
            $phpIssues[] = 'display_errors is enabled.';
        }
        if ($this->isEnabled($runtime['exposePhp'])) {
            $phpIssues[] = 'expose_php is enabled.';
        }
        $checks[] = [
            'id' => 'php-runtime',
            'label' => 'PHP runtime and configuration',
            'status' => $phpIssues === [] ? 'good' : 'attention',
            'value' => 'PHP ' . $runtime['phpVersion'],
            'detail' => $phpIssues === []
                ? 'The runtime meets the PHP 8.1 minimum; display_errors and expose_php are disabled. This check does not evaluate the PHP branch lifecycle.'
                : implode(' ', $phpIssues),
            'action' => $phpIssues === []
                ? 'Check whether this PHP branch still receives security fixes.'
                : 'Upgrade PHP and disable the reported production settings; check branch support dates.',
            'href' => 'https://www.php.net/supported-versions.php',
            'external' => 'true',
        ];

        $checks[] = [
            'id' => 'session-cookie',
            'label' => 'Session cookie flags',
            'status' => 'good',
            'value' => 'HttpOnly; SameSite=Lax; Secure on HTTPS',
            'detail' => 'SessionStore sets these flags when it issues the admin session cookie.',
            'action' => 'No action required unless the session cookie implementation changes.',
            'href' => '',
        ];

        $mode = $this->pluginPolicy->mode();
        $checks[] = [
            'id' => 'plugin-admission',
            'label' => 'Plugin admission',
            'status' => $mode === 'unrestricted' ? 'review' : 'good',
            'value' => $mode,
            'detail' => match ($mode) {
                'allowlist' => 'Only plugin IDs admitted by CLICK_ALLOWED_PLUGINS may run. The allowlist itself is not shown here.',
                'disabled' => 'CLICK_ALLOWED_PLUGINS disables all plugin admission.',
                default => 'No plugin allowlist is configured; discovered plugins are admitted by the deployment policy.',
            },
            'action' => 'Review installed extensions and decide which plugin IDs the deployment should admit.',
            'href' => '/admin/plugins',
        ];

        return ['data' => ['checks' => $checks]];
    }

    /** @return array{0: string, 1: string} */
    private function updatePolicyAssessment(UpdatePolicy $policy): array
    {
        return match ($policy) {
            UpdatePolicy::Security => ['good', 'Security releases below major are installed automatically; other releases wait for review.'],
            UpdatePolicy::Manual => ['review', 'Automatic checks and notifications are disabled; a separate deployment process must track releases.'],
            UpdatePolicy::Notify => ['review', 'Updates are reported, but none are installed automatically.'],
            UpdatePolicy::Minor => ['review', 'Patch and minor releases may install automatically; major releases wait.'],
            UpdatePolicy::All => ['attention', 'All release sizes, including major releases, may install automatically.'],
        };
    }

    /** @return array{phpVersionId: int, phpVersion: string, displayErrors: string|false, exposePhp: string|false} */
    private function runtimeValues(): array
    {
        return array_replace([
            'phpVersionId' => PHP_VERSION_ID,
            'phpVersion' => PHP_VERSION,
            'displayErrors' => ini_get('display_errors'),
            'exposePhp' => ini_get('expose_php'),
        ], $this->runtime ?? []);
    }

    private function isEnabled(string|false $value): bool
    {
        return $value !== false && !in_array(strtolower(trim($value)), ['', '0', 'off', 'false', 'no'], true);
    }
}
