#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build and sign the update feed.
 *
 *   php scripts/updates/build-feed.php <releases.json> <private_key.pem> <out_dir> [--sequence=N] [--ttl-days=N]
 *
 * `releases.json` is the list of published releases — the input an operator or
 * CI maintains — shaped as:
 *
 *   [ { "version": "1.2.0", "packageUrl": "https://…/click-cms-1.2.0.zip",
 *       "sha256": "…", "security": false, "notes": "…", "requiresPhp": "8.1" } ]
 *
 * It writes `feed.json` and the detached `feed.json.sig` beside it.
 *
 * ## Why the sequence and the expiry are not optional
 *
 * A signature says who wrote a document; it says nothing about whether it is the
 * current one. Without these two fields an attacker who can decide which signed
 * document a site fetches can either replay an old feed (pinning the site to a
 * release with a public vulnerability) or keep serving a stale one (so the site
 * never hears about a security release and reports itself up to date). The CMS
 * refuses a feed lacking either, so this tool always writes both.
 *
 * The expiry has an operational consequence worth stating plainly: **the feed
 * must be re-signed before it expires, even when there is no new release.** A
 * project that publishes twice a year and signs a 30-day feed will have every
 * site reporting a feed error for ten months. That is what the scheduled
 * re-sign in .github/workflows/update-feed.yml exists to prevent.
 */

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/updates/build-feed.php <releases.json> <private_key.pem> <out_dir> [--sequence=N] [--ttl-days=N]\n");
    exit(1);
}

$releasesPath = $argv[1];
$keyPath = $argv[2];
$outDir = rtrim($argv[3], '/');

$options = getopt('', ['sequence::', 'ttl-days::']);
$ttlDays = isset($options['ttl-days']) ? max(1, (int) $options['ttl-days']) : 30;

foreach ([$releasesPath => 'Releases file', $keyPath => 'Private key'] as $path => $what) {
    if (!is_file($path)) {
        fwrite(STDERR, "$what not found: $path\n");
        exit(1);
    }
}
if (!is_dir($outDir) && !mkdir($outDir, 0o755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Could not create output directory: $outDir\n");
    exit(1);
}

$releases = json_decode((string) file_get_contents($releasesPath), true);
if (!is_array($releases)) {
    fwrite(STDERR, "Releases file is not valid JSON: $releasesPath\n");
    exit(1);
}

/*
 * The sequence must never go backwards, or every site that saw the higher one
 * will refuse everything after it — the rollback defence turning into a
 * self-inflicted outage. Taken from --sequence when given (CI passes the run
 * number, which is monotonic), otherwise one past whatever the previous feed
 * published, otherwise 1.
 */
$sequence = isset($options['sequence']) ? (int) $options['sequence'] : null;
if ($sequence === null) {
    $previous = json_decode((string) @file_get_contents("$outDir/feed.json"), true);
    $sequence = is_array($previous) && is_int($previous['sequence'] ?? null)
        ? $previous['sequence'] + 1
        : 1;
}

// Validated here rather than discovered by every site that fetches it: a feed
// with a broken entry is a release nobody can install and nobody was told about.
$problems = [];
foreach ($releases as $i => $release) {
    if (!is_array($release)) {
        $problems[] = "entry $i is not an object";
        continue;
    }
    if (preg_match('/^v?\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', (string) ($release['version'] ?? '')) !== 1) {
        $problems[] = "entry $i has no usable version";
    }
    if (preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($release['sha256'] ?? ''))) !== 1) {
        $problems[] = "entry {$i} ({$release['version']}) has no sha256";
    }
    $scheme = strtolower((string) parse_url((string) ($release['packageUrl'] ?? ''), PHP_URL_SCHEME));
    $host = strtolower((string) parse_url((string) ($release['packageUrl'] ?? ''), PHP_URL_HOST));
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true))) {
        $problems[] = "entry {$i} ({$release['version']}) must have an https packageUrl";
    }
}
if ($problems !== []) {
    fwrite(STDERR, "Refusing to sign a feed with problems:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

$expiresAt = time() + ($ttlDays * 86400);
$feed = [
    'sequence' => $sequence,
    'expires' => gmdate('c', $expiresAt),
    'releases' => array_values($releases),
];

// Compact, stable bytes: the signature covers exactly what is written, so the
// file must not be reformatted afterwards by anything.
$body = (string) json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

$key = openssl_pkey_get_private((string) file_get_contents($keyPath));
if ($key === false) {
    fwrite(STDERR, "Could not read the private key: $keyPath\n");
    exit(1);
}
if (!openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, "Signing failed.\n");
    exit(1);
}

file_put_contents("$outDir/feed.json", $body);
file_put_contents("$outDir/feed.json.sig", base64_encode($signature));

printf(
    "Wrote %s/feed.json — sequence %d, %d release(s), expires %s (%d days)\n",
    $outDir,
    $sequence,
    count($releases),
    gmdate('c', $expiresAt),
    $ttlDays
);
