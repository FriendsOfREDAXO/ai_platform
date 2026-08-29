<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Handler;

use FriendsOfRedaxo\AiPlatform\Change\AbstractHandler;
use FriendsOfRedaxo\AiPlatform\Change\ApplyResult;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\Payload\MediaPayload;
use FriendsOfRedaxo\AiPlatform\Change\Target\MediaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;
use FriendsOfRedaxo\AiPlatform\Change\TargetInterface;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use rex_i18n;
use rex_media_category;
use rex_path;
use rex_file;
use rex_media_service;
use rex_user;
use Throwable;

use function array_key_exists;
use function is_file;

/**
 * Media pool files — title and category.
 *
 * rex_media_service::updateMedia() reads both keys unconditionally, so a field
 * the proposal left alone is filled from the snapshot. Same pattern as the
 * article service; getting it wrong here would blank a media title whenever
 * someone proposed a category move.
 *
 * Descriptive fields — alt text, copyright — are metainfo (`med_*`) and go
 * through MetaHandler.
 *
 * ## The create
 *
 * A create adds a staged file to the pool. The enabling detail is in the core:
 * `rex_media_service::addMedia()` takes `$data['file']['path']` and falls back to
 * `tmp_name` only if that is missing — it wants a path, not an upload. So the
 * bytes can wait in `data/addons/ai_platform/pending/` until someone decides, and
 * the approval hands over the path. Every check the media pool would run on an
 * interactive upload runs there, at the moment that matters.
 *
 * The filename is fixed when the file is staged, not when it is approved. See
 * MediaTarget for why: an unpredictable name cannot be referenced from a slice
 * proposal, and `checkReferences()` resolves those references at submission time.
 */
final class MediaHandler extends AbstractHandler
{
    public function getType(): string
    {
        return 'media';
    }

    public function targetClass(): string
    {
        return MediaTarget::class;
    }

    public function payloadClass(): string
    {
        return MediaPayload::class;
    }

    public function supportedOperations(): array
    {
        return [ChangeOperation::Create, ChangeOperation::Update, ChangeOperation::Delete];
    }

    public function readCurrent(TargetInterface $target): ?array
    {
        $media = $this->requireTarget($target)->loadMedia();
        if (null === $media) {
            return null;
        }

        return [
            'title' => (string) $media->getTitle(),
            'category_id' => $media->getCategoryId(),
        ];
    }

    /**
     * For a create, the media category it is aimed at.
     *
     * A create has no target of its own to fingerprint, so this is the only thing
     * that can move under it — and it does: a category renamed or deleted between
     * proposal and approval changes where the file lands.
     *
     * @return array<string, mixed>|null
     */
    public function readContext(TargetInterface $target): ?array
    {
        $media = $this->requireTarget($target);

        if (ChangeOperation::Create !== $media->operation()) {
            return null;
        }

        $categoryId = (int) $media->getCategoryId();
        $category = rex_media_category::get($categoryId);

        return [
            'category_id' => $categoryId,
            'category_name' => null === $category ? '' : $category->getName(),
            'filename_taken' => null !== $media->loadMedia(),
        ];
    }

    /**
     * The file behind the record, and the target category of a move.
     *
     * The database row and the file on disk can part ways — someone deletes the
     * file over FTP, or a sync goes wrong. A proposal about a record whose file
     * is gone would "succeed" while the media pool entry stays broken, so it is
     * reported rather than applied.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array
    {
        $target = $this->requireTarget($request->target);
        $problems = [];

        if (ChangeOperation::Create === $request->operation) {
            // A create's references are the staged file and the category. The
            // file can vanish — the cronjob sweeps abandoned uploads, and a
            // proposal whose bytes were swept is not applicable at any price.
            // Not overridable, like every other broken reference: there is no
            // version of "add it anyway" that adds the right file.
            $handles = $request->effectivePayload()->attachments();
            if ([] === $handles) {
                return [rex_i18n::rawMsg('ai_platform_change_ref_upload_missing')];
            }

            $upload = (new PendingUploadStore())->findByHandle($handles[0]);
            if (null === $upload) {
                $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_upload_gone', $handles[0]);
            } elseif (!$upload->fileExists()) {
                $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_upload_file_gone', $upload->filename);
            }

            // Someone uploaded the same name through the media pool in the
            // meantime. The reservation only holds against other proposals.
            if (null !== $target->loadMedia()) {
                $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_upload_taken', $target->getFilename());
            }

            $categoryId = (int) $target->getCategoryId();
            if (0 !== $categoryId && null === rex_media_category::get($categoryId)) {
                $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_media_category_gone', $categoryId);
            }

            return $problems;
        }

        $media = $target->loadMedia();
        if (null === $media) {
            return [rex_i18n::rawMsg('ai_platform_change_err_media_gone', $target->getFilename())];
        }

        if (!is_file(rex_path::media($target->getFilename()))) {
            $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_media_file_missing', $target->getFilename());
        }

        $payload = $request->effectivePayload()->toArray();
        if (array_key_exists('category_id', $payload)) {
            $categoryId = (int) $payload['category_id'];
            if (0 !== $categoryId && null === rex_media_category::get($categoryId)) {
                $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_media_category_gone', $categoryId);
            }
        }

        return $problems;
    }

    public function validate(ChangeRequest $request): void
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();

        self::filterToAllowed($payload, MediaPayload::allFields());

        if (ChangeOperation::Create === $request->operation) {
            if ([] === $request->effectivePayload()->attachments()) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_upload_required'));
            }

            $upload = (new PendingUploadStore())->findByHandle(
                $request->effectivePayload()->attachments()[0],
            );
            if (null === $upload) {
                throw new ValidationException(rex_i18n::rawMsg(
                    'ai_platform_change_ref_upload_gone',
                    $request->effectivePayload()->attachments()[0],
                ));
            }

            // The proposal must name the file the upload reserved. Letting the
            // two drift apart would mean the diff promises one name and the write
            // produces another.
            if ($upload->filename !== $target->getFilename()) {
                throw new ValidationException(rex_i18n::rawMsg(
                    'ai_platform_change_err_upload_filename',
                    $target->getFilename(),
                    $upload->filename,
                ));
            }

            if (null !== $upload->consumedAt) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_upload_consumed', $upload->filename));
            }

            // An upload belongs to whoever staged it. Otherwise one token could
            // attach its bytes to another's proposal, or claim a reservation it
            // never made.
            if ('' !== $upload->sourceKey && $upload->sourceKey !== $request->source->key) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_upload_foreign'));
            }

            $this->assertReferencesResolve($request);

            return;
        }

        if (null === $target->loadMedia()) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_media_gone', $target->getFilename()));
        }

        if (ChangeOperation::Delete === $request->operation) {
            return;
        }

        if ([] === $payload) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        $this->assertReferencesResolve($request);
    }

    public function canApprove(rex_user $user, ChangeRequest $request): bool
    {
        $target = $this->requireTarget($request->target);

        if ($user->isAdmin()) {
            return true;
        }

        $perm = $user->getComplexPerm('media');

        // A create is judged by where it lands; there is no source category yet.
        if (ChangeOperation::Create === $request->operation) {
            return $perm->hasCategoryPerm((int) $target->getCategoryId());
        }

        $media = $target->loadMedia();
        if (null === $media) {
            return false;
        }

        if (!$perm->hasCategoryPerm($media->getCategoryId())) {
            return false;
        }

        // Moving a file needs rights on both sides, not just the source.
        $payload = $request->effectivePayload()->toArray();
        if (array_key_exists('category_id', $payload)) {
            return $perm->hasCategoryPerm((int) $payload['category_id']);
        }

        return true;
    }

    protected function fieldLabel(string $key, ChangeRequest $request): string
    {
        return rex_i18n::rawMsg('ai_platform_change_field_media_' . $key);
    }

    public function apply(ChangeRequest $request): ApplyResult
    {
        $target = $this->requireTarget($request->target);
        $filename = $target->getFilename();

        try {
            if (ChangeOperation::Delete === $request->operation) {
                rex_media_service::deleteMedia($filename);

                return ApplyResult::ok([], [], [rex_i18n::rawMsg('ai_platform_change_media_deleted')]);
            }

            if (ChangeOperation::Create === $request->operation) {
                return $this->applyCreate($request, $target);
            }

            $payload = $request->effectivePayload()->toArray();
            $snapshot = $request->snapshotBefore ?? [];

            // updateMedia() reads both keys without checking isset, so both
            // have to be present.
            $messages = rex_media_service::updateMedia($filename, [
                'title' => (string) ($payload['title'] ?? $snapshot['title'] ?? ''),
                'category_id' => (int) ($payload['category_id'] ?? $snapshot['category_id'] ?? 0),
            ]);
        } catch (Throwable $e) {
            return ApplyResult::failed($e->getMessage());
        }

        return ApplyResult::ok(
            ['filename' => $filename],
            [],
            array_values(array_map('strval', $messages)),
        );
    }

    private function requireTarget(TargetInterface $target): MediaTarget
    {
        if (!$target instanceof MediaTarget) {
            throw new ValidationException(sprintf('MediaHandler needs a MediaTarget, got %s.', $target::class));
        }

        return $target;
    }

    /**
     * Hands the staged file to the media pool.
     *
     * `addMedia()` is given a `path`, which is why staging works at all. Two
     * details that are not decoration:
     *
     * - **`$doSubindexing = false`.** The name was reserved when the file was
     *   staged and the proposal was validated against it. Letting the core
     *   subindex here would produce a file called something other than what the
     *   reviewer approved and what a slice proposal may already reference.
     * - **The file is copied, not moved.** `addMedia()` itself moves the file it
     *   is given into `media/`. If it throws halfway — a mime check, a filesystem
     *   error, an extension refusing the write in `MEDIA_ADDED` — the staged copy
     *   is still there and the request can be retried or rejected with its
     *   evidence intact. A moved-then-failed upload leaves a request pointing at
     *   nothing.
     */
    private function applyCreate(ChangeRequest $request, MediaTarget $target): ApplyResult
    {
        $store = new PendingUploadStore();
        $handles = $request->effectivePayload()->attachments();
        $upload = [] === $handles ? null : $store->findByHandle($handles[0]);

        if (null === $upload || !$upload->fileExists()) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_ref_upload_file_gone', $target->getFilename()));
        }

        $payload = $request->effectivePayload()->toArray();
        $working = rex_path::addonData('ai_platform', 'pending/apply_' . $upload->handle . '.' . $upload->extension);

        if (!rex_file::copy($upload->path(), $working)) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_upload_err_store'));
        }

        try {
            $result = rex_media_service::addMedia([
                'title' => (string) ($payload['title'] ?? ''),
                'category_id' => (int) $target->getCategoryId(),
                'file' => [
                    'name' => $upload->filename,
                    'path' => $working,
                    'error' => 0,
                ],
            ], false);
        } catch (Throwable $e) {
            rex_file::delete($working);

            return ApplyResult::failed($e->getMessage());
        }

        // addMedia() moved the working copy into the pool; if it did not, the
        // leftover would sit in the staging directory forever.
        rex_file::delete($working);

        $stored = (string) ($result['filename'] ?? $upload->filename);
        $store->markConsumed($upload->handle);

        return ApplyResult::ok(
            ['filename' => $stored, 'category_id' => (int) $target->getCategoryId()],
            [],
            array_values(array_map('strval', $result['messages'] ?? [])),
        );
    }
}
