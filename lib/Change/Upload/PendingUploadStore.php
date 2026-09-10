<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Upload;

use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use rex;
use rex_dir;
use rex_file;
use rex_i18n;
use rex_media;
use rex_mediapool;
use rex_path;
use rex_sql;
use Throwable;

use function is_file;
use function strlen;

/**
 * Staging for files offered to the media pool.
 *
 * ## Why a staging area at all
 *
 * A change request must not write, and putting the file in the media pool *is*
 * the write. So the bytes have to wait somewhere until a reviewer decides. They
 * wait in `data/addons/ai_platform/pending/`, which `redaxo/data/.htaccess`
 * denies to the web — the staging directory must never become an open file host.
 * The reviewer sees the file through a backend endpoint that checks the
 * `ai_changes[]` permission, never through a public URL.
 *
 * ## Why the bytes are not in the payload
 *
 * Base64 in the JSON would put a 4 MB photo into the `payload` column as ~5.5 MB
 * of text — the column the thinning cronjob exists to keep small, and the one the
 * diff renderer reads. The payload carries a handle; the file carries itself.
 *
 * ## Validation happens here, at upload time
 *
 * `rex_media_service::addMedia()` validates again at approval, and that is the
 * check that actually protects the media pool. But an agent that learns at
 * approval time — hours later, from an editor — that its file type was never
 * allowed cannot do anything with that. So the same core rules run on the way in:
 * `rex_mediapool::isAllowedExtension()` (which also catches double extensions
 * like `x.php.jpg`) and `isAllowedMimeType()`, which compares the real mime type
 * against the name.
 *
 * SVG is allowed, deliberately, as in the media pool itself. It is worth knowing
 * where that risk sits: the backend preview renders it inside an `<img>` tag,
 * where scripts in an SVG do not execute — that is the tag's semantics, not luck.
 * Once approved, though, the file is delivered from `/media/` on the **frontend**,
 * where a backend CSP does not apply. The channel adds no capability an admin did
 * not already have through the media pool; what it adds is that a machine may
 * *propose* one, and a person still decides.
 */
final class PendingUploadStore
{
    /** How long a reservation survives without a proposal referencing it. */
    public const RESERVATION_HOURS = 24;

    /** Hard ceiling, independent of `upload_max_filesize`. */
    public const MAX_BYTES = 32 * 1024 * 1024;

    private function table(): string
    {
        return rex::getTable('ai_change_upload');
    }

    public static function directory(): string
    {
        return rex_path::addonData('ai_platform', 'pending/');
    }

    /**
     * Takes a file that is already on disk and stages it.
     *
     * @param string $sourcePath Absolute path — an upload tmp file, or any file
     *                           a PHP caller already has
     * @param string $originalName The name the caller wants in the media pool
     * @param bool $move Whether the source may be moved (true for tmp uploads)
     *
     * @throws ValidationException when the file is not acceptable
     */
    public function stage(string $sourcePath, string $originalName, Source $source, bool $move = true): PendingUpload
    {
        if (!is_file($sourcePath)) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_upload_err_missing'));
        }

        $bytes = (int) filesize($sourcePath);
        if ($bytes < 1) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_upload_err_empty'));
        }
        if ($bytes > self::MAX_BYTES) {
            throw new ValidationException(rex_i18n::rawMsg(
                'ai_platform_change_upload_err_too_big',
                round(self::MAX_BYTES / 1024 / 1024),
            ));
        }

        // The same two checks the media pool runs, run here so the answer is
        // immediate instead of arriving via an editor hours later.
        if (!rex_mediapool::isAllowedExtension($originalName)) {
            throw new ValidationException(rex_i18n::rawMsg(
                'ai_platform_change_upload_err_extension',
                rex_file::extension($originalName),
            ));
        }
        if (!rex_mediapool::isAllowedMimeType($sourcePath, $originalName)) {
            throw new ValidationException(rex_i18n::rawMsg(
                'ai_platform_change_upload_err_mime',
                rex_file::mimeType($sourcePath),
                rex_file::extension($originalName),
            ));
        }

        // Subindexing OFF on purpose. `rex_mediapool::filename()` would turn a
        // collision into `bulli_2.jpg` at approval time, and a name the caller
        // cannot predict is a name it cannot reference from a slice proposal.
        // Better to refuse now and let the caller pick another.
        $filename = rex_mediapool::filename($originalName, false);

        if (null !== rex_media::get($filename)) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_upload_err_taken', $filename));
        }

        $extension = strtolower(rex_file::extension($filename));
        $handle = 'up_' . bin2hex(random_bytes(16));

        rex_dir::create(self::directory());
        $target = self::directory() . $handle . '.' . $extension;

        if ($move ? !rex_file::move($sourcePath, $target) : !rex_file::copy($sourcePath, $target)) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_upload_err_store'));
        }

        [$width, $height] = self::dimensions($target);

        $sql = rex_sql::factory();
        $sql->setTable($this->table());
        $sql->setValue('handle', $handle);
        $sql->setValue('filename', $filename);
        $sql->setValue('original_name', $originalName);
        $sql->setValue('extension', $extension);
        $sql->setValue('mime', (string) rex_file::mimeType($target));
        $sql->setValue('bytes', $bytes);
        $sql->setValue('width', $width);
        $sql->setValue('height', $height);
        $sql->setValue('source_channel', $source->channel);
        $sql->setValue('source_key', $source->key);
        $sql->setValue('source_label', $source->label);
        $sql->setValue('expires_at', date('Y-m-d H:i:s', time() + self::RESERVATION_HOURS * 3600));
        $sql->addGlobalCreateFields('ai_platform');
        $sql->addGlobalUpdateFields('ai_platform');

        try {
            $sql->insert();
        } catch (Throwable $e) {
            // The unique index on `filename` is what actually settles a race
            // between two callers claiming the same name; the rex_media check
            // above only covers names already in the pool.
            rex_file::delete($target);

            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_upload_err_taken', $filename));
        }

        $upload = $this->findByHandle($handle);
        if (null === $upload) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_upload_err_store'));
        }

        return $upload;
    }

    public function findByHandle(string $handle): ?PendingUpload
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->table() . ' WHERE handle = :h LIMIT 1',
            [':h' => $handle],
        );

        return [] === $rows ? null : self::hydrate($rows[0]);
    }

    /**
     * By the media pool name the upload reserved.
     *
     * Used by SliceHandler to tell "the editor deleted this file" from "the file
     * is waiting in a proposal you have not had approved yet" — two situations
     * that look identical from `rex_media::get()` and need opposite advice.
     */
    public function findByFilename(string $filename): ?PendingUpload
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->table() . ' WHERE filename = :f LIMIT 1',
            [':f' => $filename],
        );

        return [] === $rows ? null : self::hydrate($rows[0]);
    }

    public function findByRequest(int $requestId): ?PendingUpload
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->table() . ' WHERE request_id = :r ORDER BY id LIMIT 1',
            [':r' => $requestId],
        );

        return [] === $rows ? null : self::hydrate($rows[0]);
    }

    /**
     * Links staged files to the request that references them.
     *
     * Called by ChangeService right after a request is stored, from
     * `PayloadInterface::attachments()`. Until this runs an upload looks
     * abandoned, which is exactly what it is.
     *
     * @param list<string> $handles
     */
    public function attach(array $handles, int $requestId): void
    {
        if ([] === $handles) {
            return;
        }

        $sql = rex_sql::factory();
        foreach ($handles as $handle) {
            $sql->setQuery(
                'UPDATE ' . $this->table() . ' SET request_id = :r, expires_at = NULL WHERE handle = :h',
                [':r' => $requestId, ':h' => $handle],
            );
        }
    }

    /** Marks the moment the media pool file was created. */
    public function markConsumed(string $handle): void
    {
        rex_sql::factory()->setQuery(
            'UPDATE ' . $this->table() . ' SET consumed_at = NOW() WHERE handle = :h',
            [':h' => $handle],
        );
    }

    /**
     * Deletes the staged file and its row.
     *
     * The row goes too, unlike a change request: the request is the record of
     * who proposed what, this is only a parking slot. Keeping an empty slot
     * would also keep the filename reserved forever.
     */
    public function discard(string $handle): void
    {
        $upload = $this->findByHandle($handle);
        if (null === $upload) {
            return;
        }

        rex_file::delete($upload->path());
        rex_sql::factory()->setQuery(
            'DELETE FROM ' . $this->table() . ' WHERE handle = :h',
            [':h' => $handle],
        );
    }

    /**
     * Uploads nobody ever referenced, past their reservation.
     *
     * An agent that uploads and then crashes leaves bytes behind and a filename
     * blocked. This is the only sweep with a short fuse — everything attached to
     * a request follows that request's fate.
     *
     * @return list<PendingUpload>
     */
    public function findAbandoned(): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->table()
            . ' WHERE request_id IS NULL AND expires_at IS NOT NULL AND expires_at < NOW()',
        );

        return array_map(static fn(array $row): PendingUpload => self::hydrate($row), $rows);
    }

    /**
     * Staged files belonging to requests that were decided before the cutoff.
     *
     * Same period as the payload thinning, and for the same reason: the decision
     * has been made, the bytes are only volume now. A pending request keeps its
     * file however old it is — a proposal whose image has been deleted is not a
     * saving, it is a broken proposal.
     *
     * @return list<PendingUpload>
     */
    public function findDecidedBefore(int $days): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT u.* FROM ' . $this->table() . ' u'
            . ' INNER JOIN ' . rex::getTable('ai_change_request') . ' r ON r.id = u.request_id'
            . ' WHERE r.status NOT IN (:pending, :approved)'
            . ' AND r.createdate < DATE_SUB(NOW(), INTERVAL ' . max(1, $days) . ' DAY)',
            [':pending' => 'pending', ':approved' => 'approved'],
        );

        return array_map(static fn(array $row): PendingUpload => self::hydrate($row), $rows);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private static function dimensions(string $path): array
    {
        try {
            $size = @getimagesize($path);
        } catch (Throwable) {
            return [null, null];
        }

        if (false === $size) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): PendingUpload
    {
        return new PendingUpload(
            id: (int) $row['id'],
            handle: (string) $row['handle'],
            filename: (string) $row['filename'],
            originalName: (string) $row['original_name'],
            extension: (string) $row['extension'],
            mime: (string) $row['mime'],
            bytes: (int) $row['bytes'],
            width: null === $row['width'] ? null : (int) $row['width'],
            height: null === $row['height'] ? null : (int) $row['height'],
            sourceKey: (string) $row['source_key'],
            requestId: null === $row['request_id'] ? null : (int) $row['request_id'],
            expiresAt: null === $row['expires_at'] ? null : (string) $row['expires_at'],
            consumedAt: null === $row['consumed_at'] ? null : (string) $row['consumed_at'],
        );
    }
}
