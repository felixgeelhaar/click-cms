<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php build-registry.php <entry.json> [entry.json ...] > registry.json\n");
    exit(1);
}

$entries = [];

foreach (array_slice($argv, 1) as $entryPath) {
    if (!file_exists($entryPath)) {
        fwrite(STDERR, "Entry file not found: {$entryPath}\n");
        exit(1);
    }

    $raw = file_get_contents($entryPath);
    if ($raw === false) {
        fwrite(STDERR, "Failed to read entry file: {$entryPath}\n");
        exit(1);
    }

    $entry = json_decode($raw, true);
    if (!is_array($entry) || !isset($entry['manifestUrl'], $entry['signature'])) {
        fwrite(STDERR, "Invalid entry JSON: {$entryPath}\n");
        exit(1);
    }

    $entries[] = $entry;
}

$registry = [
    'plugins' => $entries,
];

echo json_encode($registry, JSON_PRETTY_PRINT) . "\n";
