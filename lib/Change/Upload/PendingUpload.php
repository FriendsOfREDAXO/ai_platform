<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Upload;

use rex_path;

use function is_file;

/**
 * One staged file: bytes offered for the media pool, not in it yet.
 *
 * The `filename` is **reserved**, not proposed. That distinction is the whole
 * reason this record exists — see the table comment in `install.php`.
 */
final class PendingUpload
{
    public function __construct(
        public readonly int $id,
        public readonly string $handle,
        public readonly string $filename,
        public readonly string $originalName,
        public readonly string $extension,
        public readonly string $mime,
        public readonly int $bytes,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly string $sourceKey,
        public readonly ?int $requestId,
        public readonly ?string $expiresAt,
        public readonly ?string $consumedAt,
    ) {
    }

    /** Absolute path of the staged file. */
    public function path(): string
    {
        return rex_path::addonData('ai_platform', 'pending/' . $this->handle . '.' . $this->extension);
    }

    public function fileExists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Whether a browser can show this inline. Everything else gets a link —
     * a PDF is worth offering, it is just not worth embedding in an inbox row.
     */
    public function isDisplayableImage(): bool
    {
        return in_array($this->mime, [
            'image/jpeg',
            'image/pjpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
            'image/svg+xml',
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'upload' => $this->handle,
            'filename' => $this->filename,
            'original_name' => $this->originalName,
            'mime' => $this->mime,
            'bytes' => $this->bytes,
            'width' => $this->width,
            'height' => $this->height,
            'expires_at' => $this->expiresAt,
        ];
    }
}
