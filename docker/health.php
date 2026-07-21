<?php
// Liveness/readiness probe. Boots nothing: it answers whether the container can
// serve PHP and whether the writable paths the CMS needs are actually writable,
// which is the failure that matters in a container with mounted volumes.
declare(strict_types=1);

header('Content-Type: application/json');

$paths = ['/var/www/html/content', '/var/www/html/data'];
$problems = [];

foreach ($paths as $path) {
    if (!is_dir($path)) {
        $problems[] = "missing: {$path}";
    } elseif (!is_writable($path)) {
        $problems[] = "not writable: {$path}";
    }
}

if ($problems !== []) {
    http_response_code(503);
    echo json_encode(['status' => 'unhealthy', 'problems' => $problems]);
    return;
}

echo json_encode(['status' => 'ok', 'php' => PHP_VERSION]);
