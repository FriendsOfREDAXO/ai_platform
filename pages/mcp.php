<?php

declare(strict_types=1);

$addon = rex_addon::get('ai_platform');
$csrfToken = rex_csrf_token::factory('ai_platform_mcp');

// Handle form submission
if ('post' === rex_request::requestMethod() && $csrfToken->isValid()) {
    rex_config::set('ai_platform', 'mcp_enabled', rex_post('mcp_enabled', 'int', 0));
    rex_config::set('ai_platform', 'mcp_description', rex_post('mcp_description', 'string', ''));

    echo rex_view::success(rex_i18n::msg('ai_platform_settings_saved'));
}

$mcpEnabled = (int) rex_config::get('ai_platform', 'mcp_enabled', 0);
$mcpDescription = rex_config::get('ai_platform', 'mcp_description', '');

// Endpoint URLs — canonical /mcp plus the OAuth discovery endpoints
$base = rtrim(rex::getServer(), '/');
$mcpUrl = $base . '/mcp';
$discoveryProtectedResource = $base . '/.well-known/oauth-protected-resource';
$discoveryAuthServer = $base . '/.well-known/oauth-authorization-server';
$legacyUrl = $base . '/' . ltrim(rex_url::frontendController(['rex-api-call' => 'ai_mcp'], false), '/');

// Collect registered tools for display
$tools = rex_ai_mcp_server::collectTools();

$content = '
<form action="' . rex_url::currentBackendPage() . '" method="post">
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

        <div class="form-group">
            <label class="control-label col-sm-3">' . rex_i18n::msg('ai_platform_mcp_legacy_endpoint') . '</label>
            <div class="col-sm-9">
                <p class="form-control-static"><code>' . rex_escape($legacyUrl) . '</code></p>
                <p class="help-block text-warning">' . rex_i18n::msg('ai_platform_mcp_legacy_endpoint_notice') . '</p>
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

// Phase-1 status banner — make it obvious that OAuth is not wired up yet
$phaseBanner = '<div class="alert alert-info">'
    . '<strong>' . rex_i18n::msg('ai_platform_mcp_oauth_phase_title') . '</strong><br>'
    . rex_i18n::msg('ai_platform_mcp_oauth_phase_notice')
    . '</div>';
echo $phaseBanner;

// Show registered tools with their auth requirements
if (count($tools) > 0) {
    $toolContent = '<table class="table table-striped"><thead><tr>'
        . '<th>' . rex_i18n::msg('ai_platform_mcp_tool_name') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_mcp_tool_description') . '</th>'
        . '<th>' . rex_i18n::msg('ai_platform_mcp_tool_auth') . '</th>'
        . '</tr></thead><tbody>';
    foreach ($tools as $tool) {
        if ($tool->isPublic()) {
            $authLabel = '<span class="label label-success">' . rex_i18n::msg('ai_platform_mcp_tool_public') . '</span>';
        } else {
            $scopes = $tool->getRequiredScopes();
            $authLabel = '<span class="label label-warning">' . rex_i18n::msg('ai_platform_mcp_tool_protected') . '</span>';
            if ($scopes) {
                $authLabel .= ' <code>' . rex_escape(implode(', ', $scopes)) . '</code>';
            }
        }
        $toolContent .= '<tr>'
            . '<td><code>' . rex_escape($tool->getName()) . '</code></td>'
            . '<td>' . rex_escape($tool->getDescription()) . '</td>'
            . '<td>' . $authLabel . '</td>'
            . '</tr>';
    }
    $toolContent .= '</tbody></table>';
} else {
    $toolContent = '<p class="text-muted">' . rex_i18n::msg('ai_platform_mcp_no_tools') . '</p>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('ai_platform_mcp_registered_tools'), false);
$fragment->setVar('content', $toolContent, false);
echo $fragment->parse('core/page/section.php');

// Show usage example
$exampleContent = '<pre><code>' . rex_escape('// In einem anderen AddOn (boot.php oder lib/):
rex_extension::register(\'AI_PLATFORM_MCP_TOOLS\', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    $tools[\'my_tool_name\'] = new rex_ai_mcp_tool(
        name: \'my_tool_name\',
        description: \'Beschreibung des Tools\',
        inputSchema: [
            \'type\' => \'object\',
            \'properties\' => [
                \'query\' => [\'type\' => \'string\', \'description\' => \'Suchbegriff\'],
            ],
            \'required\' => [\'query\'],
        ],
        handler: function (array $arguments, rex_ai_mcp_context $context): string {
            // $context->getYcomUser() / $context->hasScope(\'...\') verfuegbar
            return \'Ergebnis fuer: \' . $arguments[\'query\'];
        },
        public: false,
        requiredScopes: [\'mcp:tools:call\'],
    );

    return $tools;
});') . '</code></pre>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('ai_platform_mcp_example'), false);
$fragment->setVar('content', $exampleContent, false);
echo $fragment->parse('core/page/section.php');

// Show Claude Desktop config example
$configExample = '<p>' . rex_i18n::msg('ai_platform_mcp_client_intro') . '</p>';
$configExample .= '<pre><code>npm install -g mcp-remote</code></pre>';
$configExample .= '<p><strong>claude_desktop_config.json:</strong></p>';
$configExample .= '<pre><code>' . rex_escape(json_encode([
    'mcpServers' => [
        'redaxo' => [
            'command' => 'npx',
            'args' => [
                'mcp-remote',
                $mcpUrl,
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</code></pre>';
$configExample .= '<p class="help-block">' . rex_i18n::msg('ai_platform_mcp_client_hint') . '</p>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('ai_platform_mcp_client_config'), false);
$fragment->setVar('content', $configExample, false);
echo $fragment->parse('core/page/section.php');
