<?php

declare(strict_types=1);

if (!class_exists('rex_ycom_group')) {
    echo rex_view::warning(rex_i18n::msg('ai_platform_scopes_no_groups'));
    return;
}

$csrf = rex_csrf_token::factory('ai_platform_scope_mapping');

// Save submitted mapping
if ('post' === rex_request::requestMethod() && $csrf->isValid()) {
    /** @var array<int, array<int, string>> $submitted */
    $submitted = rex_post('scopes', 'array', []);
    foreach ($submitted as $groupId => $scopes) {
        $groupId = (int) $groupId;
        if ($groupId <= 0) {
            continue;
        }
        $filtered = is_array($scopes)
            ? array_values(array_filter($scopes, static fn ($s) => is_string($s) && '' !== $s))
            : [];
        rex_ai_oauth_scope_registry::setScopesForGroup($groupId, $filtered);
    }
    echo rex_view::success(rex_i18n::msg('ai_platform_scopes_saved'));
}

$groups = rex_ycom_group::query()->orderBy('name')->find();
if (0 === count($groups)) {
    echo rex_view::info(rex_i18n::msg('ai_platform_scopes_no_groups'));
    return;
}

echo '<p>' . rex_i18n::msg('ai_platform_scopes_intro') . '</p>';

$allScopes = rex_ai_oauth_scope_registry::allScopes();

$body = '<table class="table table-striped">'
    . '<thead><tr>'
    . '<th>' . rex_i18n::msg('ai_platform_scopes_group') . '</th>'
    . '<th>' . rex_i18n::msg('ai_platform_scopes_scopes') . '</th>'
    . '</tr></thead><tbody>';

foreach ($groups as $group) {
    $groupId = (int) $group->getId();
    $groupName = (string) $group->getValue('name');
    $current = rex_ai_oauth_scope_registry::getScopesForGroup($groupId);

    $checkboxes = '';
    foreach ($allScopes as $scope => $description) {
        $checked = in_array($scope, $current, true) ? ' checked' : '';
        $fieldId = 'scope-' . $groupId . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $scope);
        $checkboxes .= '<div class="checkbox">'
            . '<label for="' . rex_escape($fieldId) . '">'
            . '<input type="checkbox" id="' . rex_escape($fieldId) . '" name="scopes[' . $groupId . '][]" value="' . rex_escape($scope) . '"' . $checked . '> '
            . '<code>' . rex_escape($scope) . '</code>'
            . ('' !== $description ? ' <small class="text-muted">' . rex_escape($description) . '</small>' : '')
            . '</label></div>';
    }

    $body .= '<tr>'
        . '<td><strong>' . rex_escape($groupName) . '</strong><br><small class="text-muted">#' . $groupId . '</small></td>'
        . '<td>' . $checkboxes . '</td>'
        . '</tr>';
}

$body .= '</tbody></table>';

$submitButton = '<button type="submit" class="btn btn-save">' . rex_i18n::msg('ai_platform_save') . '</button>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('ai_platform_scopes_title'), false);
$fragment->setVar('content', $body, false);
$fragment->setVar('buttons', $submitButton, false);
$section = $fragment->parse('core/page/section.php');

echo '<form method="post" action="' . rex_url::currentBackendPage() . '">'
    . $csrf->getHiddenField()
    . $section
    . '</form>';
