<?php

/**
 * Mcp\SetupCheck -- the warnings on the MCP settings page (issue #10).
 *
 * Each rule gets a case that triggers it and one right next to it that must not,
 * so moving a condition shows up as a failure rather than as a warning that fires
 * everywhere or nowhere. The rules are fed directly through evaluate(); one last
 * section renders the real settings page and checks a finding reaches it.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Mcp\SetupCheck;
use FriendsOfRedaxo\AiPlatform\Mcp\Tool;
use FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry;

require __DIR__ . '/bootstrap.php';

$t = new AiTestRunner('MCP setup check');

$tool = static fn (string $name, bool $public = false, array $scopes = []): Tool => new Tool(
    name: $name,
    description: 'test',
    inputSchema: ['type' => 'object'],
    handler: static fn (): string => '',
    public: $public,
    requiredScopes: $scopes,
);

$public = ['status' => $tool('status', true)];
$protected = ['whoami' => $tool('whoami', false, ['demo:read'])];

/** The keys of the findings for one configuration; defaults are a working setup. */
$keys = static function (array $override = []) use ($public, $protected): array {
    $args = $override + [
        'enabled' => true,
        'requireAuth' => true,
        'loginAvailable' => true,
        'tools' => $public + $protected,
        'disabledTools' => [],
        'alwaysAvailableTools' => [],
        'mappedScopes' => ['demo:read'],
    ];

    return array_column(SetupCheck::evaluate(...$args), 'key');
};

// ---------------------------------------------------------------------------
$t->section('A working setup');

$t->assertSame([], $keys(), 'raises nothing');

// ---------------------------------------------------------------------------
$t->section('Switched off');

$t->assertSame(['disabled'], $keys(['enabled' => false]), 'is said, and only that');

// ---------------------------------------------------------------------------
$t->section('Sign-in required, nowhere to sign in');

$t->assert(in_array('auth_without_login', $keys(['loginAvailable' => false]), true), 'warns');
$t->assert(!in_array('auth_without_login', $keys(['loginAvailable' => false, 'requireAuth' => false]), true), 'not while sign-in is optional — the field itself already warns there');

// ---------------------------------------------------------------------------
$t->section('Protected tools, sign-in optional');

$t->assert(in_array('protected_without_auth', $keys(['requireAuth' => false]), true), 'warns: such a client connects anonymously and never sees them');
$t->assert(!in_array('protected_without_auth', $keys(['requireAuth' => false, 'tools' => $public]), true), 'not with public tools only');
$t->assert(!in_array('protected_without_auth', $keys(['requireAuth' => false, 'disabledTools' => ['whoami']]), true), 'not when the protected tool is switched off');

$finding = SetupCheck::evaluate(true, false, true, $public + $protected, [], [], ['demo:read']);
$t->assertSame(['whoami'], $finding[0]['params'] ?? null, 'and names the tool');

// ---------------------------------------------------------------------------
$t->section('Sign-in required, nothing protected');

$t->assert(in_array('auth_without_protected', $keys(['tools' => $public]), true), 'points out that signing in gains nothing');
$t->assert(!in_array('auth_without_protected', $keys(['tools' => $public, 'requireAuth' => false]), true), 'not while sign-in is optional');

// ---------------------------------------------------------------------------
$t->section('A tool whose scopes no group holds');

$t->assert(in_array('unreachable_tools', $keys(['mappedScopes' => []]), true), 'warns');
$t->assert(in_array('unreachable_tools', $keys(['mappedScopes' => ['other:scope']]), true), 'also when other scopes are mapped');
$t->assert(!in_array('unreachable_tools', $keys(['mappedScopes' => [], 'alwaysAvailableTools' => ['whoami']]), true), 'not when the tool is released to everyone signed in');
$t->assert(!in_array('unreachable_tools', $keys(['mappedScopes' => [], 'tools' => $public + ['open' => $tool('open')]]), true), 'not for a protected tool that demands no scope');
$t->assert(!in_array('unreachable_tools', $keys(['mappedScopes' => [], 'disabledTools' => ['whoami']]), true), 'not for a switched-off tool');

$finding = SetupCheck::evaluate(true, true, true, ['two' => $tool('two', false, ['a:x', 'b:y'])], [], [], ['a:x']);
$t->assertSame(['two (b:y)'], $finding[0]['params'] ?? null, 'names the tool and only the scope that is missing');

// ---------------------------------------------------------------------------
$t->section('The settings page');

$original = rex_config::get('ai_platform', 'mcp_enabled', 0);
try {
    rex_config::set('ai_platform', 'mcp_enabled', 0);
    rex_be_controller::setCurrentPage('ai_platform/mcp/settings');
    ob_start();
    try {
        include rex_addon::get('ai_platform')->getPath('pages/mcp.settings.php');
    } finally {
        $html = (string) ob_get_clean();
    }
    $t->assert(str_contains($html, rex_i18n::msg('ai_platform_mcp_check_disabled')), 'shows the finding for the current configuration');
    $t->assert(!str_contains($html, '[translate:'), 'with every text translated');
} finally {
    rex_config::set('ai_platform', 'mcp_enabled', $original);
}

$t->assert(is_array(ScopeRegistry::mappedScopes()), 'mappedScopes() runs against the real table');

exit($t->summary());
