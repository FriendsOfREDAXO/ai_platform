<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry;
use rex_article;
use rex_config;
use rex_ycom_config;

use function in_array;

/**
 * Settings that each look fine on their own and do not work together.
 *
 * Every switch on the MCP page has a sensible value in isolation. What goes wrong
 * in practice is the combination: protected tools while sign-in is optional (the
 * client connects anonymously and never sees them), sign-in required while YCom
 * has no login page (nobody gets in), a tool whose scopes no group hands out
 * (nobody may call it). None of that produces an error anywhere -- the client
 * simply shows fewer tools. This class names those cases on the settings page
 * (issue #10).
 *
 * Deliberately no HTTP request against the own /mcp: what it could find out is
 * already in the configuration, and a request from the server to itself fails
 * for reasons that say nothing about the setup (loopback, proxy, certificate).
 */
final class SetupCheck
{
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';

    /**
     * @return list<array{level: string, key: string, params: list<string>}>
     */
    public static function findings(): array
    {
        $loginArticleId = class_exists('rex_ycom_config') ? (int) rex_ycom_config::get('article_id_login') : 0;

        return self::evaluate(
            enabled: (bool) rex_config::get('ai_platform', 'mcp_enabled', false),
            requireAuth: (bool) rex_config::get('ai_platform', 'mcp_require_auth', false),
            loginAvailable: $loginArticleId > 0 && null !== rex_article::get($loginArticleId),
            tools: Server::collectTools(),
            disabledTools: Server::disabledTools(),
            alwaysAvailableTools: Server::alwaysAvailableTools(),
            mappedScopes: ScopeRegistry::mappedScopes(),
        );
    }

    /**
     * The rules themselves, free of configuration reads so a test can feed them.
     *
     * @param array<string, Tool> $tools
     * @param list<string> $disabledTools
     * @param list<string> $alwaysAvailableTools
     * @param list<string> $mappedScopes
     * @return list<array{level: string, key: string, params: list<string>}>
     */
    public static function evaluate(
        bool $enabled,
        bool $requireAuth,
        bool $loginAvailable,
        array $tools,
        array $disabledTools,
        array $alwaysAvailableTools,
        array $mappedScopes,
    ): array {
        $findings = [];

        if (!$enabled) {
            $findings[] = ['level' => self::LEVEL_INFO, 'key' => 'disabled', 'params' => []];
        }

        if ($requireAuth && !$loginAvailable) {
            $findings[] = ['level' => self::LEVEL_WARNING, 'key' => 'auth_without_login', 'params' => []];
        }

        $protected = [];
        $unreachable = [];
        foreach ($tools as $tool) {
            if ($tool->isPublic() || in_array($tool->getName(), $disabledTools, true)) {
                continue;
            }
            $protected[] = $tool->getName();

            // Released to everyone signed in, or no scope demanded: any login will do.
            if (in_array($tool->getName(), $alwaysAvailableTools, true)) {
                continue;
            }
            $missing = array_values(array_diff($tool->getRequiredScopes(), $mappedScopes));
            if ([] !== $missing) {
                $unreachable[] = $tool->getName() . ' (' . implode(', ', $missing) . ')';
            }
        }

        if ([] !== $protected && !$requireAuth) {
            $findings[] = ['level' => self::LEVEL_WARNING, 'key' => 'protected_without_auth', 'params' => [implode(', ', $protected)]];
        }

        if ([] === $protected && $requireAuth) {
            $findings[] = ['level' => self::LEVEL_INFO, 'key' => 'auth_without_protected', 'params' => []];
        }

        if ([] !== $unreachable) {
            $findings[] = ['level' => self::LEVEL_WARNING, 'key' => 'unreachable_tools', 'params' => [implode('; ', $unreachable)]];
        }

        return $findings;
    }
}
