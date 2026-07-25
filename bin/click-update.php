#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check for a release and, if the policy allows it, install it.
 *
 *   php bin/click-update.php            # check, install only what policy permits
 *   php bin/click-update.php --force    # ignore the interval, check now
 *   php bin/click-update.php --dry-run  # report what would happen, change nothing
 *
 * Meant for cron. Once a day is plenty; the interval is enforced inside the
 * scheduler, so a more frequent entry is harmless:
 *
 *   17 3 * * *  cd /var/www/html && php bin/click-update.php >> data/updates/cron.log 2>&1
 *
 * ## Why this is a CLI command and not something the web request does
 *
 * Installing a release replaces `src/` underneath the running process. In a web
 * request that is genuinely dangerous: PHP loads class files lazily, so a swap
 * mid-request can leave a half-served page unable to autoload the class it needs
 * next, and an opcode cache can then hold a mix of both releases. A CLI run has
 * no page in flight and exits immediately afterwards, so the next request starts
 * cleanly on the new code.
 *
 * That is the whole reason there is no "update on request tail" mode. WordPress
 * can do it because it loads nearly everything up front; this does not, and
 * pretending otherwise would trade a rare visible failure for a rare invisible
 * one.
 *
 * Exit codes: 0 nothing to do or update installed, 1 something failed. Cron
 * mails on non-zero, which is the notification.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Update\ReleaseFeed;
use Click\Cms\Application\Update\UpdateInstaller;
use Click\Cms\Application\Update\UpdateScheduler;
use Click\Cms\Application\Update\UpdateService;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Update\UpdatePolicy;

$argvFlags = array_slice($argv, 1);
$force = in_array('--force', $argvFlags, true);
$dryRun = in_array('--dry-run', $argvFlags, true);

$config = CoreConfig::load($root . '/config/core.json');
$policy = $config->updatePolicy();
$feedUrl = $config->updateFeedUrl();
$publicKey = $config->updatePublicKey();

$say = static fn (string $line): int => (int) fwrite(STDOUT, date('c') . '  ' . $line . "\n");
$fail = static function (string $line): never {
    fwrite(STDERR, date('c') . '  ' . $line . "\n");
    exit(1);
};

if (!$policy->checksForUpdates()) {
    $say('Update policy is "manual"; nothing to do.');
    exit(0);
}
if ($feedUrl === '' || $publicKey === '') {
    // Not an error: an installation that never configured a feed is simply not
    // using this. Saying so beats failing obscurely every night in a cron mail.
    $say('No update feed or signing key configured; nothing to do.');
    exit(0);
}

$scheduler = new UpdateScheduler($root . '/data/updates');
if (!$force && !$scheduler->isDue(time())) {
    exit(0); // silent: this is the ordinary case on a frequent cron
}

$service = new UpdateService(
    $root,
    new ReleaseFeed(),
    new UpdateInstaller($root),
    Application::VERSION,
);

// Everything below runs under the lock, so two overlapping cron runs cannot both
// install. A run that finds the lock held simply ends.
$outcome = $scheduler->withLock(static function () use ($service, $scheduler, $feedUrl, $publicKey, $policy, $config, $dryRun, $say) {
    $allowPre = $config->updateAllowPreRelease();

    $decision = $service->check($feedUrl, $publicKey, $policy, $allowPre);

    // A dry run deliberately does not record the check. Previewing what would
    // happen must not consume the interval and leave the next real run silently
    // skipping — which is exactly the surprise an operator would hit by looking
    // before leaping.
    if (!$dryRun) {
        $scheduler->markChecked(time());
    }

    $feedError = method_exists($service, 'lastFeedError') ? $service->lastFeedError() : null;
    if (is_string($feedError) && $feedError !== '') {
        // Surfaced rather than swallowed: a wrong key looks exactly like "up to
        // date" from the outside, and staying quiet about it would leave a site
        // convinced it was current forever.
        return ['status' => 'error', 'message' => 'Update feed problem: ' . $feedError];
    }

    if (!$decision->hasUpdate()) {
        return ['status' => 'ok', 'message' => 'Up to date (' . Application::VERSION . ').'];
    }

    $version = $decision->release?->version->toString() ?? '?';

    if ($dryRun) {
        return ['status' => 'ok', 'message' => sprintf(
            'Available: %s (%s%s). Policy "%s" would %s install it.',
            $version,
            $decision->step->value,
            $decision->release?->security ? ', security' : '',
            $policy->value,
            $decision->automatic ? '' : 'NOT '
        )];
    }

    if (!$decision->automatic) {
        return ['status' => 'ok', 'message' => sprintf(
            'Update %s is available but policy "%s" leaves it for an administrator.',
            $version,
            $policy->value
        )];
    }

    $say('Installing ' . $version . '…');
    // applyIfAutomatic, not applyApproved: it re-applies the policy itself, so
    // a feed that changed between the check above and this call cannot slip an
    // install past the rule that permitted the first one. There is no
    // administrator here to catch that.
    $result = $service->applyIfAutomatic($feedUrl, $publicKey, $policy, $allowPre);

    if (($result['success'] ?? false) !== true) {
        return ['status' => 'error', 'message' => 'Update to ' . $version . ' failed: '
            . ($result['error'] ?? 'unknown error') . ' (the site was left as it was)'];
    }

    return ['status' => 'ok', 'message' => 'Installed ' . $version
        . '. Backup: ' . ($result['backup'] ?? 'none')];
});

if ($outcome === null) {
    $say('Another update run holds the lock; skipping.');
    exit(0);
}

if (($outcome['status'] ?? '') === 'error') {
    $fail((string) $outcome['message']);
}

$say((string) $outcome['message']);
exit(0);
