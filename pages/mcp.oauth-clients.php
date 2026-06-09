<?php

declare(strict_types=1);

$csrf = rex_csrf_token::factory('ai_platform_oauth_clients');
$func = rex_request('func', 'string', '');
$id = rex_request('id', 'int', 0);
$justCreated = null;

// Handle delete
if ('delete' === $func && $id > 0 && $csrf->isValid()) {
    $row = rex_sql::factory();
    $row->setQuery('SELECT client_id FROM ' . rex::getTable('ai_oauth_client') . ' WHERE id = ?', [$id]);
    if ($row->getRows() > 0) {
        rex_ai_oauth_token_store::revokeAllForClient((string) $row->getValue('client_id'));
        rex_ai_oauth_client_store::deleteById($id);
        echo rex_view::success(rex_i18n::msg('ai_platform_oauth_client_deleted'));
    }
}

// Handle create
if ('post' === rex_request::requestMethod() && 'create' === rex_post('action', 'string') && $csrf->isValid()) {
    $name = trim(rex_post('client_name', 'string', ''));
    $type = rex_post('type', 'string', rex_ai_oauth_client_store::TYPE_PUBLIC);
    $rawUris = rex_post('redirect_uris', 'string', '');
    $uris = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $rawUris) ?: [])));

    if ('' === $name || [] === $uris) {
        echo rex_view::error(rex_i18n::msg('ai_platform_oauth_client_redirect_required'));
    } else {
        $created = rex_ai_oauth_client_store::create(
            $name,
            $uris,
            rex_ai_oauth_client_store::TYPE_CONFIDENTIAL === $type
                ? rex_ai_oauth_client_store::TYPE_CONFIDENTIAL
                : rex_ai_oauth_client_store::TYPE_PUBLIC,
            false,
        );
        $justCreated = $created;
        echo rex_view::success(rex_i18n::msg('ai_platform_oauth_client_created'));
    }
}

echo '<p>' . rex_i18n::msg('ai_platform_oauth_clients_intro') . '</p>';

if (null !== $justCreated) {
    $box = '<dl class="dl-horizontal">';
    $box .= '<dt>' . rex_i18n::msg('ai_platform_oauth_client_id') . '</dt>';
    $box .= '<dd><code>' . rex_escape($justCreated['client_id']) . '</code></dd>';
    if (null !== $justCreated['client_secret']) {
        $box .= '<dt>' . rex_i18n::msg('ai_platform_oauth_client_secret_notice') . '</dt>';
        $box .= '<dd><code>' . rex_escape($justCreated['client_secret']) . '</code></dd>';
    }
    $box .= '</dl>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'info', false);
    $fragment->setVar('title', rex_i18n::msg('ai_platform_oauth_client_created'), false);
    $fragment->setVar('body', $box, false);
    echo $fragment->parse('core/page/section.php');
}

// --- Client list
$clients = rex_ai_oauth_client_store::findAll();
if ([] === $clients) {
    echo rex_view::info(rex_i18n::msg('ai_platform_oauth_clients_empty'));
} else {
    $listBody = '<table class="table table-striped"><thead><tr>'
        . '<th>' . rex_i18n::msg('ai_platform_oauth_client_name') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_oauth_client_id') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_oauth_client_type') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_oauth_client_redirect') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_oauth_client_created_at') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_oauth_client_last_used') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_oauth_client_expires') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_actions') . '</th>'
        . '</tr></thead><tbody>';
    foreach ($clients as $client) {
        $dcrBadge = $client['created_by_dcr']
            ? ' <span class="label label-info">' . rex_i18n::msg('ai_platform_oauth_client_dcr') . '</span>'
            : '';
        $deleteUrl = rex_url::currentBackendPage(array_merge(
            ['func' => 'delete', 'id' => $client['id']],
            $csrf->getUrlParams(),
        ));

        $expiresAt = rex_ai_oauth_client_store::expiresAt($client);
        if (rex_ai_oauth_client_store::isExpired($client)) {
            $expiryCell = '<span class="label label-danger">' . rex_i18n::msg('ai_platform_oauth_client_expired') . '</span>';
        } elseif (null === $expiresAt) {
            $expiryCell = '<span class="text-muted">' . rex_i18n::msg('ai_platform_oauth_client_never_expires') . '</span>';
        } else {
            $expiryCell = rex_escape($expiresAt);
        }

        $listBody .= '<tr>'
            . '<td>' . rex_escape($client['client_name']) . $dcrBadge . '</td>'
            . '<td><code>' . rex_escape($client['client_id']) . '</code></td>'
            . '<td>' . rex_escape($client['type']) . '</td>'
            . '<td>' . nl2br(rex_escape(implode("\n", $client['redirect_uris']))) . '</td>'
            . '<td>' . rex_escape((string) ($client['createdate'] ?? '–')) . '</td>'
            . '<td>' . rex_escape((string) ($client['last_used_at'] ?? '–')) . '</td>'
            . '<td>' . $expiryCell . '</td>'
            . '<td><a class="btn btn-delete" href="' . $deleteUrl . '" '
            . 'data-confirm="' . rex_escape(rex_i18n::msg('ai_platform_oauth_client_delete_confirm')) . '">'
            . '<i class="rex-icon rex-icon-delete"></i></a></td>'
            . '</tr>';
    }
    $listBody .= '</tbody></table>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('ai_platform_oauth_clients_title'), false);
    $fragment->setVar('content', $listBody, false);
    echo $fragment->parse('core/page/section.php');
}

// --- Create form
$formFields = '<div class="form-group">'
    . '<label class="control-label col-sm-3" for="oauth-client-name">' . rex_i18n::msg('ai_platform_oauth_client_name') . '</label>'
    . '<div class="col-sm-9"><input type="text" class="form-control" id="oauth-client-name" name="client_name" required></div>'
    . '</div>'
    . '<div class="form-group">'
    . '<label class="control-label col-sm-3" for="oauth-client-type">' . rex_i18n::msg('ai_platform_oauth_client_type') . '</label>'
    . '<div class="col-sm-9"><select class="form-control selectpicker" id="oauth-client-type" name="type">'
    . '<option value="public">public (PKCE only)</option>'
    . '<option value="confidential">confidential (client_secret)</option>'
    . '</select></div>'
    . '</div>'
    . '<div class="form-group">'
    . '<label class="control-label col-sm-3" for="oauth-client-redirect">' . rex_i18n::msg('ai_platform_oauth_client_redirect') . '</label>'
    . '<div class="col-sm-9">'
    . '<textarea class="form-control" id="oauth-client-redirect" name="redirect_uris" rows="3" required></textarea>'
    . '<p class="help-block">' . rex_i18n::msg('ai_platform_oauth_client_redirect_notice') . '</p>'
    . '</div></div>';

$submitButton = '<button type="submit" class="btn btn-save rex-form-aligned">' . rex_i18n::msg('ai_platform_oauth_client_add') . '</button>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('ai_platform_oauth_client_add'), false);
$fragment->setVar('body', $formFields, false);
$fragment->setVar('buttons', $submitButton, false);
$createSection = $fragment->parse('core/page/section.php');

echo '<form method="post" action="' . rex_url::currentBackendPage() . '" class="form-horizontal">'
    . $csrf->getHiddenField()
    . '<input type="hidden" name="action" value="create">'
    . $createSection
    . '</form>';
