#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Publish and unpublish whatever has come due.
 *
 *   php bin/click-schedule.php            # do it
 *   php bin/click-schedule.php --dry-run  # report what would happen, change nothing
 *   php bin/click-schedule.php --list     # everything pending, due or not
 *
 * Meant for cron, and meant to run often — a schedule is only as precise as the
 * interval between sweeps, so every five minutes is a reasonable default and
 * every minute is not unreasonable. Written out rather than with a step value,
 * because a slash-star cannot appear inside this comment:
 *
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * *  cd /var/www/html && php bin/click-schedule.php >> data/schedule/cron.log 2>&1
 *
 * ## Why a sweep and not a check on each request
 *
 * Two reasons, both in `SchedulingService`'s docblock at length. Briefly: a page
 * nobody visits would never publish, which is exactly the case scheduling exists
 * for; and it would put a write on the public read path, which is one file read
 * on shared hosting by design.
 *
 * A site that never installs the cron entry gets a scheduling feature that
 * visibly does nothing, rather than one that half works — which is why `--list`
 * exists and why the admin says when a sweep last ran.
 *
 * Exit codes: 0 swept cleanly (including "nothing to do"), 1 something needs a
 * person. Cron mails on non-zero, which is the notification.
 */

// A command-line tool. Served over the web this would be an unauthenticated
// endpoint that publishes content, so it refuses to run under a web SAPI at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Click\Cms\Application\Publishing\SchedulingService;
use Click\Cms\Application\Publishing\SweepOutcome;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Publishing\ScheduledDocument;

$argv = $_SERVER['argv'];
array_shift($argv);

$dryRun = in_array('--dry-run', $argv, true);
$list = in_array('--list', $argv, true);
$quiet = in_array('--quiet', $argv, true);

$stamp = static fn (): string => gmdate('Y-m-d H:i:s') . 'Z';
$say = static function (string $line) use ($quiet, $stamp): void {
    if (!$quiet) {
        fwrite(STDOUT, "[{$stamp()}] {$line}\n");
    }
};

try {
    $app = new Application($root);
    $app->boot();
} catch (Throwable $e) {
    fwrite(STDERR, "click-schedule: the application could not start: {$e->getMessage()}\n");
    exit(1);
}

$schedules = $app->getScheduleStore();

/* ------------------------------------------------------------------ list -- */

if ($list) {
    $pending = $schedules->all();

    if ($pending === []) {
        $say('Nothing is scheduled.');
        exit(0);
    }

    usort($pending, static function (ScheduledDocument $a, ScheduledDocument $b): int {
        return ($a->schedule->nextDueAt() <=> $b->schedule->nextDueAt());
    });

    foreach ($pending as $item) {
        $parts = [];
        if ($item->schedule->publishAt !== null) {
            $parts[] = 'publish ' . $item->schedule->publishAt->format(DATE_ATOM);
        }
        if ($item->schedule->unpublishAt !== null) {
            $parts[] = 'unpublish ' . $item->schedule->unpublishAt->format(DATE_ATOM);
        }

        $by = $item->scheduledBy === null ? '' : " (set by {$item->scheduledBy})";
        $say($item->key->toString() . ': ' . implode(', ', $parts) . $by);
    }

    exit(0);
}

/* --------------------------------------------------------------- dry run -- */

if ($dryRun) {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $due = $schedules->due($now);

    if ($due === []) {
        $say('Nothing is due.');
        exit(0);
    }

    foreach ($due as $item) {
        $action = $item->schedule->actionDueAt($now);
        $say("would {$action?->value} {$item->key->toString()}");
    }

    exit(0);
}

/* ----------------------------------------------------------------- sweep -- */

$service = new SchedulingService(
    $schedules,
    $app->getPublishingStorage(),
    // The publish gate, asked at the moment of publication rather than when the
    // schedule was set — a review's answer is about the page as it stands, and
    // the page will have changed since. A gate a cron job walks past is not a
    // gate.
    static fn ($key, array $user): ?string => \Click\Cms\Application\Plugin\PublishGate::ambient()
        ->refusalFor($key, $user),
    // Attribution: the write is recorded against whoever set the schedule, not
    // against nobody. See `Application::runAs()`.
    static fn (?string $username, callable $work): mixed => $app->runAs($username, $work),
);

try {
    $report = $service->sweep();
} catch (Throwable $e) {
    fwrite(STDERR, "click-schedule: the sweep failed: {$e->getMessage()}\n");
    exit(1);
}

if ($report->total() === 0) {
    $say('Nothing was due.');
    exit(0);
}

foreach ($report->outcomes as $outcome) {
    $line = $outcome->describe();

    // Trouble goes to stderr so cron mails it, and so a log reader can find the
    // failures without reading every success.
    if ($outcome->result === SweepOutcome::FAILED || $outcome->result === SweepOutcome::MISSING) {
        fwrite(STDERR, "[{$stamp()}] {$line}\n");
        continue;
    }

    $say($line);
}

$say(sprintf(
    'Swept %d: %d published, %d unpublished, %d refused, %d dropped, %d failed.',
    $report->total(),
    $report->publishedCount(),
    $report->unpublishedCount(),
    $report->refusedCount(),
    $report->missingCount(),
    $report->failedCount(),
));

exit($report->hasTrouble() ? 1 : 0);
