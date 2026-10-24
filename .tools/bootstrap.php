<?php

/**
 * PHPUnit bootstrap: boots REDAXO with all packages, as in ycom and yform.
 *
 * Expects the addon inside a REDAXO installation (redaxo/src/addons/ai_platform),
 * locally as in CI, where the workflow installs a fresh REDAXO around it.
 */

declare(strict_types=1);

$htdocs = dirname(__DIR__, 5);

unset($REX);
$REX['REDAXO'] = true;
$REX['HTDOCS_PATH'] = $htdocs . '/';
$REX['BACKEND_FOLDER'] = 'redaxo';
$REX['LOAD_PAGE'] = false;

require $htdocs . '/redaxo/src/core/boot.php';
require $htdocs . '/redaxo/src/core/packages.php';

// use original error handlers of the tools
rex_error_handler::unregister();
