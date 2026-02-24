<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: php build-registry-from-manifests.php <manifests_dir> <private_key.pem> <output_registry.json>\n");
    exit(1);
}

$manifestsDir = rtrim($argv[1], '/');
$privateKeyPath = $argv[2];
$outputPath = $argv[3];

if (!is_dir($manifestsDir)) {
    fwrite(STDERR, "Manifests directory not found: {$manifestsDir}\n");
    exit(1);
}

if (!file_exists($privateKeyPath)) {
    fwrite(STDERR, "Private key not found: {$privateKeyPath}\n");
    exit(1);
}

$privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));
if ($privateKey === false) {
    fwrite(STDERR, "Invalid private key\n");
    exit(1);
}

$entries = [];
$files = glob($manifestsDir . '/*.json');

foreach ($files as $manifestPath) {
    $manifestRaw = file_get_contents($manifestPath);
    if ($manifestRaw === false) {
        fwrite(STDERR, "Failed to read manifest: {$manifestPath}\n");
        exit(1);
    }

    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest)) {
        fwrite(STDERR, "Invalid manifest JSON: {$manifestPath}\n");
        exit(1);
    }

    $manifestId = $manifest['id'] ?? null;
    $manifestUrl = $manifest['manifestUrl'] ?? null;

    if ($manifestId === null || $manifestUrl === null) {
        fwrite(STDERR, "Manifest must include 'id' and 'manifestUrl': {$manifestPath}\n");
        exit(1);
    }

    $signature = '';
    $ok = openssl_sign($manifestRaw, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    if ($ok !== true) {
        fwrite(STDERR, "Failed to sign manifest: {$manifestPath}\n");
        exit(1);
    }

    $entries[] = [
        'id' => $manifestId,
        'manifestUrl' => $manifestUrl,
        'signature' => base64_encode($signature),
    ];
}

$registry = [
    'plugins' => $entries,
];

if (file_put_contents($outputPath, json_encode($registry, JSON_PRETTY_PRINT)) === false) {
    fwrite(STDERR, "Failed to write registry to: {$outputPath}\n");
    exit(1);
}

echo "Registry written to {$outputPath}\n";
