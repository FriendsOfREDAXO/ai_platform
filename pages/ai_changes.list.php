<?php

/**
 * Inbox and detail view for change requests.
 *
 * `func=view` shows one request with its diff and the decision buttons;
 * without it, the filtered list with bulk actions.
 *
 * Every value that reaches the page from a request or a payload is escaped on
 * output — payloads come from an LLM or an external adapter and are rendered to
 * a backend user with full rights.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Change\ChangeQuery;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\ChangeStatus;
use FriendsOfRedaxo\AiPlatform\Change\Diff\DiffRenderer;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use FriendsOfRedaxo\AiPlatform\Change\StaleTargetException;
use FriendsOfRedaxo\AiPlatform\Change\TargetGoneException;

$service = ChangeService::getInstance();
$store = new ChangeRequestStore();
$user = rex::requireUser();
$csrf = rex_csrf_token::factory('ai_platform_changes');

$mayApprove = ChangeService::mayApprove($user);

$func = rex_request('func', 'string', '');
$id = rex_request('id', 'int', 0);
$filter = ChangeQuery::fromRequest();

if (!$service->isEnabled()) {
    echo rex_view::warning(rex_i18n::msg('ai_platform_change_disabled'));
}

// ---------------------------------------------------------------------------
// Decisions
// ---------------------------------------------------------------------------

/**
 * Turns a BatchResult into one message block, naming what did not go through.
 * Silently reporting only the successes would make a partly failed batch look
 * complete.
 */
$renderBatch = static function (FriendsOfRedaxo\AiPlatform\Change\BatchResult $result, string $successKey): string {
    $out = '';

    if ($result->appliedCount() > 0) {
        $out .= rex_view::success(rex_i18n::msg($successKey, $result->appliedCount()));
    }

    if ($result->staleCount() > 0) {
        $items = '';
        foreach ($result->getStale() as $staleId => $message) {
            $items .= '<li>#' . (int) $staleId . ' — ' . rex_escape($message) . '</li>';
        }
        $out .= rex_view::warning(
            rex_i18n::msg('ai_platform_change_batch_stale', $result->staleCount()) . '<ul>' . $items . '</ul>',
        );
    }

    if ($result->failedCount() > 0) {
        $items = '';
        foreach ($result->getFailed() as $failedId => $message) {
            $items .= '<li>#' . (int) $failedId . ' — ' . rex_escape($message) . '</li>';
        }
        $out .= rex_view::error(
            rex_i18n::msg('ai_platform_change_batch_failed', $result->failedCount()) . '<ul>' . $items . '</ul>',
        );
    }

    if ($result->skippedCount() > 0) {
        $items = '';
        foreach ($result->getSkipped() as $skippedId => $message) {
            $items .= '<li>#' . (int) $skippedId . ' — ' . rex_escape($message) . '</li>';
        }
        $out .= rex_view::info(
            rex_i18n::msg('ai_platform_change_batch_skipped', $result->skippedCount()) . '<ul>' . $items . '</ul>',
        );
    }

    return $out;
};

if ('post' === rex_request::requestMethod() && $csrf->isValid() && $mayApprove) {
    $action = rex_post('action', 'string', '');
    $note = rex_post('note', 'string', '');
    $force = 1 === rex_post('force', 'int', 0);
    $selected = array_map('intval', array_keys(rex_post('selected', 'array', [])));

    try {
        switch ($action) {
            case 'approve':
                $result = $service->approve($id, $user, $force, '' === $note ? null : $note);
                $service->flushCacheRebuilds();
                echo $result->success
                    ? rex_view::success(rex_i18n::msg('ai_platform_change_applied_one'))
                    : rex_view::error(rex_escape((string) $result->error));
                $func = '';
                break;

            case 'reject':
                $service->reject($id, $user, $note);
                echo rex_view::success(rex_i18n::msg('ai_platform_change_rejected_one'));
                $func = '';
                break;

            case 'approve_selected':
                echo $renderBatch($service->approveMany($selected, $user, $force), 'ai_platform_change_applied_many');
                $func = '';
                break;

            case 'reject_selected':
                echo $renderBatch($service->rejectMany($selected, $user, $note), 'ai_platform_change_rejected_many');
                $func = '';
                break;

            case 'approve_changeset':
                $changesetId = rex_post('changeset_id', 'int', 0);
                echo $renderBatch($service->approveChangeset($changesetId, $user, $force), 'ai_platform_change_applied_many');
                $func = '';
                break;

            case 'approve_filtered':
                // Unbounded: the batch cap was removed because what a batch does
                // is decided by its writes, not their number. `0` means no limit.
                $ids = $filter->matchingOpenIds(0);
                echo $renderBatch($service->approveMany($ids, $user, $force), 'ai_platform_change_applied_many');
                $func = '';
                break;
        }
    } catch (StaleTargetException $e) {
        echo rex_view::warning(rex_escape($e->getMessage()));
    } catch (TargetGoneException $e) {
        echo rex_view::warning(rex_escape($e->getMessage()));
        $func = '';
    } catch (Throwable $e) {
        echo rex_view::error(rex_escape($e->getMessage()));
    }
}

// ---------------------------------------------------------------------------
// Detail view
// ---------------------------------------------------------------------------

if ('view' === $func && $id > 0) {
    $request = $service->getRequest($id);

    if (null === $request) {
        echo rex_view::error(rex_i18n::msg('ai_platform_change_not_found', $id));
        $func = '';
    } else {
        $handler = HandlerRegistry::require($request->type);
        $inspection = $service->inspectTarget($request);
        // Both gates: the blanket reviewer permission and the per-target check.
        $mayDecide = $mayApprove && $handler->canApprove($user, $request);

        $backUrl = rex_url::currentBackendPage($filter->toParams());

        // --- header -----------------------------------------------------
        $meta = '<dl class="dl-horizontal ai-change-meta">'
            . '<dt>' . rex_i18n::msg('ai_platform_change_col_type') . '</dt>'
            . '<dd>' . rex_escape($handler->getLabel())
                . ' <span class="label ' . $request->operation->cssClass() . '">' . rex_escape($request->operation->label()) . '</span></dd>'
            . '<dt>' . rex_i18n::msg('ai_platform_change_col_target') . '</dt>'
            . '<dd>' . rex_escape($request->label());

        $targetUrl = $request->target->backendUrl();
        if (null !== $targetUrl) {
            $meta .= ' <a href="' . $targetUrl . '" class="btn btn-xs btn-default">'
                . rex_i18n::msg('ai_platform_change_open_target') . '</a>';
        }

        $meta .= '</dd>'
            . '<dt>' . rex_i18n::msg('ai_platform_change_col_status') . '</dt>'
            . '<dd><span class="label ' . $request->status->cssClass() . '">' . rex_escape($request->status->label()) . '</span>'
            . ('api' === $request->reviewedVia
                && in_array($request->status, [ChangeStatus::Approved, ChangeStatus::Applied, ChangeStatus::Failed], true)
                ? ' <span class="label label-warning">' . rex_escape(rex_i18n::msg('ai_platform_change_via_api')) . '</span>'
                  . '<br><small class="text-muted">' . rex_escape(rex_i18n::msg('ai_platform_change_via_api_hint')) . '</small>'
                : '')
            . '</dd>'
            . '<dt>' . rex_i18n::msg('ai_platform_change_col_source') . '</dt>'
            . '<dd>' . rex_escape($request->source->displayName())
                . ' <small class="text-muted">' . rex_escape($request->source->channel) . ' · ' . rex_escape($request->source->key) . '</small>';

        $sourceUser = $request->source->resolveUserName();
        if (null !== $sourceUser) {
            $meta .= '<br><small class="text-muted">' . rex_i18n::msg('ai_platform_change_on_behalf_of', rex_escape($sourceUser)) . '</small>';
        }

        $meta .= '</dd>'
            . '<dt>' . rex_i18n::msg('ai_platform_change_col_created') . '</dt>'
            . '<dd>' . rex_escape($request->createdAt?->format('d.m.Y H:i') ?? '—') . '</dd>';

        if ([] !== $request->source->meta) {
            $metaItems = [];
            foreach ($request->source->meta as $key => $value) {
                $metaItems[] = rex_escape((string) $key) . ': ' . rex_escape((string) $value);
            }
            $meta .= '<dt>' . rex_i18n::msg('ai_platform_change_col_context') . '</dt>'
                . '<dd><small class="text-muted">' . implode(' · ', $metaItems) . '</small></dd>';
        }

        if (null !== $request->reviewedBy) {
            $meta .= '<dt>' . rex_i18n::msg('ai_platform_change_col_reviewed') . '</dt>'
                . '<dd>' . rex_escape((string) $request->reviewerName())
                . ' · ' . rex_escape($request->reviewedAt?->format('d.m.Y H:i') ?? '—') . '</dd>';
        }

        if (null !== $request->reviewNote && '' !== $request->reviewNote) {
            $meta .= '<dt>' . rex_i18n::msg('ai_platform_change_col_note') . '</dt>'
                . '<dd>' . nl2br(rex_escape($request->reviewNote)) . '</dd>';
        }

        if (null !== $request->applyError) {
            $meta .= '<dt>' . rex_i18n::msg('ai_platform_change_col_error') . '</dt>'
                . '<dd class="text-danger">' . rex_escape($request->applyError) . '</dd>';
        }

        $meta .= '</dl>';

        // --- reason -----------------------------------------------------
        $reason = '' === $request->reason
            ? '<p class="text-muted">' . rex_i18n::msg('ai_platform_change_no_reason') . '</p>'
            : '<blockquote class="ai-change-reason">' . nl2br(rex_escape($request->reason)) . '</blockquote>';

        // --- warnings ---------------------------------------------------
        // Each finding gets its own block, and broken references get the
        // strongest one: unlike a changed target, there is no override that
        // makes applying them correct.
        $warnings = '';

        if ($inspection->gone) {
            $warnings .= rex_view::warning(rex_escape($inspection->messages()[0] ?? ''));
        } else {
            if ($inspection->hasBrokenReferences()) {
                $items = '';
                foreach ($inspection->brokenReferences as $problem) {
                    $items .= '<li>' . rex_escape($problem) . '</li>';
                }
                $warnings .= rex_view::error(
                    rex_i18n::msg('ai_platform_change_broken_references') . '<ul>' . $items . '</ul>',
                );
            }

            if ($inspection->changed) {
                $text = rex_i18n::msg(
                    $service->blocksOnStale()
                        ? 'ai_platform_change_stale_blocked'
                        : 'ai_platform_change_stale_warned',
                );
                if ([] !== $inspection->changedFields) {
                    $text .= '<br><small>' . rex_escape(
                        rex_i18n::rawMsg('ai_platform_change_changed_fields', implode(', ', $inspection->changedFields)),
                    ) . '</small>';
                }
                $warnings .= rex_view::warning($text);
            }

            if ($inspection->contextChanged) {
                $items = '';
                foreach ($inspection->contextChanges as $change) {
                    $items .= '<li>' . rex_escape($change) . '</li>';
                }
                $warnings .= rex_view::info(
                    rex_i18n::msg('ai_platform_change_context_changed') . '<ul>' . $items . '</ul>',
                );
            }
        }

        if (!$mayDecide) {
            $warnings .= rex_view::warning(rex_i18n::msg('ai_platform_change_no_approve_permission'));
        }

        if ($request->wasEdited()) {
            $warnings .= rex_view::info(rex_i18n::msg('ai_platform_change_payload_edited'));
        }

        // --- the staged file, if this request carries one -----------------
        //
        // The reviewer has to see it. A diff row saying `upload: up_7f3a…` tells
        // nobody anything about whether that picture belongs on the site, and
        // approving a file sight unseen is worse than approving unread text —
        // text at least shows itself in the diff.
        //
        // Images are embedded and scaled down by CSS rather than by a thumbnail
        // service: the media manager only works on files that are already in the
        // pool, and this one deliberately is not. Anything a browser will not
        // display inline — a PDF, an Office document — gets a link instead of a
        // broken image box.
        $uploadBlock = '';
        $attachments = $request->effectivePayload()->attachments();
        if ([] !== $attachments) {
            $upload = (new PendingUploadStore())->findByHandle($attachments[0]);

            if (null === $upload || !$upload->fileExists()) {
                $uploadBlock = rex_view::warning(rex_i18n::msg('ai_platform_change_ref_upload_file_gone', $attachments[0]));
            } else {
                $fileUrl = rex_url::backendController([
                    'rex-api-call' => 'ai_change_file',
                    'handle' => $upload->handle,
                ]);
                // NOT $meta — that name already holds the request's metadata
                // block further down and this would silently blank it.
                $fileMeta = rex_i18n::msg(
                    'ai_platform_change_upload_meta',
                    rex_formatter::bytes($upload->bytes),
                    null === $upload->width ? $upload->mime : $upload->width . '×' . $upload->height . ' px',
                );

                $body = $upload->isDisplayableImage()
                    ? '<a href="' . $fileUrl . '" target="_blank" rel="noopener">'
                        . '<img src="' . $fileUrl . '" alt="" class="ai-upload-preview">'
                        . '</a>'
                    : '<p>' . rex_i18n::msg('ai_platform_change_upload_no_preview') . '</p>';

                $uploadBlock = '<div class="ai-upload-block">'
                    . $body
                    . '<p class="ai-upload-meta"><strong>' . rex_escape($upload->filename) . '</strong> '
                    . '<small class="text-muted">' . rex_escape($fileMeta) . '</small><br>'
                    . '<a href="' . $fileUrl . '" target="_blank" rel="noopener">'
                    . rex_i18n::msg('ai_platform_change_upload_open') . '</a></p>'
                    . '</div>';
            }
        }

        // --- diff -------------------------------------------------------
        $diff = DiffRenderer::render(
            $handler->diffFields($request, $inspection->currentValues),
            $inspection->changed,
        );

        // --- deviations from the applied write --------------------------
        $deviationBlock = '';
        if (ChangeStatus::Applied === $request->status && null !== $request->applyResult) {
            $valuesAfter = $request->applyResult['values_after'] ?? null;
            if (is_array($valuesAfter)) {
                $rows = '';
                foreach ($request->effectivePayload()->toArray() as $key => $proposed) {
                    if (!array_key_exists($key, $valuesAfter)) {
                        continue;
                    }
                    if ((string) $valuesAfter[$key] === (string) $proposed) {
                        continue;
                    }
                    $rows .= '<tr><th scope="row">' . rex_escape((string) $key) . '</th>'
                        . '<td>' . rex_escape((string) $proposed) . '</td>'
                        . '<td>' . rex_escape((string) $valuesAfter[$key]) . '</td></tr>';
                }
                if ('' !== $rows) {
                    $deviationBlock = rex_view::info(
                        rex_i18n::msg('ai_platform_change_deviation_notice')
                        . '<table class="table table-condensed"><thead><tr><th></th>'
                        . '<th>' . rex_i18n::msg('ai_platform_change_deviation_proposed') . '</th>'
                        . '<th>' . rex_i18n::msg('ai_platform_change_deviation_stored') . '</th></tr></thead>'
                        . '<tbody>' . $rows . '</tbody></table>',
                    );
                }
            }
        }

        // --- decision form ----------------------------------------------
        $actions = '';
        if ($request->isOpen() && $mayDecide && !$inspection->gone) {
            $forceField = $inspection->isOverridable() && $service->blocksOnStale()
                ? '<div class="checkbox"><label><input type="checkbox" name="force" value="1"> '
                    . rex_i18n::msg('ai_platform_change_force_label') . '</label></div>'
                : '';

            $changesetButton = '';
            if (null !== $request->changesetId) {
                $counts = $store->changesetCounts($request->changesetId);
                if ($counts['open'] > 1) {
                    $changesetButton = '<button class="btn btn-primary" type="submit" name="action" value="approve_changeset">'
                        . rex_i18n::msg('ai_platform_change_approve_changeset', $counts['open']) . '</button> ';
                }
            }

            $actions = '
                <form action="' . rex_url::currentBackendPage() . '" method="post">
                    ' . $csrf->getHiddenField() . '
                    <input type="hidden" name="id" value="' . $request->id . '">
                    <input type="hidden" name="changeset_id" value="' . (int) $request->changesetId . '">
                    <fieldset>
                        <div class="form-group">
                            <label for="ai-change-note">' . rex_i18n::msg('ai_platform_change_note_label') . '</label>
                            <textarea class="form-control" id="ai-change-note" name="note" rows="2"></textarea>
                        </div>
                        ' . $forceField . '
                        <button class="btn btn-save" type="submit" name="action" value="approve"'
                            . ($inspection->hasBrokenReferences() ? ' disabled title="' . rex_i18n::msg('ai_platform_change_approve_blocked') . '"' : '') . '>'
                            . rex_i18n::msg('ai_platform_change_approve') . '</button>
                        <button class="btn btn-delete" type="submit" name="action" value="reject">'
                            . rex_i18n::msg('ai_platform_change_reject') . '</button>
                        ' . $changesetButton . '
                        <a class="btn btn-default" href="' . $backUrl . '">'
                            . rex_i18n::msg('ai_platform_change_back') . '</a>
                    </fieldset>
                </form>';
        } else {
            $actions = '<a class="btn btn-default" href="' . $backUrl . '">'
                . rex_i18n::msg('ai_platform_change_back') . '</a>';
        }

        $content = $warnings . $meta
            . '<h3>' . rex_i18n::msg('ai_platform_change_reason_heading') . '</h3>' . $reason
            . ('' === $uploadBlock
                ? ''
                : '<h3>' . rex_i18n::msg('ai_platform_change_upload_preview') . '</h3>' . $uploadBlock)
            . '<h3>' . rex_i18n::msg('ai_platform_change_diff_heading') . '</h3>' . $diff
            . $deviationBlock
            . $actions;

        $fragment = new rex_fragment();
        $fragment->setVar('class', 'edit', false);
        $fragment->setVar('title', rex_i18n::msg('ai_platform_change_detail_heading', $request->id), false);
        $fragment->setVar('body', $content, false);
        echo $fragment->parse('core/page/section.php');
    }
}

// ---------------------------------------------------------------------------
// List view
// ---------------------------------------------------------------------------

if ('view' !== $func) {
    $counts = $store->countByStatus();

    // --- filter bar -------------------------------------------------------
    $statusOptions = ['open' => rex_i18n::msg('ai_platform_change_filter_open'), 'all' => rex_i18n::msg('ai_platform_change_filter_all')];
    foreach (ChangeStatus::cases() as $case) {
        $statusOptions[$case->value] = $case->label() . ' (' . ($counts[$case->value] ?? 0) . ')';
    }

    $statusSelect = '';
    foreach ($statusOptions as $value => $label) {
        $selected = $filter->statusFilterValue() === (string) $value ? ' selected' : '';
        $statusSelect .= '<option value="' . rex_escape((string) $value) . '"' . $selected . '>' . rex_escape($label) . '</option>';
    }

    $typeSelect = '<option value="">' . rex_i18n::msg('ai_platform_change_filter_all_types') . '</option>';
    foreach (HandlerRegistry::labels() as $type => $label) {
        $selected = $filter->getType() === $type ? ' selected' : '';
        $typeSelect .= '<option value="' . rex_escape($type) . '"' . $selected . '>' . rex_escape($label) . '</option>';
    }

    $sourceSelect = '<option value="">' . rex_i18n::msg('ai_platform_change_filter_all_sources') . '</option>';
    foreach (ChangeQuery::knownSources() as $key => $label) {
        $selected = $filter->getSourceKey() === $key ? ' selected' : '';
        $sourceSelect .= '<option value="' . rex_escape($key) . '"' . $selected . '>' . rex_escape($label) . '</option>';
    }

    // Wrapped in panel-body so the padding comes from be_style rather than from
    // our own stylesheet: the fragment's `content` slot renders flush with the
    // panel edges (that is what makes a rex_list table sit edge to edge), and a
    // form placed there without a wrapper ends up glued to the border and to the
    // table below it.
    $filterBar = '
    <div class="panel-body ai-change-filter-wrap">
    <form action="' . rex_url::currentBackendPage() . '" method="get" class="form-inline ai-change-filter">
        <input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">
        <div class="form-group">
            <label class="sr-only" for="ai-filter-status">' . rex_i18n::msg('ai_platform_change_col_status') . '</label>
            <select class="form-control" id="ai-filter-status" name="status">' . $statusSelect . '</select>
        </div>
        <div class="form-group">
            <label class="sr-only" for="ai-filter-type">' . rex_i18n::msg('ai_platform_change_col_type') . '</label>
            <select class="form-control" id="ai-filter-type" name="type">' . $typeSelect . '</select>
        </div>
        <div class="form-group">
            <label class="sr-only" for="ai-filter-source">' . rex_i18n::msg('ai_platform_change_col_source') . '</label>
            <select class="form-control" id="ai-filter-source" name="source">' . $sourceSelect . '</select>
        </div>
        <div class="form-group">
            <label class="sr-only" for="ai-filter-q">' . rex_i18n::msg('ai_platform_change_filter_search') . '</label>
            <input class="form-control" type="search" id="ai-filter-q" name="q" value="' . rex_escape($filter->getSearch()) . '" placeholder="' . rex_i18n::msg('ai_platform_change_filter_search') . '">
        </div>
        <button class="btn btn-default" type="submit">' . rex_i18n::msg('ai_platform_change_filter_apply') . '</button>
    </form>
    </div>';

    // --- list -------------------------------------------------------------
    $list = rex_list::factory($filter->toListSql(), 50);
    $list->addParam('func', 'view');
    foreach ($filter->toParams() as $key => $value) {
        $list->addParam($key, (string) $value);
    }

    $list->setNoRowsMessage(rex_i18n::msg('ai_platform_change_list_empty'));

    if ($mayApprove) {
        $list->addColumn(
            'select',
            '<input type="checkbox" name="selected[###id###]" value="1" form="ai-change-bulk" class="ai-change-select">',
            0,
            ['<th><input type="checkbox" id="ai-change-select-all"></th>', '<td>###VALUE###</td>'],
        );
        $list->setColumnLabel('select', '');
    }

    // Removed from display, not from the query: the status formatter reads them
    // through getValue(), which goes to the SQL result rather than the column
    // list. `reviewed_via` was missing here and showed up as a bare column header
    // spelling out a database column to an editor.
    $list->removeColumn('reviewed_by');
    $list->removeColumn('reviewed_via');
    $list->removeColumn('source_key');
    $list->removeColumn('changeset_id');

    $list->setColumnLabel('id', 'ID');
    $list->setColumnParams('id', ['func' => 'view', 'id' => '###id###']);

    $list->setColumnLabel('type', rex_i18n::msg('ai_platform_change_col_type'));
    $list->setColumnFormat('type', 'custom', static function (array $params): string {
        $handler = HandlerRegistry::get((string) $params['list']->getValue('type'));

        return rex_escape(null === $handler ? (string) $params['list']->getValue('type') : $handler->getLabel());
    });

    $list->setColumnLabel('operation', rex_i18n::msg('ai_platform_change_col_operation'));
    $list->setColumnFormat('operation', 'custom', static function (array $params): string {
        $operation = FriendsOfRedaxo\AiPlatform\Change\ChangeOperation::tryFrom((string) $params['list']->getValue('operation'));
        if (null === $operation) {
            return '';
        }

        return '<span class="label ' . $operation->cssClass() . '">' . rex_escape($operation->label()) . '</span>';
    });

    $list->setColumnLabel('target_label', rex_i18n::msg('ai_platform_change_col_target'));
    $list->setColumnParams('target_label', ['func' => 'view', 'id' => '###id###']);

    $list->setColumnLabel('status', rex_i18n::msg('ai_platform_change_col_status'));
    $list->setColumnFormat('status', 'custom', static function (array $params): string {
        $status = ChangeStatus::tryFrom((string) $params['list']->getValue('status'));
        if (null === $status) {
            return '';
        }

        $badge = '<span class="label ' . $status->cssClass() . '">' . rex_escape($status->label()) . '</span>';

        // An approval that no human looked at is the one thing an editor has to
        // be able to spot at a glance. Shown next to the status rather than
        // folded into it: what happened and who decided it are two questions.
        //
        // Status matters here as well as channel: withdrawing also happens over
        // the API and also records `reviewed_via = api`, but labelling a
        // withdrawn request "approved unattended" states the opposite of what
        // happened. Only a decision that actually wrote something gets the badge.
        $decided = ChangeStatus::tryFrom((string) $params['list']->getValue('status'));
        if (
            'api' === (string) $params['list']->getValue('reviewed_via')
            && in_array($decided, [ChangeStatus::Approved, ChangeStatus::Applied, ChangeStatus::Failed], true)
        ) {
            $badge .= ' <span class="label label-warning" title="'
                . rex_escape(rex_i18n::msg('ai_platform_change_via_api_hint')) . '">'
                . rex_escape(rex_i18n::msg('ai_platform_change_via_api')) . '</span>';
        }

        return $badge;
    });

    $list->setColumnLabel('source_label', rex_i18n::msg('ai_platform_change_col_source'));
    $list->setColumnFormat('source_label', 'custom', static function (array $params): string {
        $label = (string) $params['list']->getValue('source_label');
        $key = (string) $params['list']->getValue('source_key');

        return rex_escape('' !== $label ? $label : $key);
    });

    $list->setColumnLabel('reason', rex_i18n::msg('ai_platform_change_col_reason'));
    $list->setColumnFormat('reason', 'custom', static function (array $params): string {
        $reason = (string) $params['list']->getValue('reason');
        $short = mb_strimwidth($reason, 0, 90, '…');

        return '<span title="' . rex_escape($reason) . '">' . rex_escape($short) . '</span>';
    });

    $list->setColumnLabel('createdate', rex_i18n::msg('ai_platform_change_col_created'));
    $list->setColumnFormat('createdate', 'strftime', 'datetime');

    // State column. Inspecting a row means reading its target, so this is only
    // done for open requests on the current page — a decided request cannot be
    // acted on anyway, and checking every row of every page would turn a list
    // view into a few hundred queries. Reviewers need to spot the problem cases
    // without opening each entry, which is what makes it worth the ones we do.
    $list->addColumn(
        'state',
        '',
        -1,
        ['<th>' . rex_i18n::msg('ai_platform_change_col_state') . '</th>', '<td>###VALUE###</td>'],
    );
    $list->setColumnFormat('state', 'custom', static function (array $params) use ($service): string {
        $status = ChangeStatus::tryFrom((string) $params['list']->getValue('status'));
        if (null === $status || !$status->isOpen()) {
            return '';
        }

        $request = $service->getRequest((int) $params['list']->getValue('id'));
        if (null === $request) {
            return '';
        }

        try {
            $inspection = $service->inspectTarget($request);
        } catch (Throwable) {
            return '';
        }

        $badge = $inspection->badge();
        if (null === $badge) {
            return '';
        }

        return '<span class="label ' . $inspection->badgeCssClass() . '" title="'
            . rex_escape(implode(' ', $inspection->messages())) . '">' . rex_escape($badge) . '</span>';
    });

    $content = $list->get();

    // --- bulk actions -----------------------------------------------------
    $openInFilter = count($filter->matchingOpenIds(0));

    if (!$mayApprove) {
        // Without the reviewer permission there is nothing to submit, so the
        // form is not rendered at all — an inert form invites confusion.
        $fragment = new rex_fragment();
        $fragment->setVar('title', rex_i18n::msg('ai_platform_changes_inbox'), false);
        $fragment->setVar('content', $filterBar . $content, false);
        echo $fragment->parse('core/page/section.php');

        echo rex_view::info(rex_i18n::msg('ai_platform_change_read_only_notice'));

        return;
    }

    $bulk = '
    <form action="' . rex_url::currentBackendPage() . '" method="post" id="ai-change-bulk" class="ai-change-bulk">
        ' . $csrf->getHiddenField() . '
        <fieldset>
            <div class="form-group">
                <label for="ai-bulk-note">' . rex_i18n::msg('ai_platform_change_note_label') . '</label>
                <textarea class="form-control" id="ai-bulk-note" name="note" rows="2"></textarea>
            </div>
            <div class="checkbox">
                <label><input type="checkbox" name="force" value="1"> ' . rex_i18n::msg('ai_platform_change_force_label') . '</label>
            </div>
            <button class="btn btn-save" type="submit" name="action" value="approve_selected">'
                . rex_i18n::msg('ai_platform_change_approve_selected') . '</button>
            <button class="btn btn-delete" type="submit" name="action" value="reject_selected">'
                . rex_i18n::msg('ai_platform_change_reject_selected') . '</button>';

    if ($openInFilter > 0) {
        $bulk .= '
            <button class="btn btn-primary" type="submit" name="action" value="approve_filtered">'
                . rex_i18n::msg('ai_platform_change_approve_filtered', $openInFilter) . '</button>';
    }

    $bulk .= '
        </fieldset>
    </form>';

    // Filter bar and table go into one section, but the filter is not part of
    // the table markup — it gets its own padded block (see styles.css), so the
    // selects do not sit flush against the section border.
    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('ai_platform_changes_inbox'), false);
    $fragment->setVar('content', $filterBar . $content, false);
    echo $fragment->parse('core/page/section.php');

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', rex_i18n::msg('ai_platform_change_bulk_heading'), false);
    $fragment->setVar('body', $bulk, false);
    echo $fragment->parse('core/page/section.php');
}
