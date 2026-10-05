<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\OAuth;

use rex;
use rex_config;
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
    /** Default for the fallback scope; overridable under MCP server > settings. */
    public const SCOPE_FALLBACK_DEFAULT = 'mcp';

    /** Config key holding the installation's own name for that scope. */
    public const CONFIG_FALLBACK_SCOPE = 'mcp_fallback_scope';

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
     * The scope announced when nothing else is on offer.
     *
     * A name, not a permission: it exists because discovery must not advertise
     * an empty scope list -- clients that find one stop before they ever send
     * the user to a login page. Installations that announce scopes of their own
     * never see it.
     *
     * Configurable because the name shows up in the consent dialog, where it is
     * read by people: "mcp" fits a developer install, elsewhere the product name
     * reads better. Falls back to the default when unset or when the value is no
     * valid scope token (RFC 6749 §3.3 allows neither spaces nor quotes).
     */
    public static function fallbackScope(): string
    {
        $configured = trim((string) rex_config::get('ai_platform', self::CONFIG_FALLBACK_SCOPE, ''));

        if ('' !== $configured && 1 === preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $configured)) {
            return $configured;
        }

        return self::SCOPE_FALLBACK_DEFAULT;
    }

    /**
     * The scopes advertised to OAuth clients in discovery and accepted at
     * registration.
     *
     * Clients assemble the authorize URL from this list. When it is empty, some
     * never build that URL at all: the connection dies silently after
     * registration, without the user ever seeing a login page. So an
     * installation that has announced no scopes of its own still gets a neutral
     * marker -- its tools remain reachable either way, because a tool is gated
     * by its own `public` flag and `requiredScopes`, not by this list.
     *
     * @return list<string>
     */
    public static function advertisedScopes(): array
    {
        $scopes = array_keys(self::allScopes());

        return [] === $scopes ? [self::fallbackScope()] : array_values($scopes);
    }

    /**
     * Narrows a client's requested scope string down to what is advertised.
     * An empty or absent request means "everything on offer" -- that is what
     * RFC 7591 §2 leaves to the server, and it keeps clients working that
     * register before reading the metadata.
     *
     * @return list<string>
     */
    public static function filterRequested(string $requested): array
    {
        $advertised = self::advertisedScopes();
        $wanted = array_values(array_filter(preg_split('/\\s+/', trim($requested)) ?: []));

        if ([] === $wanted) {
            return $advertised;
        }

        return array_values(array_intersect($wanted, $advertised));
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

        $scopes = [];

        // No groups, no query: an empty IN () is a syntax error, and there is
        // nothing to look up anyway. The fallback below still applies -- a user
        // without groups is the normal case on installations that do not use
        // them, not a reason to hand back nothing.
        if ([] !== $groupIds) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $sql = rex_sql::factory();
            $sql->setQuery(
                'SELECT scopes FROM ' . rex::getTable('ai_scope_mapping') . ' WHERE ycom_group_id IN (' . $placeholders . ')',
                array_values($groupIds),
            );

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
        }

        if ([] === $scopes && [] === self::allScopes()) {
            // (reached both when the user is in no group at all and when the
            // groups carry no mapping)
            // Nothing configured anywhere, and no addon announced scopes of its
            // own: then the marker from advertisedScopes() is all there is, and
            // withholding it would make the discovery document promise something
            // no one can ever get. Tools stay protected regardless -- each one
            // gates itself through its `public` flag and its own requiredScopes.
            return [self::fallbackScope()];
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
