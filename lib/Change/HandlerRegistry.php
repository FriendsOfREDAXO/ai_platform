<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use rex_extension;
use rex_extension_point;
use RuntimeException;

use function is_string;

/**
 * Collects change handlers via the AI_PLATFORM_CHANGE_HANDLERS extension
 * point — the same pattern the MCP tool registry uses.
 *
 * The addon registers its six built-in handlers in boot.php; other addons
 * append theirs:
 *
 *   rex_extension::register('AI_PLATFORM_CHANGE_HANDLERS', function ($ep) {
 *       $handlers = $ep->getSubject();
 *       $handlers['my_type'] = new MyHandler();
 *       return $handlers;
 *   });
 */
final class HandlerRegistry
{
    /** @var array<string, HandlerInterface>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, HandlerInterface>
     */
    public static function all(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        /** @var array<mixed> $raw */
        $raw = rex_extension::registerPoint(new rex_extension_point('AI_PLATFORM_CHANGE_HANDLERS', []));

        $handlers = [];
        foreach ($raw as $key => $handler) {
            if (!$handler instanceof HandlerInterface) {
                continue;
            }
            // The handler's own type wins over the array key, so a typo in
            // the key cannot make a handler unreachable by its type.
            $type = $handler->getType();
            if (!is_string($key) || $key !== $type) {
                $handlers[$type] = $handler;
                continue;
            }
            $handlers[$type] = $handler;
        }

        return self::$cache = $handlers;
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function get(string $type): ?HandlerInterface
    {
        return self::all()[$type] ?? null;
    }

    /**
     * @throws RuntimeException when no handler is registered for the type
     */
    public static function require(string $type): HandlerInterface
    {
        $handler = self::get($type);
        if (null === $handler) {
            throw new RuntimeException(sprintf(
                'No change handler registered for type "%s". Registered types: %s',
                $type,
                implode(', ', array_keys(self::all())) ?: '(none)',
            ));
        }

        return $handler;
    }

    /**
     * Resolves the handler responsible for a target.
     */
    public static function forTarget(TargetInterface $target): HandlerInterface
    {
        return self::require($target::changeType());
    }

    /**
     * Type => label, for filters and select boxes.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::all() as $type => $handler) {
            $labels[$type] = $handler->getLabel();
        }

        return $labels;
    }

    /**
     * The extension point is evaluated once per request.
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
