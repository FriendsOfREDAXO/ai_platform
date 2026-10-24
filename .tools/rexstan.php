<?php

/**
 * Writes the rexstan user config for CI (see .github/workflows/rexstan.yml).
 *
 * Run from the REDAXO root with ADDON_KEY set. It overwrites rexstan's
 * user-config.neon, so do not run it against an installation whose rexstan
 * settings you want to keep.
 */

unset($REX);
$REX['REDAXO'] = true;
$REX['HTDOCS_PATH'] = './';
$REX['BACKEND_FOLDER'] = 'redaxo';
$REX['LOAD_PAGE'] = false;

require './redaxo/src/core/boot.php';
require './redaxo/src/core/packages.php';

$extensions = [
    '../../../../redaxo/src/addons/rexstan/vendor/phpstan/phpstan-deprecation-rules/rules.neon',
    '../../../../redaxo/src/addons/rexstan/config/phpstan-phpunit.neon',
];

$addon = ['../../../../redaxo/src/addons/' . getenv('ADDON_KEY') . '/'];

// level 5, PHP 8.2 -- the minimum package.yml declares
\rexstan\RexStanUserConfig::save(5, $addon, $extensions, 80200);
