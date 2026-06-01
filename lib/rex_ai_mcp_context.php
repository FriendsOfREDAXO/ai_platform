<?php

declare(strict_types=1);

/**
 * Auth context passed to MCP tool handlers.
 *
 * Tools can inspect who is calling them and which scopes are granted.
 * For anonymous (public) calls, isAuthenticated() returns false and the
 * scope list is empty.
 */
final class rex_ai_mcp_context
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly ?int $ycomUserId = null,
        private readonly array $scopes = [],
        private readonly string $authMode = 'anonymous',
        private readonly ?string $clientId = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self();
    }

    public function isAuthenticated(): bool
    {
        return null !== $this->ycomUserId;
    }

    public function getYcomUserId(): ?int
    {
        return $this->ycomUserId;
    }

    /**
     * Returns the YCom user if available. Returns null in anonymous mode or
     * when YCom is not installed.
     */
    public function getYcomUser(): ?rex_ycom_user
    {
        if (null === $this->ycomUserId) {
            return null;
        }
        if (!class_exists('rex_ycom_user')) {
            return null;
        }
        return rex_ycom_user::get($this->ycomUserId);
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @param list<string> $required
     */
    public function hasAllScopes(array $required): bool
    {
        foreach ($required as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }
        return true;
    }

    public function getAuthMode(): string
    {
        return $this->authMode;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }
}
