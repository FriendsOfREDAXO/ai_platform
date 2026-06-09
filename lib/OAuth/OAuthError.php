<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\OAuth;

use RuntimeException;

/**
 * Internal exception used inside the token endpoint to carry an OAuth
 * error code + HTTP status across to the JSON response renderer.
 */
final class OAuthError extends RuntimeException
{
    public function __construct(
        public readonly string $oauthError,
        string $description,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($description);
    }
}
