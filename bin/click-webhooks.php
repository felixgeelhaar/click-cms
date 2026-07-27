#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Send the webhook deliveries that are waiting.
 *
 *   php bin/click-webhooks.php             # send what is due
 *   php bin/click-webhooks.php --list      # the recent delivery log
 *   php bin/click-webhooks.php --prune     # discard finished rows older than the retention
 *   php bin/click-webhooks.php --limit=25  # take fewer in one run
 *
 * Meant for cron, and meant to run often — the queue is only as fresh as the
 * interval between sweeps. Written out rather than with a step value, because a
 * slash-star cannot appear inside this comment:
 *
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * *  cd /var/www/html && php bin/click-webhooks.php >> data/webhooks/cron.log 2>&1
 *
 * ## Why the sending is here and not in the request that caused it
 *
 * Three reasons, in increasing severity: it would put a remote host's latency on
 * the editor's Save button; a receiver that hangs would hold a PHP worker until
 * the timeout, and shared hosting has few workers; and it could not retry, so a
 * receiver that happened to be restarting would never hear about the change —
 * which makes the mechanism unreliable exactly when reliability is the point.
 *
 * Exit codes: 0 swept cleanly, 1 something needs a person. A failed attempt is
 * not trouble — it will be retried — but a delivery discarded because its
 * endpoint vanished is, since that is work thrown away.
 */

// A command-line tool. Served over the web this would be an unauthenticated
// endpoint that makes outbound requests on demand, so it refuses to run under a
// web SAPI at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Click\Cms\Application\Webhook\WebhookSender;
use Click\Cms\Domain\Webhook\RetryPolicy;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use Click\Cms\Infrastructure\Webhook\FileDeliveryQueue;
use Click\Cms\Infrastructure\Webhook\FileEndpointRepository;
use Click\Cms\Infrastructure\Webhook\TransportFactory;

$argv = $_SERVER['argv'];
array_shift($argv);

$list = in_array('--list', $argv, true);
$prune = in_array('--prune', $argv, true);
$quiet = in_array('--quiet', $argv, true);

$limit = WebhookSender::DEFAULT_LIMIT;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
        $limit = max(1, (int) $m[1]);
    }
}

/** How long a finished delivery is kept before `--prune` discards it. */
const RETENTION_SECONDS = 7 * 24 * 3600;

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "Z] {$line}\n");
    }
};

$directory = $root . '/data/webhooks';
$queue = new FileDeliveryQueue($directory . '/queue');
$endpoints = new FileEndpointRepository($directory);

/* ------------------------------------------------------------------ list -- */

if ($list) {
    $recent = $queue->recent(50);

    if ($recent === []) {
        $say('No deliveries recorded.');
        exit(0);
    }

    foreach ($recent as $delivery) {
        $detail = $delivery->lastError !== null ? " — {$delivery->lastError}" : '';
        $say(sprintf(
            '%s %s -> %s (attempt %d)%s',
            str_pad($delivery->status, 9),
            $delivery->event,
            $delivery->endpointId,
            $delivery->attempts,
            $detail,
        ));
    }

    exit(0);
}

/* ----------------------------------------------------------------- prune -- */

if ($prune) {
    $removed = $queue->prune(time() - RETENTION_SECONDS);
    $say("Discarded {$removed} finished deliveries.");
    exit(0);
}

/* ----------------------------------------------------------------- sweep -- */

try {
    $transport = TransportFactory::create();
} catch (Throwable $e) {
    // Loud, not silent. A webhooks plugin that quietly delivered nothing would
    // be the worst outcome available: the queue fills, the admin shows pending
    // deliveries, and nothing ever says why none of them move.
    fwrite(STDERR, "click-webhooks: {$e->getMessage()}\n");
    exit(1);
}

$sender = new WebhookSender($queue, $endpoints, $transport, RetryPolicy::standard());

try {
    $report = $sender->sweep(time(), $limit);
} catch (Throwable $e) {
    fwrite(STDERR, "click-webhooks: the sweep failed: {$e->getMessage()}\n");
    exit(1);
}

if ($report->total() === 0) {
    $say('Nothing was due.');
    exit(0);
}

foreach ($report->outcomes as $outcome) {
    $line = sprintf(
        '%s %s (%s)%s',
        $outcome['result'],
        $outcome['event'] ?? '?',
        $outcome['id'] ?? '?',
        isset($outcome['reason']) ? ' — ' . $outcome['reason'] : '',
    );

    // Discarded work goes to stderr so cron mails it. A retryable failure does
    // not: a receiver restarting is ordinary, and mailing every one of those
    // trains people to filter the mail that also carries the real problems.
    if (($outcome['result'] ?? '') === 'orphaned' || ($outcome['givingUp'] ?? false) === true) {
        fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . "Z] {$line}\n");
        continue;
    }

    $say($line);
}

$say(sprintf(
    'Swept %d: %d delivered, %d failed, %d dropped.',
    $report->total(),
    $report->deliveredCount(),
    $report->failedCount(),
    $report->orphanedCount(),
));

exit($report->hasTrouble() ? 1 : 0);
