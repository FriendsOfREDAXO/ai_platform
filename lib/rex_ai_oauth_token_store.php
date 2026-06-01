<?php

declare(strict_types=1);

/**
 * Persistence layer for one-time authorization codes and the access/refresh
 * token pairs derived from them.
 *
 * All sensitive material (codes, access tokens, refresh tokens) is hashed
 * with SHA-256 before being written so a database leak does not yield
 * usable bearer tokens. The plaintext is only returned to the caller at
 * issuance time.
 */
final class rex_ai_oauth_token_store
{
    public const TYPE_ACCESS = 'access';
    public const TYPE_REFRESH = 'refresh';

    public const ACCESS_TOKEN_TTL = 3600;        // 1 hour
    public const REFRESH_TOKEN_TTL = 60 * 60 * 24 * 30;  // 30 days
    public const CODE_TTL = 600;                 // 10 minutes

    // ------------------------------------------------------------------
    // Authorization codes
    // ------------------------------------------------------------------

    /**
     * Creates a fresh authorization code bound to a YCom user + client +
     * PKCE challenge. Returns the plain code that must be handed back to
     * the redirect URI exactly once.
     *
     * @param list<string> $scopes
     */
    public static function issueAuthorizationCode(
        string $clientId,
        int $ycomUserId,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $redirectUri,
    ): string {
        $code = self::randomToken();
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_oauth_authorization_code'));
        $sql->setValue('code_hash', self::hash($code));
        $sql->setValue('client_id', $clientId);
        $sql->setValue('ycom_user_id', $ycomUserId);
        $sql->setValue('scopes', json_encode(array_values($scopes), JSON_THROW_ON_ERROR));
        $sql->setValue('code_challenge', $codeChallenge);
        $sql->setValue('code_challenge_method', $codeChallengeMethod);
        $sql->setValue('redirect_uri', $redirectUri);
        $sql->setValue('expires_at', date('Y-m-d H:i:s', time() + self::CODE_TTL));
        $sql->addGlobalCreateFields();
        $sql->insert();

        return $code;
    }

    /**
     * Consumes an authorization code: returns its row exactly once and
     * marks it used. Returns null when the code is unknown, expired or
     * already used.
     *
     * @return array<string, mixed>|null
     */
    public static function consumeAuthorizationCode(string $code): ?array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . rex::getTable('ai_oauth_authorization_code') . ' WHERE code_hash = ?',
            [self::hash($code)],
        );
        if (0 === $sql->getRows()) {
            return null;
        }

        $row = [];
        foreach ($sql->getFieldnames() as $field) {
            $row[$field] = $sql->getValue($field);
        }

        if (null !== $row['used_at']) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $update = rex_sql::factory();
        $update->setQuery(
            'UPDATE ' . rex::getTable('ai_oauth_authorization_code') . ' SET used_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $row['id']],
        );

        $scopes = json_decode((string) $row['scopes'], true);
        $row['scopes'] = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
        $row['id'] = (int) $row['id'];
        $row['ycom_user_id'] = (int) $row['ycom_user_id'];

        return $row;
    }

    // ------------------------------------------------------------------
    // Access + refresh tokens
    // ------------------------------------------------------------------

    /**
     * Issues a brand-new access + refresh token pair.
     *
     * @param list<string> $scopes
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public static function issueTokenPair(string $clientId, int $ycomUserId, array $scopes): array
    {
        $accessToken = self::randomToken();
        $refreshToken = self::randomToken();
        $now = time();

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_oauth_token'));
        $sql->setValue('token_hash', self::hash($accessToken));
        $sql->setValue('type', self::TYPE_ACCESS);
        $sql->setValue('client_id', $clientId);
        $sql->setValue('ycom_user_id', $ycomUserId);
        $sql->setValue('scopes', json_encode(array_values($scopes), JSON_THROW_ON_ERROR));
        $sql->setValue('expires_at', date('Y-m-d H:i:s', $now + self::ACCESS_TOKEN_TTL));
        $sql->addGlobalCreateFields();
        $sql->insert();
        $accessId = (int) $sql->getLastId();

        $refresh = rex_sql::factory();
        $refresh->setTable(rex::getTable('ai_oauth_token'));
        $refresh->setValue('token_hash', self::hash($refreshToken));
        $refresh->setValue('type', self::TYPE_REFRESH);
        $refresh->setValue('client_id', $clientId);
        $refresh->setValue('ycom_user_id', $ycomUserId);
        $refresh->setValue('scopes', json_encode(array_values($scopes), JSON_THROW_ON_ERROR));
        $refresh->setValue('parent_token_id', $accessId);
        $refresh->setValue('expires_at', date('Y-m-d H:i:s', $now + self::REFRESH_TOKEN_TTL));
        $refresh->addGlobalCreateFields();
        $refresh->insert();

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::ACCESS_TOKEN_TTL,
        ];
    }

    /**
     * Looks up an access token. Returns null when the token is unknown,
     * revoked, expired or not of type access.
     *
     * @return array<string, mixed>|null
     */
    public static function findAccessToken(string $accessToken): ?array
    {
        return self::findToken($accessToken, self::TYPE_ACCESS);
    }

    /**
     * Rotates a refresh token: validates it, revokes the old pair, issues
     * a new pair with the same scopes/user/client. Returns null when the
     * refresh token is unknown/expired/revoked.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public static function rotateRefreshToken(string $refreshToken, string $expectedClientId): ?array
    {
        $row = self::findToken($refreshToken, self::TYPE_REFRESH);
        if (null === $row) {
            return null;
        }
        if ($row['client_id'] !== $expectedClientId) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . rex::getTable('ai_oauth_token') . ' SET revoked_at = ? WHERE id = ? OR id = ?',
            [$now, $row['id'], $row['parent_token_id'] ?? 0],
        );

        return self::issueTokenPair(
            (string) $row['client_id'],
            (int) $row['ycom_user_id'],
            $row['scopes'],
        );
    }

    /**
     * Revokes every active token (access + refresh) issued to a given
     * client. Useful when deleting a client in the backend.
     */
    public static function revokeAllForClient(string $clientId): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . rex::getTable('ai_oauth_token') . ' SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
            [date('Y-m-d H:i:s'), $clientId],
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private static function findToken(string $plain, string $type): ?array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . rex::getTable('ai_oauth_token') . ' WHERE token_hash = ? AND type = ?',
            [self::hash($plain), $type],
        );
        if (0 === $sql->getRows()) {
            return null;
        }

        $row = [];
        foreach ($sql->getFieldnames() as $field) {
            $row[$field] = $sql->getValue($field);
        }

        if (null !== $row['revoked_at']) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $scopes = json_decode((string) $row['scopes'], true);
        $row['scopes'] = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
        $row['id'] = (int) $row['id'];
        $row['ycom_user_id'] = (int) $row['ycom_user_id'];
        if (null !== $row['parent_token_id']) {
            $row['parent_token_id'] = (int) $row['parent_token_id'];
        }

        return $row;
    }

    private static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
