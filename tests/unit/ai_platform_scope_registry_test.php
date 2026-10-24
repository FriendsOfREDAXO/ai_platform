<?php

use FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which strings may travel as an OAuth scope (RFC 6749 §3.3).
 *
 * @internal
 */
final class ai_platform_scope_registry_test extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validTokens(): iterable
    {
        yield 'default' => ['mcp'];
        yield 'with colon' => ['lernidee:tools'];
        yield 'with dash and capitals' => ['Lernidee-Zugriff'];
        yield 'with dot and underscore' => ['a.b_c-1'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        // Scopes are space-delimited, so a space makes two of them.
        yield 'space' => ['Lernidee Zugriff'];
        // Only printable ASCII is allowed: the one nobody expects.
        yield 'umlaut' => ['Zugänge'];
        yield 'quote' => ['mein"scope'];
        yield 'backslash' => ['mein\\scope'];
        yield 'empty' => [''];
    }

    #[DataProvider('validTokens')]
    public function testValidToken(string $token): void
    {
        self::assertTrue(ScopeRegistry::isValidScopeToken($token));
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidToken(string $token): void
    {
        self::assertFalse(ScopeRegistry::isValidScopeToken($token));
    }
}
