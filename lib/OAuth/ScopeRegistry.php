<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\OAuth;

use rex;
use rex_config;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use rex_sql;
use rex_ycom_user;

use function count;
use function is_array;
use function is_string;

use const JSON_THROW_ON_ERROR;

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

    /** Config key holding the sentence shown next to it in the consent dialog. */
    public const CONFIG_FALLBACK_DESCRIPTION = 'mcp_fallback_scope_description';

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
     * Configurable for the rare case that `mcp` collides with something an
     * installation already uses. **What a person reads in the consent dialog is
     * not this** — that is `fallbackScopeDescription()`, which has no character
     * restrictions, because it never travels over the protocol. Keeping the two
     * apart is the point: this one is an identifier, that one is a sentence.
     *
     * Falls back to the default when unset or when the value is no valid scope
     * token. The backend refuses an invalid value rather than storing it, so
     * reaching that fallback means the configuration predates the check.
     */
    public static function fallbackScope(): string
    {
        $configured = trim((string) rex_config::get('ai_platform', self::CONFIG_FALLBACK_SCOPE, ''));

        return self::isValidScopeToken($configured) ? $configured : self::SCOPE_FALLBACK_DEFAULT;
    }

    /**
     * Whether a string may be used as an OAuth scope.
     *
     * RFC 6749 §3.3 defines `scope` as a **space-delimited list**, so a value
     * containing a space would be two scopes rather than one with a space in it;
     * `"` and `\` are out because the value travels through JSON and headers.
     * What is left is printable ASCII — which also rules out umlauts, the part
     * that surprises people most and the reason the field says so.
     *
     *     scope-token = 1*( %x21 / %x23-5B / %x5D-7E )
     */
    public static function isValidScopeToken(string $value): bool
    {
        return '' !== $value && 1 === preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $value);
    }

    /**
     * The sentence shown beside the fallback scope in the consent dialog.
     *
     * This is what an installation actually wants to word for itself: the dialog
     * asks a person to hand access to an application, and "mcp" alone says
     * nothing about what that means. Spaces, umlauts and punctuation are all
     * fine here — the text is rendered, never sent as a scope.
     */
    public static function fallbackScopeDescription(): string
    {
        $configured = trim((string) rex_config::get('ai_platform', self::CONFIG_FALLBACK_DESCRIPTION, ''));

        return '' !== $configured ? $configured : rex_i18n::rawMsg('ai_platform_oauth_fallback_scope_description_default');
    }

    /**
     * Scope => description for display, including the fallback.
     *
     * Separate from {@see allScopes()} on purpose. That one answers "which
     * scopes can be assigned to a group", and the fallback is not among them —
     * it is what applies when nothing was assigned. This one answers "what do I
     * show the person deciding", and there the fallback has to carry its
     * description, or the dialog shows a bare identifier and nothing else.
     *
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        $scopes = self::allScopes();

        return [] === $scopes
            ? [self::fallbackScope() => self::fallbackScopeDescription()]
            : $scopes;
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

        return [] === $scopes ? [self::fallbackScope()] : $scopes;
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
