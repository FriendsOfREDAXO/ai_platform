<?php

declare(strict_types=1);

$addon = rex_addon::get('ai_platform');
$csrfToken = rex_csrf_token::factory('ai_platform_mcp');

// Handle form submission
if ('post' === rex_request::requestMethod() && $csrfToken->isValid()) {
    rex_config::set('ai_platform', 'mcp_enabled', rex_post('mcp_enabled', 'int', 0));
    rex_config::set('ai_platform', 'mcp_description', rex_post('mcp_description', 'string', ''));

    if (rex_post('regenerate_token', 'bool', false)) {
        rex_config::set('ai_platform', 'mcp_token', bin2hex(random_bytes(32)));
        echo rex_view::success(rex_i18n::msg('ai_platform_mcp_token_regenerated'));
    }

    echo rex_view::success(rex_i18n::msg('ai_platform_settings_saved'));
}

$mcpEnabled = (int) rex_config::get('ai_platform', 'mcp_enabled', 0);
$mcpToken = rex_config::get('ai_platform', 'mcp_token', '');
$mcpDescription = rex_config::get('ai_platform', 'mcp_description', '');

// Build MCP endpoint URL
$mcpUrl = rex_url::frontendController(['rex-api-call' => 'ai_mcp'], false);
if (!str_starts_with($mcpUrl, 'http')) {
    $mcpUrl = rex::getServer() . ltrim($mcpUrl, '/');
}

// Collect registered tools for display
$tools = rex_extension::registerPoint(new rex_extension_point('AI_PLATFORM_MCP_TOOLS', []));

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
            <label class="control-label col-sm-3">' . rex_i18n::msg('ai_platform_mcp_token') . '</label>
            <div class="col-sm-9">
                <div class="input-group">
                    <input type="text" class="form-control" value="' . rex_escape($mcpToken) . '" readonly onclick="this.select()">
                    <span class="input-group-btn">
                        <label class="btn btn-default">
                            <input type="checkbox" name="regenerate_token" value="1" style="display:none">
                            <i class="rex-icon fa-refresh"></i> ' . rex_i18n::msg('ai_platform_mcp_regenerate_token') . '
                        </label>
                    </span>
                </div>
                <p class="help-block">' . rex_i18n::msg('ai_platform_mcp_token_notice') . '</p>
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

// Show registered tools
if (count($tools) > 0) {
    $toolContent = '<table class="table table-striped"><thead><tr><th>' . rex_i18n::msg('ai_platform_mcp_tool_name') . '</th><th>' . rex_i18n::msg('ai_platform_mcp_tool_description') . '</th></tr></thead><tbody>';
    foreach ($tools as $tool) {
        $toolContent .= '<tr><td><code>' . rex_escape($tool->getName()) . '</code></td><td>' . rex_escape($tool->getDescription()) . '</td></tr>';
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
                \'query\' => [
                    \'type\' => \'string\',
                    \'description\' => \'Suchbegriff\',
                ],
            ],
            \'required\' => [\'query\'],
        ],
        handler: function (array $arguments): string {
            return \'Ergebnis für: \' . $arguments[\'query\'];
        },
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
                '--header',
                'Authorization: Bearer ' . $mcpToken,
            ],
            'env' => [
                'NODE_TLS_REJECT_UNAUTHORIZED' => '0',
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</code></pre>';
$configExample .= '<p class="help-block">' . rex_i18n::msg('ai_platform_mcp_client_hint') . '</p>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('ai_platform_mcp_client_config'), false);
$fragment->setVar('content', $configExample, false);
echo $fragment->parse('core/page/section.php');
