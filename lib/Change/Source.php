<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use InvalidArgumentException;
use rex;
use rex_user;
use rex_ycom_user;

use function in_array;
use function is_scalar;

/**
 * Where a change request came from.
 *
 * Authentication is not this object's job. Whether a caller is allowed to
 * submit at all is decided by the adapter it came through — the api addon
 * checks its bearer token, a YCom login checks the group, PHP code inside
 * REDAXO is trusted by definition. Source only records the answer, so a
 * reviewer can see who is asking and so limits and statistics have
 * something stable to group by.
 *
 * `key` is the important field: it is the stable identifier of one
 * submitting party ("ai_content", "agent:alt-text", "api:client-xyz") and
 * drives list filters, the per-source counts and later the acceptance-rate
 * report. Pick it once and keep it.
 */
final class Source
{
    public const CHANNEL_PHP = 'php';
    public const CHANNEL_AGENT = 'agent';
    public const CHANNEL_BACKEND = 'backend';
    public const CHANNEL_API = 'api';
    public const CHANNEL_CUSTOM = 'custom';

    public const USER_REX = 'rex';
    public const USER_YCOM = 'ycom';

    private const CHANNELS = [
        self::CHANNEL_PHP,
        self::CHANNEL_AGENT,
        self::CHANNEL_BACKEND,
        self::CHANNEL_API,
        self::CHANNEL_CUSTOM,
    ];

    /**
     * @param string $channel One of the CHANNEL_* constants
     * @param string $key Stable identifier of the submitting party
     * @param string $label Display name for the backend list
     * @param string|null $userType `rex`, `ycom` or null
     * @param int|null $userId Id of the acting user, if any
     * @param array<string, scalar|null> $meta Free-form context (model, profile id, agent run)
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $key,
        public readonly string $label = '',
        public readonly ?string $userType = null,
        public readonly ?int $userId = null,
        public readonly array $meta = [],
    ) {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown source channel "%s". Expected one of: %s',
                $channel,
                implode(', ', self::CHANNELS),
            ));
        }
        if ('' === trim($key)) {
            throw new InvalidArgumentException('Source key must not be empty — it identifies the submitting party.');
        }
        if (null !== $userType && !in_array($userType, [self::USER_REX, self::USER_YCOM], true)) {
            throw new InvalidArgumentException(sprintf('Unknown user type "%s".', $userType));
        }
    }

    /**
     * Another addon or project code submitting directly.
     *
     * @param array<string, scalar|null> $meta
     */
    public static function php(string $key, string $label = '', ?int $rexUserId = null, array $meta = []): self
    {
        return new self(
            self::CHANNEL_PHP,
            $key,
            $label,
            null === $rexUserId ? null : self::USER_REX,
            $rexUserId,
            $meta,
        );
    }

    /**
     * An agent running inside REDAXO through Service::createAgent().
     *
     * @param array<string, scalar|null> $meta
     */
    public static function agent(string $key, string $label = '', array $meta = []): self
    {
        return new self(self::CHANNEL_AGENT, $key, $label, null, null, $meta);
    }

    /**
     * The backend form in this addon. Records the logged-in editor.
     */
    public static function backend(?rex_user $user = null): self
    {
        $user ??= rex::getUser();

        return new self(
            self::CHANNEL_BACKEND,
            'backend',
            null === $user ? '' : $user->getName(),
            null === $user ? null : self::USER_REX,
            $user?->getId(),
        );
    }

    /**
     * A caller authenticated by an api-addon bearer token.
     *
     * The key is derived from the token id, never from the request body. That
     * is the whole point: `key` drives the inbox filter, the per-source counts
     * and later the acceptance-rate report. A caller free to name its own source
     * hides its own history by inventing a new name, and the origin shown to the
     * reviewer stops meaning anything.
     *
     * The token *name* is only a label. A renamed token keeps its identity and
     * therefore its history.
     *
     * @param int $tokenId Id from the api addon's rex_api_token row
     * @param string $tokenName Display name of that token
     * @param array<string, scalar|null> $meta Free-form context
     */
    public static function apiToken(int $tokenId, string $tokenName = '', array $meta = []): self
    {
        if ($tokenId < 1) {
            throw new InvalidArgumentException('An api token source needs the token id.');
        }

        return new self(
            self::CHANNEL_API,
            'api-token:' . $tokenId,
            '' !== $tokenName ? $tokenName : 'API-Token #' . $tokenId,
            null,
            null,
            $meta,
        );
    }

    /**
     * A custom adapter — an api addon route, an own rex_api_function, an MCP
     * tool a project built on top of ChangeService.
     *
     * @param array<string, scalar|null> $meta
     */
    public static function custom(string $key, string $label = '', ?string $userType = null, ?int $userId = null, array $meta = []): self
    {
        return new self(self::CHANNEL_CUSTOM, $key, $label, $userType, $userId, $meta);
    }

    /**
     * Display name, falling back to the key.
     */
    public function displayName(): string
    {
        return '' !== $this->label ? $this->label : $this->key;
    }

    /**
     * The acting user's login name, if it can still be resolved.
     */
    public function resolveUserName(): ?string
    {
        if (null === $this->userId) {
            return null;
        }

        if (self::USER_REX === $this->userType) {
            return rex_user::get($this->userId)?->getLogin();
        }

        if (self::USER_YCOM === $this->userType && class_exists(rex_ycom_user::class)) {
            $user = rex_ycom_user::get($this->userId);

            return null === $user ? null : (string) $user->getValue('login');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'key' => $this->key,
            'label' => $this->label,
            'user_type' => $this->userType,
            'user_id' => $this->userId,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $meta = $data['meta'] ?? [];
        $cleanMeta = [];
        if (\is_array($meta)) {
            foreach ($meta as $key => $value) {
                if (\is_string($key) && (null === $value || is_scalar($value))) {
                    $cleanMeta[$key] = $value;
                }
            }
        }

        return new self(
            is_scalar($data['channel'] ?? null) ? (string) $data['channel'] : self::CHANNEL_CUSTOM,
            is_scalar($data['key'] ?? null) ? (string) $data['key'] : 'unknown',
            is_scalar($data['label'] ?? null) ? (string) $data['label'] : '',
            is_scalar($data['user_type'] ?? null) ? (string) $data['user_type'] : null,
            isset($data['user_id']) && is_numeric($data['user_id']) ? (int) $data['user_id'] : null,
            $cleanMeta,
        );
    }
}
