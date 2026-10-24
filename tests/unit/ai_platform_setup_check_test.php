<?php

use FriendsOfRedaxo\AiPlatform\Mcp\SetupCheck;
use FriendsOfRedaxo\AiPlatform\Mcp\Tool;
use PHPUnit\Framework\TestCase;

/**
 * The rules behind the warnings on the MCP settings page (issue #10).
 *
 * Each rule has a case that triggers it and a neighbouring one that must not.
 *
 * @internal
 */
final class ai_platform_setup_check_test extends TestCase
{
    /** @param list<string> $scopes */
    private static function tool(string $name, bool $public = false, array $scopes = []): Tool
    {
        return new Tool(
            name: $name,
            description: 'test',
            inputSchema: ['type' => 'object'],
            handler: static fn (): string => '',
            public: $public,
            requiredScopes: $scopes,
        );
    }

    /**
     * The finding keys for one configuration; the defaults describe a setup that works.
     *
     * @param array<string, mixed> $override
     * @return list<string>
     */
    private static function keys(array $override = []): array
    {
        $args = $override + [
            'enabled' => true,
            'requireAuth' => true,
            'loginAvailable' => true,
            'tools' => ['status' => self::tool('status', true), 'whoami' => self::tool('whoami', false, ['demo:read'])],
            'disabledTools' => [],
            'alwaysAvailableTools' => [],
            'mappedScopes' => ['demo:read'],
        ];

        return array_column(SetupCheck::evaluate(...$args), 'key');
    }

    public function testWorkingSetupRaisesNothing(): void
    {
        self::assertSame([], self::keys());
    }

    public function testSwitchedOffIsSaidAndOnlyThat(): void
    {
        self::assertSame(['disabled'], self::keys(['enabled' => false]));
    }

    public function testSignInRequiredWithoutLoginPage(): void
    {
        self::assertContains('auth_without_login', self::keys(['loginAvailable' => false]));
        self::assertNotContains('auth_without_login', self::keys(['loginAvailable' => false, 'requireAuth' => false]));
    }

    public function testProtectedToolsWhileSignInIsOptional(): void
    {
        self::assertContains('protected_without_auth', self::keys(['requireAuth' => false]));
        self::assertNotContains('protected_without_auth', self::keys(['requireAuth' => false, 'disabledTools' => ['whoami']]));

        $finding = SetupCheck::evaluate(true, false, true, ['whoami' => self::tool('whoami', false, ['demo:read'])], [], [], ['demo:read']);
        self::assertSame(['whoami'], $finding[0]['params']);
    }

    public function testSignInRequiredWithNothingProtected(): void
    {
        $public = ['status' => self::tool('status', true)];
        self::assertContains('auth_without_protected', self::keys(['tools' => $public]));
        self::assertNotContains('auth_without_protected', self::keys(['tools' => $public, 'requireAuth' => false]));
    }

    public function testToolWhoseScopesNoGroupHolds(): void
    {
        self::assertContains('unreachable_tools', self::keys(['mappedScopes' => []]));
        self::assertContains('unreachable_tools', self::keys(['mappedScopes' => ['other:scope']]));
        self::assertNotContains('unreachable_tools', self::keys(['mappedScopes' => [], 'alwaysAvailableTools' => ['whoami']]));
        self::assertNotContains('unreachable_tools', self::keys(['mappedScopes' => [], 'disabledTools' => ['whoami']]));
        self::assertNotContains('unreachable_tools', self::keys(['mappedScopes' => [], 'tools' => ['open' => self::tool('open')]]));

        $finding = SetupCheck::evaluate(true, true, true, ['two' => self::tool('two', false, ['a:x', 'b:y'])], [], [], ['a:x']);
        self::assertSame(['two (b:y)'], $finding[0]['params'], 'only the missing scope is named');
    }
}
