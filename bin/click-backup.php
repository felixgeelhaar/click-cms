#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Take a backup of the whole site, or put one back.
 *
 *   php bin/click-backup.php                     # back up if the interval says it is due
 *   php bin/click-backup.php --force             # back up now, whatever the interval says
 *   php bin/click-backup.php --dry-run           # report what would happen, change nothing
 *   php bin/click-backup.php --list              # what archives exist
 *   php bin/click-backup.php --restore=NAME      # restore, never overwriting
 *   php bin/click-backup.php --restore=NAME --overwrite
 *
 * Meant for cron. Once a night is the usual thing; the interval is enforced
 * inside the scheduler, so a more frequent entry is harmless:
 *
 *   23 3 * * *  cd /var/www/html && php bin/click-backup.php >> data/backups/cron.log 2>&1
 *
 * ## What is in a backup
 *
 * Every document of every type, read through the storage port rather than off
 * the disk — so a site on SQLite, MySQL, MariaDB or Postgres backs up its
 * content, which the previous implementation did not. Plus the media library.
 * The archive is backend-independent: one taken from SQLite restores onto flat
 * files, which is the point.
 *
 * ## Why this is a CLI command
 *
 * Same reason the updater is. A backup walks every document and every uploaded
 * file; on a site of any size that is minutes of work and hundreds of megabytes
 * of I/O, which is not a thing to do inside a web request that a browser or a
 * proxy will time out halfway through. The on-demand download in the admin is
 * the exception and is bounded by an administrator waiting for it.
 *
 * Exit codes: 0 nothing to do or backup taken, 1 something failed. Cron mails on
 * non-zero, which is the notification.
 */

// A command-line tool. Served over the web this would be an unauthenticated
// endpoint that reads every draft and every password hash on the site, so it
// refuses to run under a web SAPI at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Click\Cms\Application\Backup\BackupException;
use Click\Cms\Application\Backup\BackupRestorer;
use Click\Cms\Application\Backup\BackupScheduler;
use Click\Cms\Application\Backup\BackupService;
use Click\Cms\Application\Backup\BackupStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Core\Application;
use Click\Cms\Infrastructure\Storage\StorageFactory;

/**
 * Which site this run acts on.
 *
 * A command line has no hostname, so it has to be told. `CLICK_CMS_SITE` covers
 * a cron entry that always means the same site; `--site=` covers the rest. An
 * installation that has declared no sites ignores both and behaves as it always
 * has.
 */
$siteOption = null;
foreach ($_SERVER['argv'] as $argument) {
    if (str_starts_with($argument, '--site=')) {
        $siteOption = substr($argument, 7);
    }
}

$argv = $_SERVER['argv'];
array_shift($argv);

$force = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
$overwrite = in_array('--overwrite', $argv, true);
$list = in_array('--list', $argv, true);

$restore = null;
$keepOverride = null;
$unknown = [];

foreach ($argv as $arg) {
    if (in_array($arg, ['--force', '--dry-run', '--overwrite', '--list'], true)) {
        continue;
    }
    if (str_starts_with($arg, '--restore=')) {
        $restore = substr($arg, strlen('--restore='));
        continue;
    }
    if (str_starts_with($arg, '--keep=') && is_numeric(substr($arg, strlen('--keep=')))) {
        $keepOverride = (int) substr($arg, strlen('--keep='));
        continue;
    }
    $unknown[] = $arg;
}

$say = static fn (string $line): int => (int) fwrite(STDOUT, $line . "\n");
$fail = static function (string $line): never {
    fwrite(STDERR, $line . "\n");
    exit(1);
};

// A refused archive, an unwritable directory, a truncated download: all of them
// reach here as an exception carrying a sentence written for whoever is reading
// the cron mail. A stack trace would bury it, so the message is the output and
// the exit code is the signal.
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, ($e instanceof BackupException ? '' : get_class($e) . ': ') . $e->getMessage() . "\n");
    exit(1);
});

if ($unknown !== []) {
    fwrite(STDERR, "Unknown option: {$unknown[0]}\n");
    fwrite(STDERR, "Usage: php bin/click-backup.php [--force] [--dry-run] [--list] [--keep=N]\n");
    fwrite(STDERR, "       php bin/click-backup.php --restore=<archive|path> [--overwrite] [--dry-run]\n");
    exit(1);
}

$config = CoreConfig::load($root . '/config/core.json');
$store = new BackupStore($root . '/data/backups');
$keep = $keepOverride !== null ? max(1, $keepOverride) : $config->backupKeep();

/* ------------------------------------------------------------------- list -- */

if ($list) {
    $rows = $store->listing();
    if ($rows === []) {
        $say('No backups yet.');
        exit(0);
    }
    foreach ($rows as $row) {
        $say(sprintf(
            '%-32s %10s  %s',
            (string) $row['name'],
            number_format((int) $row['bytes'] / 1024, 0, '.', ',') . ' KB',
            $row['readable']
                ? sprintf(
                    '%d documents, %d media (%s, from %s)',
                    (int) $row['documents'],
                    (int) $row['media'],
                    (string) $row['mediaStorage'],
                    (string) $row['sourceBackend']
                )
                : 'UNREADABLE — its manifest will not parse'
        ));
    }
    exit(0);
}

$scheduler = new BackupScheduler(
    $root . '/data/backups',
    $config->backupIntervalHours() * 3600
);

/* ---------------------------------------------------------------- restore -- */

if ($restore !== null) {
    // A name this store issued, or a path to an archive from somewhere else —
    // the second is how an archive taken off one installation is restored onto
    // another, which is most of the reason to have backups at all.
    $archivePath = $store->pathFor($restore) ?? (is_file($restore) ? $restore : null);
    if ($archivePath === null) {
        $fail('There is no backup called "' . $restore . '", and no file at that path.');
    }

    // Booted rather than wired by hand: a restore writes through the same
    // ContentService the admin does, so the version chain, the audit trail and
    // the render cache all see it — and the site's collection types are
    // registered, which is what makes a restored collection entry get published
    // rather than left as a draft.
    $app = new Application($root, $siteOption);
    $app->boot();

    $content = $app->getContentService();
    $mediaDir = $root . '/content/media';

    $restorer = new BackupRestorer($content, $mediaDir, $store->pool());

    // The same lock a backup takes, so retention cannot delete a pool entry out
    // from under a restore that is reading it.
    $outcome = $scheduler->withLock(static function () use ($restorer, $archivePath, $overwrite, $dryRun, $store, $say, $content, $mediaDir) {
        if ($dryRun) {
            // The archive is verified in full — the same pass a real restore
            // runs first — and then each item is compared against what is here,
            // so the preview is the actual decision rather than a description of
            // one. Nothing is written.
            $manifest = (new \Click\Cms\Application\Backup\BackupVerifier($store->pool()))->verify($archivePath);

            $say(sprintf(
                'Archive verified: %d documents, %d media files, taken %s from the "%s" backend.',
                $manifest->documentCount(),
                $manifest->mediaCount(),
                $manifest->createdAt,
                $manifest->sourceBackend
            ));

            foreach ($manifest->documents as $document) {
                $key = \Click\Cms\Domain\ValueObjects\ContentKey::fromString($document['key']);
                $present = $content->exists($key) || $content->draft($key) !== null;
                $label = $document['type'] . '/' . $document['locale'] . '/' . $document['slug'];
                $say(sprintf(
                    '  %s  %s',
                    $present && !$overwrite ? 'would skip   ' : 'would restore',
                    $label
                ));
            }
            foreach ($manifest->media as $item) {
                $present = is_file($mediaDir . '/' . $item['path']);
                $say(sprintf(
                    '  %s  media %s',
                    $present && !$overwrite ? 'would skip   ' : 'would restore',
                    $item['path']
                ));
            }
            foreach ($manifest->skippedMedia as $skipped) {
                $say(sprintf('  NOT IN BACKUP  %s (%d bytes, %s)', $skipped['path'], $skipped['bytes'], $skipped['reason']));
            }
            $say('Nothing was written. Run without --dry-run to restore.');

            return ['report' => null];
        }

        return ['report' => $restorer->restore($archivePath, $overwrite)];
    });

    if ($outcome === null) {
        $fail('Another backup or restore holds the lock; nothing was done.');
    }

    $report = $outcome['report'];
    if ($report === null) {
        exit(0);
    }

    foreach ($report->restoredItems() as $item) {
        $say("restored  {$item}");
    }
    foreach ($report->skippedItems() as $item) {
        $say("exists    {$item}");
    }
    foreach ($report->failureMessages() as $message) {
        fwrite(STDERR, "FAILED    {$message}\n");
    }

    $say('');
    if ($report->wasNoOp()) {
        $say('Nothing to restore — everything in that backup is already here.');
    } else {
        printf("Restored %d item(s); %d were already present and were left alone.\n",
            count($report->restoredItems()),
            count($report->skippedItems())
        );
    }

    if ($report->hasFailures()) {
        fwrite(STDERR, sprintf("\n%d item(s) could not be restored.\n", count($report->failureMessages())));
        exit(1);
    }

    exit(0);
}

/* ----------------------------------------------------------------- backup -- */

if (!$config->backupEnabled() && !$force) {
    // Not an error: a site that has not switched scheduled backups on is not
    // failing at anything. Saying so beats a silent exit that leaves an operator
    // wondering why cron produces nothing.
    $say('Scheduled backups are off (core.backup.enabled is false). Use --force to take one now.');
    exit(0);
}

if (!$force && !$scheduler->isDue(time())) {
    exit(0); // silent: this is the ordinary case on a frequent cron
}

$service = new BackupService(
    $store,
    StorageFactory::create($config, $root),
    $root . '/content/media',
    $config->storageBackend(),
    $config->backupIncludeMedia(),
    $config->backupMaxMediaBytes(),
);

$outcome = $scheduler->withLock(static function () use ($service, $scheduler, $store, $keep, $dryRun, $config, $say) {
    if ($dryRun) {
        // A dry run deliberately does not record the run. Previewing what would
        // happen must not consume the interval and leave tonight's real backup
        // silently skipping — which is exactly the surprise an operator would hit
        // by looking before leaping. That bug was found and fixed in the updater;
        // it is not being reintroduced here.
        $plan = $store->retentionPlan($keep);

        $say(sprintf(
            'Would take a backup into %s (keeping %d, media %s).',
            $store->directory(),
            $keep,
            $config->backupIncludeMedia() ? 'included' : 'excluded'
        ));
        foreach ($plan->archivesToDelete() as $name) {
            $say('  would delete  ' . $name);
        }
        if ($plan->poolPruningRefused()) {
            $say('  the media pool would NOT be pruned: an archive that is being kept has an unreadable manifest.');
        } else {
            foreach ($plan->poolEntriesToDelete() as $reference) {
                $say('  would free    ' . $reference);
            }
        }
        $say('Nothing was written, and the schedule was not advanced.');

        return ['status' => 'ok', 'message' => null];
    }

    $result = $service->takeBackup($keep);
    $scheduler->markRun(time());

    $manifest = $result['manifest'];
    $lines = [sprintf(
        'Backed up %d documents and %d media files to %s (source backend: %s).',
        $manifest->documentCount(),
        $manifest->mediaCount(),
        $result['name'],
        $manifest->sourceBackend
    )];

    foreach ($manifest->skippedMedia as $skipped) {
        // Loud, on stdout, every run. A file the backup does not hold is the one
        // thing an operator must not learn about for the first time while
        // restoring.
        $lines[] = sprintf('  SKIPPED  %s (%d bytes, %s)', $skipped['path'], $skipped['bytes'], $skipped['reason']);
    }

    foreach ($result['pruned']['archives'] as $name) {
        $lines[] = '  deleted  ' . $name;
    }
    if ($result['pruned']['refused']) {
        $lines[] = '  the media pool was not pruned: an archive being kept has an unreadable manifest.';
    } elseif ($result['pruned']['poolEntries'] !== []) {
        $lines[] = sprintf('  freed %d unreferenced pool entries.', count($result['pruned']['poolEntries']));
    }

    return ['status' => 'ok', 'message' => implode("\n", $lines)];
});

if ($outcome === null) {
    $say('Another backup run holds the lock; skipping.');
    exit(0);
}

if (is_string($outcome['message'] ?? null)) {
    $say($outcome['message']);
}

exit(0);
