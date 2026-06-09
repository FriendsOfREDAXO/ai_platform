<?php

/**
 * OAuth consent screen body — shows the effective scopes and the allow/deny form.
 *
 * @var rex_fragment $this
 * @psalm-scope-this rex_fragment
 *
 * Vars:
 * - string             clientName    Name of the requesting client (raw)
 * - string             userEmail     Email of the signed-in YCom user (raw)
 * - list<string>       effective     Scopes that will be granted
 * - list<string>       omitted       Requested scopes the user is NOT entitled to
 * - array<string,string> descriptions  scope => human-readable description
 * - string             hiddenFields  Hidden inputs preserving the OAuth params (raw HTML)
 */

$clientName = (string) $this->getVar('clientName', '');
$userEmail = (string) $this->getVar('userEmail', '');
$effective = (array) $this->getVar('effective', []);
$omitted = (array) $this->getVar('omitted', []);
$descriptions = (array) $this->getVar('descriptions', []);
$hiddenFields = (string) $this->getVar('hiddenFields', '');
?>
<h1><?= rex_i18n::msg('ai_platform_oauth_consent_title') ?></h1>
<p><?= rex_i18n::msg('ai_platform_oauth_consent_intro', $clientName) ?></p>
<p class="muted"><?= rex_i18n::msg('ai_platform_oauth_logged_as', $userEmail) ?></p>

<?php if ([] === $effective): ?>
    <p class="warning"><?= rex_i18n::msg('ai_platform_oauth_no_scopes') ?></p>
<?php else: ?>
    <ul class="scopes">
        <?php foreach ($effective as $scope): ?>
            <?php $desc = (string) ($descriptions[$scope] ?? ''); ?>
            <li><code><?= rex_escape((string) $scope) ?></code><?= '' !== $desc ? ' &mdash; ' . rex_escape($desc) : '' ?></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

<?php if ([] !== $omitted): ?>
    <p class="warning"><?= rex_i18n::msg('ai_platform_oauth_consent_omitted') ?> <code><?= rex_escape(implode(', ', $omitted)) ?></code></p>
<?php endif ?>

<form method="post" action="">
    <?= $hiddenFields ?>
    <input type="hidden" name="_action" value="consent">
    <button type="submit" name="decision" value="allow" class="primary"><?= rex_i18n::msg('ai_platform_oauth_consent_allow') ?></button>
    <button type="submit" name="decision" value="deny"><?= rex_i18n::msg('ai_platform_oauth_consent_deny') ?></button>
</form>
