<?php

/**
 * OAuth error page body (unknown client, redirect mismatch, expired client …).
 *
 * @var rex_fragment $this
 * @psalm-scope-this rex_fragment
 *
 * Vars:
 * - string code     OAuth error code, e.g. "invalid_client" (raw)
 * - string message  Human-readable detail (raw)
 */

$code = (string) $this->getVar('code', '');
$message = (string) $this->getVar('message', '');
?>
<h1><?= rex_i18n::msg('ai_platform_oauth_error_title') ?></h1>
<p class="error"><strong><?= rex_escape($code) ?></strong></p>
<p><?= rex_escape($message) ?></p>
