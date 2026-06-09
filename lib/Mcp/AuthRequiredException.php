<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use RuntimeException;

/**
 * Thrown by tool dispatch when auth is required. Caught by the server which
 * then emits the 401 + WWW-Authenticate challenge.
 */
final class AuthRequiredException extends RuntimeException
{
}
