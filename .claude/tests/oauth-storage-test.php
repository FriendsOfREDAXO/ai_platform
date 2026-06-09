<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\OAuth\ClientStore;
use FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry;
use FriendsOfRedaxo\AiPlatform\OAuth\TokenStore;

// Boot REDAXO core so rex_sql, rex_config and the addon classmap are available.
$htdocs = dirname(__DIR__, 6);
unset($REX);
$REX['REDAXO'] = true;
$REX['HTDOCS_PATH'] = $htdocs . '/';
$REX['BACKEND_FOLDER'] = 'redaxo';
$REX['LOAD_PAGE'] = false;
chdir($htdocs . '/redaxo');
require $htdocs . '/redaxo/src/core/boot.php';

// Boot addons (registers YOrm model classes, fires PACKAGES_INCLUDED, etc.)
rex_addon::initialize();
foreach (rex::getPackageOrder() as $packageId) {
    rex_package::require($packageId)->boot();
}

$pass = 0;
$fail = 0;
$assert = static function (bool $cond, string $msg) use (&$pass, &$fail): void {
    if ($cond) {
        echo "  OK   $msg\n";
        $pass++;
    } else {
        echo "  FAIL $msg\n";
        $fail++;
    }
};

// Clean DB first
$sql = rex_sql::factory();
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_oauth_token'));
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_oauth_authorization_code'));
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_oauth_client'));
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_scope_mapping'));

echo "\n=== Client store ===\n";

$client = ClientStore::create(
    'Test Public Client',
    ['https://example.org/cb'],
    ClientStore::TYPE_PUBLIC,
    true,
);
$assert(str_starts_with($client['client_id'], 'ai_'), 'public client id prefixed with ai_');
$assert(null === $client['client_secret'], 'public client has no secret');

$confidential = ClientStore::create(
    'Test Confidential Client',
    ['https://example.org/cb2'],
    ClientStore::TYPE_CONFIDENTIAL,
);
$assert(null !== $confidential['client_secret'], 'confidential client receives secret');

$lookup = ClientStore::findByClientId($client['client_id']);
$assert(null !== $lookup, 'findByClientId returns row for known id');
$assert($lookup['client_name'] === 'Test Public Client', 'client_name persisted');
$assert($lookup['type'] === 'public', 'type persisted');
$assert($lookup['redirect_uris'] === ['https://example.org/cb'], 'redirect_uris decoded as list');
$assert(true === $lookup['created_by_dcr'], 'created_by_dcr boolean true persisted');

$assert(ClientStore::redirectUriMatches($lookup, 'https://example.org/cb'), 'redirectUriMatches positive');
$assert(!ClientStore::redirectUriMatches($lookup, 'https://attacker.example/cb'), 'redirectUriMatches negative');

$assert(ClientStore::verifySecret($confidential['client_id'], $confidential['client_secret']), 'verifySecret accepts right secret');
$assert(!ClientStore::verifySecret($confidential['client_id'], 'wrong'), 'verifySecret rejects wrong secret');
$assert(!ClientStore::verifySecret($client['client_id'], 'anything'), 'verifySecret rejects on public client');

$assert(null === ClientStore::findByClientId('ai_does_not_exist'), 'findByClientId returns null for unknown id');

echo "\n=== Authorization code lifecycle ===\n";

$code = TokenStore::issueAuthorizationCode(
    $client['client_id'],
    42,
    ['mcp:tools:read', 'mcp:tools:call'],
    'somechallenge',
    'S256',
    'https://example.org/cb',
);
$assert(64 === strlen($code), 'authorization code is 32 random bytes hex (64 chars)');

$consumed = TokenStore::consumeAuthorizationCode($code);
$assert(null !== $consumed, 'first consume returns row');
$assert($consumed['ycom_user_id'] === 42, 'ycom_user_id round-trips');
$assert($consumed['scopes'] === ['mcp:tools:read', 'mcp:tools:call'], 'scopes round-trip as list');
$assert($consumed['code_challenge'] === 'somechallenge', 'code_challenge persisted');
$assert($consumed['redirect_uri'] === 'https://example.org/cb', 'redirect_uri persisted');

$consumed2 = TokenStore::consumeAuthorizationCode($code);
$assert(null === $consumed2, 'second consume of same code returns null');

$assert(null === TokenStore::consumeAuthorizationCode('does-not-exist'), 'unknown code returns null');

// Force an expired code: insert directly with past expires_at
$expiredCode = 'expired_code_for_test';
$direct = rex_sql::factory();
$direct->setTable(rex::getTable('ai_oauth_authorization_code'));
$direct->setValue('code_hash', hash('sha256', $expiredCode));
$direct->setValue('client_id', $client['client_id']);
$direct->setValue('ycom_user_id', 99);
$direct->setValue('scopes', '[]');
$direct->setValue('code_challenge', 'x');
$direct->setValue('code_challenge_method', 'S256');
$direct->setValue('redirect_uri', 'https://example.org/cb');
$direct->setValue('expires_at', date('Y-m-d H:i:s', time() - 60));
$direct->addGlobalCreateFields();
$direct->insert();
$assert(null === TokenStore::consumeAuthorizationCode($expiredCode), 'expired code returns null');

echo "\n=== Token pair issuance, lookup, rotation ===\n";

$pair = TokenStore::issueTokenPair(
    $client['client_id'],
    42,
    ['mcp:tools:read'],
);
$assert(64 === strlen($pair['access_token']), 'access token is 32 bytes hex');
$assert(64 === strlen($pair['refresh_token']), 'refresh token is 32 bytes hex');
$assert(3600 === $pair['expires_in'], 'expires_in returns ACCESS_TOKEN_TTL');

$found = TokenStore::findAccessToken($pair['access_token']);
$assert(null !== $found, 'findAccessToken locates issued token');
$assert($found['ycom_user_id'] === 42, 'access token bound to ycom_user_id');
$assert($found['scopes'] === ['mcp:tools:read'], 'access token scopes intact');
$assert($found['client_id'] === $client['client_id'], 'access token bound to client_id');

$assert(null === TokenStore::findAccessToken('does-not-exist'), 'unknown access token returns null');
$assert(null === TokenStore::findAccessToken($pair['refresh_token']), 'refresh token NOT accepted as access token');

$rotated = TokenStore::rotateRefreshToken($pair['refresh_token'], $client['client_id']);
$assert(null !== $rotated, 'rotateRefreshToken returns new pair');
$assert($rotated['access_token'] !== $pair['access_token'], 'rotated access token differs');
$assert($rotated['refresh_token'] !== $pair['refresh_token'], 'rotated refresh token differs');

$reuse = TokenStore::rotateRefreshToken($pair['refresh_token'], $client['client_id']);
$assert(null === $reuse, 'reusing old refresh token after rotation returns null');

$oldStillAlive = TokenStore::findAccessToken($pair['access_token']);
$assert(null === $oldStillAlive, 'old access token revoked after refresh rotation');

$wrongClient = TokenStore::rotateRefreshToken($rotated['refresh_token'], 'ai_different_client');
$assert(null === $wrongClient, 'rotateRefreshToken rejects mismatched client_id');

echo "\n=== Scope registry ===\n";

// No built-in scopes by design (since commit 90f99d6): tools are gated solely
// by their `public` flag and own `requiredScopes`. Selectable scopes come from
// consumer addons via AI_PLATFORM_OAUTH_SCOPES, surfaced through allScopes().
$builtIn = ScopeRegistry::builtInScopes();
$assert([] === $builtIn, 'builtInScopes is empty by design (no built-in scopes)');
$assert(is_array(ScopeRegistry::allScopes()), 'allScopes returns an array (built-ins + extension-point scopes)');

ScopeRegistry::setScopesForGroup(7, ['mcp:tools:read', 'mcp:tools:call', 'mcp:tools:read']);
$stored = ScopeRegistry::getScopesForGroup(7);
$assert($stored === ['mcp:tools:read', 'mcp:tools:call'], 'setScopesForGroup deduplicates');

ScopeRegistry::setScopesForGroup(7, ['mcp:tools:read']);
$assert(ScopeRegistry::getScopesForGroup(7) === ['mcp:tools:read'], 'setScopesForGroup updates existing row');

ScopeRegistry::setScopesForGroup(7, []);
$assert(ScopeRegistry::getScopesForGroup(7) === [], 'empty scope list deletes mapping');

$assert(ScopeRegistry::resolveScopesForYcomUser(999999) === [], 'unknown user resolves to empty scope list');

// Cleanup
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_oauth_token'));
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_oauth_authorization_code'));
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_oauth_client'));
$sql->setQuery('DELETE FROM ' . rex::getTable('ai_scope_mapping'));

echo "\n=== Summary ===\n";
echo "  Passed: $pass\n  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
