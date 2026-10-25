<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Config\DeploymentMode;
use Click\Cms\Core\Application;

/** Runs before Application boots: no shell, cron, loopback URL or public token. */
final class WebUpdateRunner
{
    /** True means code may have changed: terminate this process without booting. */
    public static function run(string $root): bool
    {
        if (DeploymentMode::managed()) {
            return false;
        }
        $config = CoreConfig::load($root . '/config/core.json');
        $policy = $config->updatePolicy();
        if (!$policy->checksForUpdates() || $config->updateFeedUrl() === '' || $config->updatePublicKeys() === []) {
            return false;
        }
        // Never turn a visitor POST/upload into a maintenance response. A normal
        // read to this CMS (including a headless API read) provides the clock.
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return false;
        }
        $scheduler = new UpdateScheduler($root . '/data/updates/web');
        if (!$scheduler->isDue(time())) {
            return false;
        }
        $scheduler->withLock(static function () use ($root, $scheduler, $config, $policy): void {
            if (!$scheduler->isDue(time())) {
                return;
            }
            // Claim the daily check before I/O. Failed feeds/limited hosts must
            // not retry on every anonymous request and amplify outbound work.
            $scheduler->markChecked(time());
            ignore_user_abort(true);
            $status = ['currentVersion' => Application::VERSION, 'policy' => $policy->value,
                'allowPreRelease' => $config->updateAllowPreRelease(), 'configured' => true];
            $notice = new UpdateNotice($root . '/data/updates');
            $service = new UpdateService($root, new ReleaseFeed($root . '/data/updates'),
                new UpdateInstaller($root, null, $config->updatePublicRoot()), Application::VERSION);
            try {
                // The web worker can invalidate its own PHP pool's bytecode.
                // Hosts that disable both timestamp validation and cache reset
                // cannot safely self-update and must see an explicit refusal.
                $cacheActive = (bool) ini_get('opcache.enable') && PHP_SAPI !== 'cli';
                if ($cacheActive && !(bool) ini_get('opcache.validate_timestamps')
                    && (!function_exists('opcache_reset') || !@opcache_reset())) {
                    $notice->remember($status + [
                        'feedError' => 'Automatic updates require OPcache timestamp validation or permission to reset OPcache.'], time());
                    return;
                }
                $result = $service->applyIfAutomatic($config->updateFeedUrl(), $config->updatePublicKeys(), $policy, $config->updateAllowPreRelease());
                $state = $status + $result['decision'] + ['feedError' => $service->lastFeedError()];
                $notice->remember($state, time());
                if (str_contains($result['error'] ?? '', 'Active requests')) {
                    $scheduler->markChecked(time() - UpdateScheduler::DEFAULT_INTERVAL_SECONDS + 300);
                }
                if (RequestGate::attempted() && function_exists('opcache_reset')) {
                    @opcache_reset();
                }
                if (($result['success'] ?? false) === true) {
                    $notice->forget();
                }
            } catch (\Throwable $error) {
                error_log('Click automatic update failed: ' . $error->getMessage());
                $notice->remember($status + [
                    'feedError' => 'The automatic update could not finish. Check the host PHP error log and file permissions.'], time());
            }
        });
        return RequestGate::attempted();
    }
}
