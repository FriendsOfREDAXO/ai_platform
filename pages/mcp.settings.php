<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Mcp\Server;

$addon = rex_addon::get('ai_platform');
$csrfToken = rex_csrf_token::factory('ai_platform_mcp');
$csrfTools = rex_csrf_token::factory('ai_platform_mcp_tools');

// Handle settings form submission
if ('post' === rex_request::requestMethod() && $csrfToken->isValid()) {
    rex_config::set('ai_platform', 'mcp_enabled', rex_post('mcp_enabled', 'int', 0));
    rex_config::set('ai_platform', 'mcp_description', rex_post('mcp_description', 'string', ''));
    rex_config::set('ai_platform', 'mcp_require_auth', rex_post('mcp_require_auth', 'int', 0));
    rex_config::set('ai_platform', 'oauth_client_lifetime_days', max(0, rex_post('oauth_client_lifetime_days', 'int', 0)));

    echo rex_view::success(rex_i18n::msg('ai_platform_settings_saved'));
}

// Handle tool activation form submission. Checkboxes only submit when checked,
// so the disabled set is every registered tool name minus the submitted ones.
if ('post' === rex_request::requestMethod() && $csrfTools->isValid()) {
    $enabled = array_keys(rex_post('tool_enabled', 'array', []));
    $allToolNames = array_keys(Server::collectTools());
    $disabled = array_values(array_diff($allToolNames, $enabled));
    rex_config::set('ai_platform', 'mcp_disabled_tools', json_encode($disabled, JSON_THROW_ON_ERROR));

    echo rex_view::success(rex_i18n::msg('ai_platform_mcp_tools_saved'));
}

$mcpEnabled = (int) rex_config::get('ai_platform', 'mcp_enabled', 0);
$mcpDescription = rex_config::get('ai_platform', 'mcp_description', '');
$mcpRequireAuth = (int) rex_config::get('ai_platform', 'mcp_require_auth', 0);
$oauthClientLifetimeDays = (int) rex_config::get('ai_platform', 'oauth_client_lifetime_days', 0);

// Endpoint URLs — canonical /mcp plus the OAuth discovery endpoints
$base = rtrim(rex::getServer(), '/');
$mcpUrl = $base . '/mcp';
$discoveryProtectedResource = $base . '/.well-known/oauth-protected-resource';
$discoveryAuthServer = $base . '/.well-known/oauth-authorization-server';

// Collect registered tools for display
$tools = Server::collectTools();

$content = '
<form action="' . rex_url::currentBackendPage() . '" method="post" class="form-horizontal">
    ' . $csrfToken->getHiddenField() . '
    <fieldset>
        <legend>' . rex_i18n::msg('ai_platform_mcp_settings') . '</legend>

        <div class="form-group">
            <label class="control-label col-sm-3" for="mcp-enabled">' . rex_i18n::msg('ai_platform_mcp_enabled') . '</label>
            <div class="col-sm-9">
                <select class="form-control selectpicker" id="mcp-enabled" name="mcp_enabled">
                    <option value="1"' . (1 === $mcpEnabled ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_status_active') . '</option>
                    <option value="0"' . (0 === $mcpEnabled ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_status_inactive') . '</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3" for="mcp-require-auth">' . rex_i18n::msg('ai_platform_mcp_require_auth') . '</label>
            <div class="col-sm-9">
                <select class="form-control selectpicker" id="mcp-require-auth" name="mcp_require_auth">
                    <option value="0"' . (0 === $mcpRequireAuth ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_mcp_require_auth_off') . '</option>
                    <option value="1"' . (1 === $mcpRequireAuth ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_mcp_require_auth_on') . '</option>
                </select>
                <p class="help-block">' . rex_i18n::msg('ai_platform_mcp_require_auth_notice') . '</p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3" for="oauth-client-lifetime">' . rex_i18n::msg('ai_platform_oauth_client_lifetime') . '</label>
            <div class="col-sm-9">
                <input type="number" min="0" step="1" class="form-control" id="oauth-client-lifetime" name="oauth_client_lifetime_days" value="' . $oauthClientLifetimeDays . '">
                <p class="help-block">' . rex_i18n::msg('ai_platform_oauth_client_lifetime_notice') . '</p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3" for="mcp-description">' . rex_i18n::msg('ai_platform_mcp_description') . '</label>
            <div class="col-sm-9">
                <textarea class="form-control" id="mcp-description" name="mcp_description" rows="4">' . rex_escape($mcpDescription) . '</textarea>
                <p class="help-block">' . rex_i18n::msg('ai_platform_mcp_description_notice') . '</p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">' . rex_i18n::msg('ai_platform_mcp_endpoint') . '</label>
            <div class="col-sm-9">
                <p class="form-control-static"><code>' . rex_escape($mcpUrl) . '</code></p>
                <p class="help-block">' . rex_i18n::msg('ai_platform_mcp_endpoint_notice') . '</p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">' . rex_i18n::msg('ai_platform_mcp_discovery') . '</label>
            <div class="col-sm-9">
                <p class="form-control-static">
                    <code>' . rex_escape($discoveryProtectedResource) . '</code><br>
                    <code>' . rex_escape($discoveryAuthServer) . '</code>
                </p>
                <p class="help-block">' . rex_i18n::msg('ai_platform_mcp_discovery_notice') . '</p>
            </div>
        </div>

    </fieldset>

    <fieldset>
        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-9">
                <button class="btn btn-save" type="submit">' . rex_i18n::msg('ai_platform_save') . '</button>
            </div>
        </div>
    </fieldset>
</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('ai_platform_mcp_settings'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

// Auth/scopes info banner — explains the two access modes and where to configure them
$phaseBanner = '<div class="alert alert-info">'
    . '<strong>' . rex_i18n::msg('ai_platform_mcp_oauth_phase_title') . '</strong><br>'
    . rex_i18n::msg('ai_platform_mcp_oauth_phase_notice')
    . '</div>';
echo $phaseBanner;

// Registered tools — each row can be activated / deactivated for the MCP server
if (count($tools) > 0) {
    $disabledTools = Server::disabledTools();

    $toolContent = '<table class="table table-striped"><thead><tr>'
        . '<th>' . rex_i18n::msg('ai_platform_mcp_tool_active') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_mcp_tool_name') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_mcp_tool_description') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_mcp_tool_auth') . '</th>'
        . '</tr></thead><tbody>';
    foreach ($tools as $tool) {
        $toolName = $tool->getName();
        if ($tool->isPublic()) {
            $authLabel = '<span class="label label-success">' . rex_i18n::msg('ai_platform_mcp_tool_public') . '</span>';
        } else {
            $scopes = $tool->getRequiredScopes();
            $authLabel = '<span class="label label-warning">' . rex_i18n::msg('ai_platform_mcp_tool_protected') . '</span>';
            if ($scopes) {
                $authLabel .= ' <code>' . rex_escape(implode(', ', $scopes)) . '</code>';
            }
        }
        $checked = in_array($toolName, $disabledTools, true) ? '' : ' checked';
        $fieldId = 'tool-enabled-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $toolName);
        $toolContent .= '<tr>'
            . '<td><input type="checkbox" id="' . rex_escape($fieldId) . '" name="tool_enabled[' . rex_escape($toolName) . ']" value="1"' . $checked . '></td>'
            . '<td><label for="' . rex_escape($fieldId) . '"><code>' . rex_escape($toolName) . '</code></label></td>'
            . '<td>' . rex_escape($tool->getDescription()) . '</td>'
            . '<td>' . $authLabel . '</td>'
            . '</tr>';
    }
    $toolContent .= '</tbody></table>';

    $toolsSave = '<button type="submit" class="btn btn-save">' . rex_i18n::msg('ai_platform_save') . '</button>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', rex_i18n::msg('ai_platform_mcp_registered_tools'), false);
    $fragment->setVar('content', $toolContent, false);
    $fragment->setVar('buttons', $toolsSave, false);
    $toolsSection = $fragment->parse('core/page/section.php');

    echo '<p>' . rex_i18n::msg('ai_platform_mcp_tools_notice') . '</p>';
    echo '<form method="post" action="' . rex_url::currentBackendPage() . '">'
        . $csrfTools->getHiddenField()
        . $toolsSection
        . '</form>';
} else {
    echo rex_view::info(rex_i18n::msg('ai_platform_mcp_no_tools'));
}

// Show usage example
$exampleContent = '<pre><code>' . rex_escape('// In einem anderen AddOn (boot.php oder lib/):
use FriendsOfRedaxo\AiPlatform\Mcp\Tool;
use FriendsOfRedaxo\AiPlatform\Mcp\Context;

rex_extension::register(\'AI_PLATFORM_MCP_TOOLS\', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    $tools[\'my_tool_name\'] = new Tool(
        name: \'my_tool_name\',
        description: \'Beschreibung des Tools\',
        inputSchema: [
            \'type\' => \'object\',
            \'properties\' => [
                \'query\' => [\'type\' => \'string\', \'description\' => \'Suchbegriff\'],
            ],
            \'required\' => [\'query\'],
        ],
        handler: function (array $arguments, Context $context): string {
            // $context->getYcomUser() / $context->hasScope(\'...\') verfuegbar
            return \'Ergebnis fuer: \' . $arguments[\'query\'];
        },
        public: false,
        requiredScopes: [\'my_addon:read\'],
    );

    return $tools;
});') . '</code></pre>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('ai_platform_mcp_example'), false);
$fragment->setVar('body', $exampleContent, false);
echo $fragment->parse('core/page/section.php');
