<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php sign-manifest.php <manifest.json> <private_key.pem> [manifest_url]\n");
    exit(1);
}

$manifestPath = $argv[1];
$privateKeyPath = $argv[2];
$manifestUrl = $argv[3] ?? null;

if (!file_exists($manifestPath)) {
    fwrite(STDERR, "Manifest file not found: {$manifestPath}\n");
    exit(1);
}

if (!file_exists($privateKeyPath)) {
    fwrite(STDERR, "Private key not found: {$privateKeyPath}\n");
    exit(1);
}

$manifestRaw = file_get_contents($manifestPath);
if ($manifestRaw === false) {
    fwrite(STDERR, "Failed to read manifest file\n");
    exit(1);
}

$manifest = json_decode($manifestRaw, true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Invalid manifest JSON\n");
    exit(1);
}

$privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));
if ($privateKey === false) {
    fwrite(STDERR, "Invalid private key\n");
    exit(1);
}

$signature = '';
$ok = openssl_sign($manifestRaw, $signature, $privateKey, OPENSSL_ALGO_SHA256);

if ($ok !== true) {
    fwrite(STDERR, "Failed to sign manifest\n");
    exit(1);
}

$signatureB64 = base64_encode($signature);

echo "Signature (base64):\n{$signatureB64}\n\n";

if ($manifestUrl !== null) {
    $registryEntry = [
        'id' => $manifest['id'] ?? null,
        'manifestUrl' => $manifestUrl,
        'signature' => $signatureB64,
    ];

    echo "Registry entry:\n";
    echo json_encode($registryEntry, JSON_PRETTY_PRINT) . "\n";
}
