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
    /** "Authentifizierung erforderlich" is on: every anonymous request is challenged. */
    public const REASON_SERVER = 'server_requires_auth';

    /** The server lets anonymous clients in, but this tool is not public. */
    public const REASON_TOOL = 'tool_requires_auth';

    public function __construct(
        string $message,
        public readonly string $reason = self::REASON_TOOL,
    ) {
        parent::__construct($message);
    }
}
