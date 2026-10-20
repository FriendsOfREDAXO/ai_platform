<?php

/**
 * The fallback scope and the sentence shown beside it.
 *
 * Two things are tangled here that look like one field, and the test keeps them
 * apart because an installation got them confused in practice:
 *
 * - The **scope** is an identifier that travels over the protocol. RFC 6749 §3.3
 *   defines `scope` as a space-delimited list, so a value with a space in it
 *   would be two scopes; `"` and `\` are out because it also rides through JSON
 *   and headers, and what remains is printable ASCII — umlauts included in the
 *   exclusion, which is the part nobody expects.
 * - The **description** is a sentence a person reads in the consent dialog. It
 *   is never sent anywhere, so nothing is forbidden in it.
 *
 * Before this, entering "Zugriff für Agenten" as the scope was stored unchecked
 * and quietly replaced by "mcp" on every read — the form then showed "mcp" and
 * the configured value was never used nor mentioned again.
 *
 * Writes config and restores whatever it found, including when an assertion
 * throws.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry;

require __DIR__ . '/bootstrap.php';

$t = new AiTestRunner('Fallback scope');

$originalScope = rex_config::get('ai_platform', ScopeRegistry::CONFIG_FALLBACK_SCOPE, '');
$originalDescription = rex_config::get('ai_platform', ScopeRegistry::CONFIG_FALLBACK_DESCRIPTION, '');

$restore = static function () use ($originalScope, $originalDescription): void {
    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_SCOPE, $originalScope);
    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_DESCRIPTION, $originalDescription);
};
register_shutdown_function($restore);

try {
    // -----------------------------------------------------------------------
    $t->section('What may be a scope');

    foreach (['mcp', 'lernidee', 'Lernidee-Zugriff', 'lernidee:tools', 'a.b_c-1'] as $valid) {
        $t->assert(ScopeRegistry::isValidScopeToken($valid), '"' . $valid . '" is a scope token');
    }

    // The four shapes that actually get typed and cannot work.
    $t->assert(!ScopeRegistry::isValidScopeToken('Lernidee Zugriff'), 'a space is refused — scopes are space-separated');
    $t->assert(!ScopeRegistry::isValidScopeToken('Zugänge'), 'an umlaut is refused, which is the surprising one');
    $t->assert(!ScopeRegistry::isValidScopeToken('mein"scope'), 'a quote is refused');
    $t->assert(!ScopeRegistry::isValidScopeToken('mein\\scope'), 'a backslash is refused');
    $t->assert(!ScopeRegistry::isValidScopeToken(''), 'empty is not a scope either');

    // -----------------------------------------------------------------------
    $t->section('A stored value only applies when it is one');

    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_SCOPE, 'lernidee');
    $t->assertSame('lernidee', ScopeRegistry::fallbackScope(), 'a valid name is used');

    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_SCOPE, '');
    $t->assertSame('mcp', ScopeRegistry::fallbackScope(), 'no name falls back to the default');

    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_SCOPE, 'Lernidee Zugriff');
    $t->assertSame('mcp', ScopeRegistry::fallbackScope(), 'a value that is no scope token falls back too');

    // -----------------------------------------------------------------------
    $t->section('The description is free text, and reaches the dialog');

    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_SCOPE, '');
    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_DESCRIPTION, '');
    $default = ScopeRegistry::fallbackScopeDescription();
    $t->assert('' !== $default, 'there is a default sentence');
    $t->assert(!str_contains($default, '[translate:'), 'and it is translated, not a raw key');

    // Exactly what the earlier field could not hold: spaces, umlauts, punctuation.
    $own = 'Zugriff auf die Inhalte für Agenten – lesend.';
    rex_config::set('ai_platform', ScopeRegistry::CONFIG_FALLBACK_DESCRIPTION, $own);
    $t->assertSame($own, ScopeRegistry::fallbackScopeDescription(), 'a sentence with spaces and umlauts survives');

    // The consent dialog reads descriptions(), and the fallback has to be in it —
    // allScopes() does not contain it, because it is not assignable to a group.
    //
    // Both branches are forced through the extension point rather than taken as
    // the installation happens to be configured. On a machine where an addon
    // announces scopes, the fallback branch would otherwise never run — and that
    // is the branch this whole feature exists for. Handlers run in registration
    // order and the last return value wins, so registering one here decides it.
    rex_extension::register('AI_PLATFORM_OAUTH_SCOPES', static fn (): array => []);

    $t->assertSame([], ScopeRegistry::allScopes(), 'no addon announces scopes now');
    $t->assertSame(
        [ScopeRegistry::fallbackScope() => $own],
        ScopeRegistry::descriptions(),
        'the consent dialog shows the fallback with its sentence',
    );
    $t->assertSame(
        [ScopeRegistry::fallbackScope()],
        ScopeRegistry::advertisedScopes(),
        'and discovery announces it, so clients do not give up on an empty list',
    );

    // With scopes of its own, the fallback has no business anywhere.
    $announced = ['demo:read' => 'Beispiel: lesen'];
    rex_extension::register('AI_PLATFORM_OAUTH_SCOPES', static fn (): array => $announced);

    $t->assertSame($announced, ScopeRegistry::descriptions(), 'announced scopes are shown unchanged');
    $t->assert(
        !array_key_exists(ScopeRegistry::fallbackScope(), ScopeRegistry::descriptions()),
        'and the fallback stays out of the dialog',
    );
    $t->assertSame(['demo:read'], ScopeRegistry::advertisedScopes(), 'discovery announces the real ones');
} finally {
    $restore();
}

$t->assertSame($originalScope, rex_config::get('ai_platform', ScopeRegistry::CONFIG_FALLBACK_SCOPE, ''), 'configuration restored');

exit($t->summary());
