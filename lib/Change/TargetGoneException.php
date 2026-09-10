<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use RuntimeException;

/**
 * The target of an update or delete no longer exists.
 *
 * Distinct from a stale target: there is nothing left to compare or write,
 * so the request is marked expired rather than offered for override.
 */
final class TargetGoneException extends RuntimeException
{
}
