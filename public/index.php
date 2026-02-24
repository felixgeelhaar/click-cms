<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Click\Cms\Core\Application;

$app = new Application(__DIR__ . '/..');
$app->run();
