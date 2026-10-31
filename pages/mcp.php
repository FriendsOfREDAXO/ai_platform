<?php

/**
 * MCP-Server: Adresse, Zugang, Tools und die Hinweise, die ein Client beim Verbinden bekommt.
 *
 * @var Addon $this
 */

use FriendsOfRedaxo\AiPlatform\Backend\McpData;
use FriendsOfRedaxo\AiPlatform\Mcp\McpEndpoint;
use FriendsOfRedaxo\AiPlatform\Mcp\ToolRegistry;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Form\Form;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\Translation\I18n;
use Redaxo\Core\View\Component\Section;
use Redaxo\Core\View\Component\SectionVariant;
use Redaxo\Core\View\Component\Table;
use Redaxo\Core\View\Component\TableColumn;
use Redaxo\Core\View\Html;
use Redaxo\Core\View\Message;

use function Redaxo\Core\View\escape;

$addon = $this;

if (!McpEndpoint::isAvailable()) {
    echo Message::warning($addon->i18n('mcp_requires_api'));
}

$data = new McpData();
$data->instructions = (string) $addon->getConfig('mcp_instructions', '') ?: null;
$form = new Form($data, name: 'ai_platform_mcp', title: $addon->i18n('mcp_instructions_title'));
if ($form->handleRequest() && $form->isValid()) {
    $addon->setConfig('mcp_instructions', trim((string) $data->instructions));
    Response::sendRedirect(Url::currentBackendPage(['info' => 'saved']));
}
if ('saved' === Request::get('info', 'string', '')) {
    echo Message::success($addon->i18n('settings_saved'));
}

if (McpEndpoint::isAvailable()) {
    $url = McpEndpoint::getUrl();
    $claude = 'claude mcp add --transport http ' . I18n::rawMsg('ai_platform_mcp_server_key') . ' ' . $url . ' \\' . "\n" . '  --header "Authorization: Bearer <TOKEN>"';
    $json = json_encode([
        'mcpServers' => [
            I18n::rawMsg('ai_platform_mcp_server_key') => [
                'type' => 'http',
                'url' => $url,
                'headers' => ['Authorization' => 'Bearer <TOKEN>'],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // rawMsg, weil die Platzhalter Markup sind; der Text selbst enthält keins, die Platzhalter sind maskiert.
    $body = '<p>' . I18n::rawMsg('ai_platform_mcp_access_intro', '<code>' . escape(McpEndpoint::SCOPE) . '</code>', '<a href="' . escape(Url::backendPage('api/token')) . '">' . $addon->i18n('mcp_token_page') . '</a>') . '</p>'
        . '<p><b>' . $addon->i18n('mcp_url') . '</b><br><code>' . escape($url) . '</code></p>'
        . '<p><b>Claude Code</b></p><pre class="ai-mcp-config"><code>' . escape($claude) . '</code></pre>'
        . '<p><b>' . $addon->i18n('mcp_config_json') . '</b></p><pre class="ai-mcp-config"><code>' . escape((string) $json) . '</code></pre>';
    echo new Section(Html::raw($addon->i18n('mcp_access')), Html::raw($body))->render();
}

$rows = [];
foreach (ToolRegistry::all() as $tool) {
    $handler = $tool['handler'] ?? null;
    $rows[] = [
        'name' => (string) ($tool['name'] ?? ''),
        'description' => (string) ($tool['description'] ?? ''),
        'handler' => is_array($handler) ? implode('::', $handler) : (is_string($handler) ? $handler : 'Closure'),
    ];
}
$table = new Table(
    [
        new TableColumn(Html::raw($addon->i18n('mcp_tool_name')), static fn (array $row): Html => Html::raw('<code>' . escape($row['name']) . '</code>')),
        new TableColumn(Html::raw($addon->i18n('mcp_tool_description')), static fn (array $row): string => $row['description']),
        new TableColumn(Html::raw($addon->i18n('mcp_tool_handler')), static fn (array $row): Html => Html::raw('<code>' . escape($row['handler']) . '</code>')),
    ],
    $rows,
    empty: Html::raw($addon->i18n('mcp_tools_empty')),
);
echo new Section(Html::raw($addon->i18n('mcp_tools')), Html::raw('<p>' . $addon->i18n('mcp_tools_intro') . '</p>'), content: $table)->render();

echo $form->capture(static function (Form $form): void {
    echo new Section(
        $form->title ?? '',
        Html::capture(static function () use ($form): void {
            echo $form->rest();
        }),
        footer: Html::raw('<div class="rex-form-panel-footer">' . $form->buttons() . '</div>'),
        variant: SectionVariant::Edit,
    )->render();
});
