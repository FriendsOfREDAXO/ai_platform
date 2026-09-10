<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Target;

use FriendsOfRedaxo\AiPlatform\Change\AbstractTarget;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use InvalidArgumentException;
use rex_i18n;
use rex_media;
use rex_media_category;
use rex_url;

/**
 * Points at a media pool file — its core fields (title, category) only.
 *
 * Addressed by filename, the way REDAXO and the api addon address media.
 * Descriptive fields such as alt text or copyright are metainfo (`med_*`)
 * and go through {@see MetaTarget::media()}.
 *
 * A create addresses a **place plus a name**: the media category it should land
 * in, and the filename the staged upload reserved. The name is not decided at
 * approval time on purpose — `rex_mediapool::filename()` would subindex a
 * collision into `bild_2.jpg`, and a name the proposing side cannot predict is a
 * name it cannot reference from a slice proposal, since
 * `SliceHandler::checkReferences()` resolves `media1` slots against
 * `rex_media::get()` when the proposal is submitted.
 *
 * The bytes are not here. They are staged under
 * `data/addons/ai_platform/pending/` and the payload carries the handle —
 * see {@see \FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore}.
 */
final class MediaTarget extends AbstractTarget
{
    private function __construct(
        private readonly ChangeOperation $operation,
        private readonly string $filename,
        private readonly ?int $categoryId = null,
    ) {
    }

    public static function changeType(): string
    {
        return 'media';
    }

    public static function existing(string $filename): self
    {
        return new self(ChangeOperation::Update, self::assertFilename($filename));
    }

    public static function forDeletion(string $filename): self
    {
        return new self(ChangeOperation::Delete, self::assertFilename($filename));
    }

    /**
     * A new file in a media category, under the name its upload reserved.
     */
    public static function createIn(int $categoryId, string $filename): self
    {
        if ($categoryId < 0) {
            throw new InvalidArgumentException('Media category id must not be negative.');
        }

        return new self(ChangeOperation::Create, self::assertFilename($filename), $categoryId);
    }

    private static function assertFilename(string $filename): string
    {
        $filename = trim($filename);
        if ('' === $filename) {
            throw new InvalidArgumentException('Media filename must not be empty.');
        }
        // Same character class the api addon allows in its media routes.
        if (1 !== preg_match('/^[a-zA-Z0-9\-_.@]+$/', $filename)) {
            throw new InvalidArgumentException(sprintf('Media filename "%s" contains illegal characters.', $filename));
        }

        return $filename;
    }

    public function operation(): ChangeOperation
    {
        return $this->operation;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function loadMedia(): ?rex_media
    {
        return rex_media::get($this->filename);
    }

    public function toArray(): array
    {
        $data = [
            'operation' => $this->operation->value,
            'filename' => $this->filename,
        ];
        if (null !== $this->categoryId) {
            $data['category_id'] = $this->categoryId;
        }

        return $data;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            ChangeOperation::from(self::requireString($data, 'operation')),
            self::requireString($data, 'filename'),
            isset($data['category_id']) ? (int) $data['category_id'] : null,
        );
    }

    public function describe(): string
    {
        $label = rex_i18n::rawMsg('ai_platform_change_target_media') . ' »' . $this->filename . '«';

        if (ChangeOperation::Create === $this->operation) {
            $category = rex_media_category::get((int) $this->categoryId);

            return $label . ' → ' . (null === $category
                ? rex_i18n::rawMsg('ai_platform_change_media_root')
                : $category->getName());
        }

        return $label;
    }

    public function backendUrl(): ?string
    {
        if (null === $this->loadMedia()) {
            return null;
        }

        return rex_url::backendPage('mediapool/media', ['file_name' => $this->filename]);
    }
}
