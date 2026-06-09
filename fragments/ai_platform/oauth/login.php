<?php

/**
 * OAuth login form body (YCom credentials).
 *
 * @var rex_fragment $this
 * @psalm-scope-this rex_fragment
 *
 * Vars:
 * - string|null error         Login error message (already i18n-translated) or null
 * - string      hiddenFields  Hidden inputs preserving the OAuth params (raw HTML)
 */

$error = $this->getVar('error', null);
$hiddenFields = (string) $this->getVar('hiddenFields', '');
?>
<h1><?= rex_i18n::msg('ai_platform_oauth_login_title') ?></h1>
<p><?= rex_i18n::msg('ai_platform_oauth_login_intro') ?></p>
<?php if (null !== $error): ?>
    <p class="error"><?= $error ?></p>
<?php endif ?>
<form method="post" action="">
    <?= $hiddenFields ?>
    <input type="hidden" name="_action" value="login">
    <label><?= rex_i18n::msg('ai_platform_oauth_login_label') ?><br>
        <input type="text" name="login" autofocus required></label>
    <label><?= rex_i18n::msg('ai_platform_oauth_password_label') ?><br>
        <input type="password" name="password" required></label>
    <button type="submit"><?= rex_i18n::msg('ai_platform_oauth_login_submit') ?></button>
</form>
