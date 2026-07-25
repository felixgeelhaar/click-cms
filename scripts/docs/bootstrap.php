<?php

declare(strict_types=1);

/**
 * Loads the documentation site generator.
 *
 * These classes live outside `src/`, so Composer's PSR-4 autoloader does not
 * know about them — deliberately, because this is build-time tooling and has no
 * business being loadable by the running CMS. The entry point and the tests both
 * require this file.
 */

require_once __DIR__ . '/DocumentDefect.php';
require_once __DIR__ . '/RenderedDocument.php';
require_once __DIR__ . '/Slugger.php';
require_once __DIR__ . '/LinkRewriter.php';
require_once __DIR__ . '/ImageAsset.php';
require_once __DIR__ . '/ImageDimensions.php';
require_once __DIR__ . '/ImageLibrary.php';
require_once __DIR__ . '/MarkdownRenderer.php';
require_once __DIR__ . '/Navigation.php';
require_once __DIR__ . '/Page.php';
require_once __DIR__ . '/SiteBuilder.php';
