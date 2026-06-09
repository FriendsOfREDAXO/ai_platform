<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\OAuth;

use InvalidArgumentException;
use rex;
use rex_config;
use rex_sql;

/**
 * Persistence layer for OAuth 2.1 clients (rex_ai_oauth_client).
 *
 * Two client types are supported:
 *   - public:        no client_secret, must use PKCE (used by mcp-remote etc.)
 *   - confidential:  client_secret stored as a password_hash, used for
 *                    server-to-server flows
 *
 * Clients can be created manually via the backend or dynamically via the
 * /oauth/register endpoint (Dynamic Client Registration, RFC 7591). The
 * `created_by_dcr` flag distinguishes those two paths so the backend list
 * can highlight which clients were registered automatically.
 */
final class ClientStore
{
    public const TYPE_PUBLIC = 'public';
    public const TYPE_CONFIDENTIAL = 'confidential';

    /**
     * @param list<string> $redirectUris
     * @return array{client_id: string, client_secret: ?string} The secret is
     *                                                          returned only
     *                                                          once at creation
     *                                                          time and never
     *                                                          stored in plain.
     */
    public static function create(
        string $clientName,
        array $redirectUris,
        string $type = self::TYPE_PUBLIC,
        bool $createdByDcr = false,
    ): array {
        if (self::TYPE_PUBLIC !== $type && self::TYPE_CONFIDENTIAL !== $type) {
            throw new InvalidArgumentException('Invalid client type: ' . $type);
        }

        $clientId = self::generateClientId();
        $clientSecret = null;
        $clientSecretHash = null;
        if (self::TYPE_CONFIDENTIAL === $type) {
            $clientSecret = bin2hex(random_bytes(32));
            $clientSecretHash = password_hash($clientSecret, PASSWORD_DEFAULT);
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_oauth_client'));
        $sql->setValue('client_id', $clientId);
        $sql->setValue('client_secret_hash', $clientSecretHash);
        $sql->setValue('client_name', $clientName);
        $sql->setValue('redirect_uris', json_encode(array_values($redirectUris), JSON_THROW_ON_ERROR));
        $sql->setValue('type', $type);
        $sql->setValue('created_by_dcr', $createdByDcr ? 1 : 0);
        $sql->addGlobalCreateFields();
        $sql->insert();

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByClientId(string $clientId): ?array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . rex::getTable('ai_oauth_client') . ' WHERE client_id = ?',
            [$clientId],
        );
        if (0 === $sql->getRows()) {
            return null;
        }
        return self::hydrate($sql);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findAll(): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . rex::getTable('ai_oauth_client') . ' ORDER BY id DESC',
        );
        $clients = [];
        foreach ($sql as $row) {
            $clients[] = self::hydrate($row);
        }
        return $clients;
    }

    public static function deleteById(int $id): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . rex::getTable('ai_oauth_client') . ' WHERE id = ?',
            [$id],
        );
    }

    public static function markUsed(string $clientId): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . rex::getTable('ai_oauth_client') . ' SET last_used_at = ? WHERE client_id = ?',
            [date('Y-m-d H:i:s'), $clientId],
        );
    }

    /**
     * Configured client lifetime in days. 0 (default) = clients never expire.
     */
    public static function clientLifetimeDays(): int
    {
        return max(0, (int) rex_config::get('ai_platform', 'oauth_client_lifetime_days', 0));
    }

    /**
     * Whether a client registration has expired, based on its createdate and
     * the configured lifetime. False when the lifetime is 0 (never expires)
     * or the createdate is missing/unparseable.
     *
     * @param array<string, mixed> $client
     */
    public static function isExpired(array $client): bool
    {
        $expiry = self::expiryTimestamp($client);
        return null !== $expiry && time() > $expiry;
    }

    /**
     * Expiry datetime string ("Y-m-d H:i:s"), or null when the client never
     * expires / has no usable createdate.
     *
     * @param array<string, mixed> $client
     */
    public static function expiresAt(array $client): ?string
    {
        $expiry = self::expiryTimestamp($client);
        return null === $expiry ? null : date('Y-m-d H:i:s', $expiry);
    }

    /**
     * @param array<string, mixed> $client
     */
    private static function expiryTimestamp(array $client): ?int
    {
        $days = self::clientLifetimeDays();
        if ($days <= 0) {
            return null;
        }
        $created = (string) ($client['createdate'] ?? '');
        $ts = '' === $created ? false : strtotime($created);
        if (false === $ts) {
            return null;
        }
        return $ts + $days * 86400;
    }

    public static function verifySecret(string $clientId, string $providedSecret): bool
    {
        $client = self::findByClientId($clientId);
        if (null === $client || self::TYPE_CONFIDENTIAL !== $client['type']) {
            return false;
        }
        $hash = (string) $client['client_secret_hash'];
        if ('' === $hash) {
            return false;
        }
        return password_verify($providedSecret, $hash);
    }

    /**
     * @param array<string, mixed> $client
     */
    public static function redirectUriMatches(array $client, string $redirectUri): bool
    {
        $uris = $client['redirect_uris'] ?? [];
        if (!is_array($uris)) {
            return false;
        }
        return in_array($redirectUri, $uris, true);
    }

    private static function generateClientId(): string
    {
        return 'ai_' . bin2hex(random_bytes(16));
    }

    /**
     * @return array<string, mixed>
     */
    private static function hydrate(rex_sql $sql): array
    {
        $row = [];
        foreach ($sql->getFieldnames() as $field) {
            $row[$field] = $sql->getValue($field);
        }
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['created_by_dcr'] = (bool) ($row['created_by_dcr'] ?? false);

        $uris = json_decode((string) ($row['redirect_uris'] ?? '[]'), true);
        $row['redirect_uris'] = is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];

        return $row;
    }
}
