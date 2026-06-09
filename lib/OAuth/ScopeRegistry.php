<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\OAuth;

use rex;
use rex_extension;
use rex_extension_point;
use rex_sql;
use rex_ycom_user;

/**
 * Central registry for OAuth scopes.
 *
 * Built-in scopes ship with this addon. Other addons can announce their
 * own scopes via the AI_PLATFORM_OAUTH_SCOPES extension point so they
 * show up in the backend group-to-scope mapping UI.
 *
 * Effective scopes for a YCom user are derived from the rex_ai_scope_mapping
 * table: every group the user belongs to contributes its scope list,
 * deduplicated into a single set.
 */
final class ScopeRegistry
{
    /**
     * @deprecated since 1.0.0-beta3 — never enforced and removed from the
     * advertised scope list. Tool access is gated solely by the tool's
     * `public` flag and its own `requiredScopes`; there is no global
     * "tools" scope (a global gate would conflict with public tools, which
     * must stay reachable without any scope). Kept only so existing
     * references don't fatal.
     */
    public const SCOPE_TOOLS_READ = 'mcp:tools:read';
    /** @deprecated since 1.0.0-beta3 — see {@see SCOPE_TOOLS_READ}. */
    public const SCOPE_TOOLS_CALL = 'mcp:tools:call';

    /**
     * Built-in scopes provided by this addon.
     *
     * Empty by design: tools declare their own `requiredScopes`, so there is
     * no global tools scope to grant. The selectable scopes in the backend
     * therefore come entirely from consumer addons via AI_PLATFORM_OAUTH_SCOPES.
     *
     * @return array<string, string> scope => human-readable description
     */
    public static function builtInScopes(): array
    {
        return [];
    }

    /**
     * All known scopes including those announced by other addons.
     *
     * @return array<string, string>
     */
    public static function allScopes(): array
    {
        $scopes = self::builtInScopes();
        $scopes = rex_extension::registerPoint(new rex_extension_point(
            'AI_PLATFORM_OAUTH_SCOPES',
            $scopes,
        ));
        return $scopes;
    }

    /**
     * Resolves the effective scopes for a given YCom user. Looks up every
     * group the user is a member of and merges the mapped scope lists.
     *
     * @return list<string>
     */
    public static function resolveScopesForYcomUser(int $ycomUserId): array
    {
        if (!class_exists('rex_ycom_user')) {
            return [];
        }
        $user = rex_ycom_user::get($ycomUserId);
        if (null === $user) {
            return [];
        }

        $groupIds = array_filter(array_map('intval', $user->getGroups()));
        if (!$groupIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT scopes FROM ' . rex::getTable('ai_scope_mapping') . ' WHERE ycom_group_id IN (' . $placeholders . ')',
            array_values($groupIds),
        );

        $scopes = [];
        foreach ($sql as $row) {
            $decoded = json_decode((string) $row->getValue('scopes'), true);
            if (is_array($decoded)) {
                foreach ($decoded as $scope) {
                    if (is_string($scope) && '' !== $scope) {
                        $scopes[$scope] = true;
                    }
                }
            }
        }

        return array_keys($scopes);
    }

    /**
     * Stores the scope list for a YCom group. Empty list removes the mapping.
     *
     * @param list<string> $scopes
     */
    public static function setScopesForGroup(int $ycomGroupId, array $scopes): void
    {
        $sql = rex_sql::factory();
        if (!$scopes) {
            $sql->setQuery(
                'DELETE FROM ' . rex::getTable('ai_scope_mapping') . ' WHERE ycom_group_id = ?',
                [$ycomGroupId],
            );
            return;
        }

        $sql->setQuery(
            'SELECT id FROM ' . rex::getTable('ai_scope_mapping') . ' WHERE ycom_group_id = ?',
            [$ycomGroupId],
        );

        $payload = json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR);
        $write = rex_sql::factory();
        $write->setTable(rex::getTable('ai_scope_mapping'));

        if (0 === $sql->getRows()) {
            $write->setValue('ycom_group_id', $ycomGroupId);
            $write->setValue('scopes', $payload);
            $write->addGlobalCreateFields();
            $write->insert();
        } else {
            $write->setWhere(['id' => (int) $sql->getValue('id')]);
            $write->setValue('scopes', $payload);
            $write->addGlobalUpdateFields();
            $write->update();
        }
    }

    /**
     * @return list<string>
     */
    public static function getScopesForGroup(int $ycomGroupId): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT scopes FROM ' . rex::getTable('ai_scope_mapping') . ' WHERE ycom_group_id = ?',
            [$ycomGroupId],
        );
        if (0 === $sql->getRows()) {
            return [];
        }
        $decoded = json_decode((string) $sql->getValue('scopes'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_string'));
    }
}
