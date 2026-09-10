<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Cronjob;

use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;
use rex_cronjob;
use rex_i18n;
use Throwable;

/**
 * Frees space taken by old change requests, without destroying the record.
 *
 * ## What it does, and what it deliberately does not
 *
 * It empties the JSON of requests that were decided long enough ago: `payload`,
 * `payload_edited`, `snapshot_before` and `context_snapshot`. Those four columns
 * are effectively all of the volume — a proposal carries the values it wants
 * written plus a snapshot of everything the target looked like before.
 *
 * It also removes **staged media files**, which are the other kind of volume and
 * the only one measured in megabytes. Two sweeps, because the two have different
 * lifetimes:
 *
 * - Files of requests decided before the cutoff. Same period, same reasoning as
 *   the payload: the decision is made, the bytes are only weight now.
 * - Uploads no proposal ever referenced, past their 24-hour reservation. An agent
 *   that uploads and then crashes leaves bytes behind *and* a filename blocked;
 *   this is the only sweep with a short fuse, and it is why staging reserves the
 *   name in a row rather than just dropping a file somewhere.
 *
 * A pending request keeps its file however old it is — a proposal whose image has
 * been deleted is not a saving, it is a broken proposal.
 *
 * **The row itself stays, forever.** Who proposed what, against which target,
 * when, decided by whom through which channel — that is the record, and once API
 * tokens can approve their own proposals it is the only thing standing between
 * an automated write and a change nobody can explain. Deleting it to save space
 * would be trading away the only reason the feature is trustworthy.
 *
 * Rows still awaiting a decision are never touched, whatever their age: a
 * pending request without its payload is not a saving, it is a broken request.
 *
 * ## Why this is a cronjob and not a setting
 *
 * It used to be `changes_retention_days` on the settings page plus a button. Two
 * problems with that: a number in a form does nothing on its own — somebody has
 * to come back and press the button — and a page full of editable fields
 * suggested that the retention was being enforced when nothing enforced it.
 *
 * As a cronjob the period sits where the schedule sits, and the schedule is the
 * part that actually decides when anything happens.
 */
class ThinChangeRequests extends rex_cronjob
{
    public function execute(): bool
    {
        $days = (int) $this->getParam('days');

        // A zero or negative period would mean "thin everything decided,
        // including a minute ago". Refused rather than interpreted: an empty
        // field in a cronjob form is a mistake, not an instruction.
        if ($days < 1) {
            $this->setMessage(rex_i18n::rawMsg('ai_platform_cronjob_thin_needs_days'));

            return false;
        }

        try {
            $thinned = (new ChangeRequestStore())->purgeOlderThan($days);

            // Files before rows would be the wrong order only if a failure
            // between the two mattered — it does not: an upload row whose file is
            // gone is swept again on the next run, and `checkReferences()` reports
            // it in the meantime rather than applying a proposal with no bytes.
            $uploads = new PendingUploadStore();
            $files = 0;
            foreach ([...$uploads->findDecidedBefore($days), ...$uploads->findAbandoned()] as $upload) {
                $uploads->discard($upload->handle);
                ++$files;
            }
        } catch (Throwable $e) {
            $this->setMessage($e->getMessage());

            return false;
        }

        $message = rex_i18n::rawMsg('ai_platform_cronjob_thin_done', $thinned, $days);
        if ($files > 0) {
            $message .= rex_i18n::rawMsg('ai_platform_cronjob_thin_uploads', $files);
        }

        $this->setMessage($message);

        return true;
    }

    public function getTypeName(): string
    {
        return rex_i18n::rawMsg('ai_platform_cronjob_thin_name');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getParamFields(): array
    {
        return [
            [
                'label' => rex_i18n::rawMsg('ai_platform_cronjob_thin_days'),
                'name' => 'days',
                'type' => 'text',
                'default' => '90',
                'attributes' => ['type' => 'number', 'min' => '1'],
                'notice' => rex_i18n::rawMsg('ai_platform_cronjob_thin_days_notice'),
            ],
        ];
    }
}
