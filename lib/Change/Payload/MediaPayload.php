<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Payload;

use FriendsOfRedaxo\AiPlatform\Change\AbstractPatchPayload;
use InvalidArgumentException;

use function is_string;

/**
 * A media pool file: title, category, and — for a create — the staged bytes.
 *
 * rex_media_service::updateMedia() reads title and category unconditionally, so
 * the handler fills whichever one was not set from the snapshot — same pattern
 * as the article service.
 *
 * Alt text, copyright and the like are metainfo fields (`med_*`) and belong
 * to MetaPayload.
 *
 * **Replacing the file of an existing entry is still out of scope.** A create
 * adds something that was not there; a replace silently changes what every
 * article already showing that image displays, and the reviewer would be looking
 * at one filename with no way to see the blast radius. That needs its own diff
 * before it deserves an endpoint.
 */
final class MediaPayload extends AbstractPatchPayload
{
    /**
     * Media title. May be cleared with an empty string.
     */
    public function title(?string $title): self
    {
        return $this->put('title', null === $title ? '' : trim($title));
    }

    public function categoryId(int $categoryId): self
    {
        if ($categoryId < 0) {
            throw new InvalidArgumentException('Media category id must not be negative.');
        }

        return $this->put('category_id', $categoryId);
    }

    /**
     * The staged file this proposal wants added, by upload handle.
     *
     * Only meaningful on a create. The bytes are not here and never will be —
     * the handle points at a file under `data/addons/ai_platform/pending/`. See
     * {@see \FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore}.
     */
    public function upload(string $handle): self
    {
        $handle = trim($handle);
        if (1 !== preg_match('/^up_[0-9a-f]{32}$/', $handle)) {
            throw new InvalidArgumentException(sprintf('"%s" is not an upload handle.', $handle));
        }

        return $this->put('upload', $handle);
    }

    public function attachments(): array
    {
        $handle = $this->get('upload');

        return is_string($handle) && '' !== $handle ? [$handle] : [];
    }

    /**
     * @return list<string>
     */
    public static function allFields(): array
    {
        return ['title', 'category_id', 'upload'];
    }
}
