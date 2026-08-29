<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use RuntimeException;

/**
 * The target changed between the request being proposed and being applied.
 *
 * This is the guard against silently overwriting a human edit: a proposal
 * made on Monday and approved on Wednesday must not wipe out Tuesday's
 * correction. The service compares the fingerprint recorded at proposal
 * time against the current state and raises this before any write happens.
 *
 * A reviewer can override deliberately — the three-column diff (base /
 * proposed / current) exists exactly so that decision is an informed one.
 */
final class StaleTargetException extends RuntimeException
{
    public function __construct(
        private readonly string $expectedHash,
        private readonly string $actualHash,
        string $message = 'The target was modified after this change request was proposed.',
    ) {
        parent::__construct($message);
    }

    public function getExpectedHash(): string
    {
        return $this->expectedHash;
    }

    public function getActualHash(): string
    {
        return $this->actualHash;
    }
}
