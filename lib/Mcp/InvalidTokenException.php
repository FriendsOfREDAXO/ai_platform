<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use RuntimeException;

/**
 * Thrown by the authenticator when the client sent a Bearer token that
 * does not resolve to a valid access token. The server catches this and
 * emits a 401 + WWW-Authenticate response per RFC 6750.
 */
final class InvalidTokenException extends RuntimeException
{
}
