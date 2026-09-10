<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;

/**
 * Serves a staged file to a reviewer, and to nobody else.
 *
 * ## Why this exists at all
 *
 * A reviewer must see the image before approving it. Approving a file you have
 * not looked at is worse than approving text you have not read — text at least
 * shows itself in the diff. But the file deliberately lives in
 * `data/addons/ai_platform/pending/`, which `redaxo/data/.htaccess` denies to the
 * web, so there is no URL to put in an `<img>` tag. This endpoint is that URL.
 *
 * ## Three properties that make it safe
 *
 * 1. **`$published = false`.** The endpoint answers in the backend only. A public
 *    one would turn the staging directory into an open file host: anyone who
 *    guessed a handle could fetch anything an agent ever offered.
 * 2. **`ai_changes[]` is required**, the same permission the inbox needs. The
 *    people who may decide on a request are exactly the people who may see its
 *    file.
 * 3. **The path is never taken from the request.** The handle is looked up in the
 *    database and the path is derived from the stored row, so `../` in a
 *    parameter has nothing to act on. This is the whole reason the handle is an
 *    opaque token rather than a filename.
 *
 * SVG is served as-is, as decided for this addon. `Content-Disposition: inline`
 * plus the real mime type means the browser renders it — and the inbox embeds it
 * in an `<img>` tag, where scripts inside an SVG do not run. That is the tag's
 * semantics rather than a precaution that could be forgotten. The frontend
 * delivery after approval is a different matter and is discussed in
 * PendingUploadStore.
 */
class rex_api_ai_change_file extends rex_api_function
{
    protected $published = false;

    public function execute()
    {
        if (!ChangeService::getInstance()->isEnabled()) {
            throw new rex_api_exception(rex_i18n::rawMsg('ai_platform_changes_disabled_page'));
        }

        $user = rex::getUser();
        if (null === $user || (!$user->isAdmin() && !$user->hasPerm('ai_changes[]') && !ChangeService::mayApprove($user))) {
            throw new rex_api_exception(rex_i18n::rawMsg('ai_platform_change_upload_forbidden'));
        }

        $handle = rex_request('handle', 'string', '');
        $upload = '' === $handle ? null : (new PendingUploadStore())->findByHandle($handle);

        if (null === $upload || !$upload->fileExists()) {
            throw new rex_api_exception(rex_i18n::rawMsg('ai_platform_change_ref_upload_gone', $handle));
        }

        // sendFile() cleans the output buffers itself and exits. Nothing of
        // REDAXO's normal response pipeline may run after this point.
        rex_response::sendFile(
            $upload->path(),
            $upload->mime,
            'inline',
            $upload->filename,
        );

        exit;
    }
}
