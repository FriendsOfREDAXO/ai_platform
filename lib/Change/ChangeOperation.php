<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use rex_i18n;

/**
 * The kind of write a change request asks for.
 *
 * The operation is never passed in by a caller — it is derived from the
 * target factory that was used (see {@see TargetInterface::operation()}).
 * That way "create an object that already exists" is not expressible.
 */
enum ChangeOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function label(): string
    {
        return rex_i18n::rawMsg('ai_platform_change_operation_' . $this->value);
    }

    /**
     * Bootstrap CSS class for the badge in the backend list.
     */
    public function cssClass(): string
    {
        return match ($this) {
            self::Create => 'label-success',
            self::Update => 'label-info',
            self::Delete => 'label-danger',
        };
    }

    /**
     * Whether this operation needs an existing target, and therefore a
     * snapshot of the current state to diff against.
     */
    public function needsSnapshot(): bool
    {
        return self::Create !== $this;
    }
}
