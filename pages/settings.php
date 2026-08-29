<?php

/**
 * Everything the addon is configured with, on one page. Admin only (the whole
 * `ai_platform` section is `admin[]`; this file re-checks because a page file can
 * also be reached by direct inclusion).
 *
 * ## Why one page and not two
 *
 * The change-request settings were a page of their own — first under `ai_changes`,
 * then as a sibling tab here. Both arrangements had the same flaw: the master
 * switch that decides whether the feature exists at all sat on one page while
 * everything it governs sat on another. An admin switching the feature on then had
 * to find a second page to finish configuring it, and the second page had to carry
 * a sentence explaining where the switch lived.
 *
 * Now the switch and what it governs are in one form, and the detail settings are
 * only rendered when the feature is on. A setting for something that is switched
 * off is not information, it is an invitation to configure something that will
 * never run.
 *
 * ## Why the block is always rendered and only hidden
 *
 * The reveal is done in `assets/changes.js`, watching the master switch: flip it to
 * active and the detail settings appear at once, without a save. That is the whole
 * point — a two-step "switch on, save, now configure" is a worse form than a long
 * one.
 *
 * Which means the fields are **always in the DOM and always in the POST**, hidden
 * or not. That is deliberate and it is what makes the arrangement safe: a hidden
 * field submits the value it was rendered with, so saving while the block is out
 * of sight writes the stored values back unchanged.
 *
 * **Do not "optimise" this into conditional rendering.** It was that once, and the
 * trap is quiet: fields that are not rendered are not in the POST either, so
 * reading them anyway resets the stale policy to its default and empties the YForm
 * allow list the moment somebody saves the page in that state — no error, no
 * warning, a security setting silently reopened. If it ever has to become
 * conditional again, the post handler needs a marker emitted with the block to
 * tell "the user cleared every checkbox" from "the block was never on screen".
 *
 * The server still decides the *initial* state, so a page load with the feature
 * off does not flash the settings before the script runs.
 *
 * ## What used to be in the change settings and is not any more
 *
 * That page carried seven settings. Six were removed, and it is worth recording
 * why, because a short settings section reads like an unfinished one:
 *
 * - **Max payload size (KB)** — a cap on what a single proposal may carry. Never
 *   the thing that hurt: a large payload is a long article, and refusing it only
 *   meant the proposal could not be made.
 * - **Retention (days)** — a number in a form enforced nothing; somebody still
 *   had to come back and press a button. Now a cronjob
 *   ({@see \FriendsOfRedaxo\AiPlatform\Cronjob\ThinChangeRequests}), where the
 *   period sits next to the schedule that actually makes it happen.
 * - **Entries per run** — capped how many one batch approval applied. What a
 *   batch can do is decided by its writes, not by their number.
 * - **Open requests per source** — a flood limit. Nothing is written without a
 *   decision, so a runaway agent produced rows and no damage, and the cronjob now
 *   keeps the volume in check.
 * - **Allowed modules** — replaced by something that cannot be forgotten, below.
 * - **Who may approve over the API** — a read-only listing. That belongs to the
 *   api addon's token administration; the guide tab explains it.
 *
 * The module list is the one worth understanding. It existed for a single
 * reason: a REDAXO module whose output holds `REX_VALUE[... output=php]` runs the
 * slice value as PHP, so a proposal for such a module is code, not content — and
 * with an approval scope it is code nobody reads before it runs. But an allow
 * list is the wrong instrument: it needs maintaining, it defaults to permissive
 * to stay usable, and the one entry that matters is the one somebody forgets.
 * `ChangeService::moduleExecutesPhp()` reads the module's own output instead —
 * automatic, unforgettable, nothing to configure.
 *
 * What remains are the two decisions a person genuinely has to make: what should
 * happen when a target moved under a pending request, and which YForm tables may
 * be written at all. The latter cannot be derived from anything, because a
 * proposal can name any table in the database — including `rex_ycom_user`.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeStatus;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use FriendsOfRedaxo\AiPlatform\Service;

if (!rex::requireUser()->isAdmin()) {
    echo rex_view::error(rex_i18n::msg('ai_platform_changes_settings_admin_only'));

    return;
}

$service = Service::getInstance();
$csrfToken = rex_csrf_token::factory('ai_platform_settings');

if ('post' === rex_request::requestMethod() && $csrfToken->isValid()) {
    rex_config::set('ai_platform', 'default_text_profile', rex_post('default_text_profile', 'int', 0));
    rex_config::set('ai_platform', 'default_image_generation_profile', rex_post('default_image_generation_profile', 'int', 0));
    rex_config::set('ai_platform', 'default_image_understanding_profile', rex_post('default_image_understanding_profile', 'int', 0));
    rex_config::set('ai_platform', 'default_embedding_profile', rex_post('default_embedding_profile', 'int', 0));

    // Change requests: the master switch. Off means the menu entry disappears
    // entirely and every route into the feature refuses, so this is the one
    // place that turns the whole thing on or off.
    rex_config::set('ai_platform', 'changes_enabled', rex_post('changes_enabled', 'int', 0));

    // The detail fields are always part of the form — hidden by the script when
    // the switch is off, never absent — so they can be read unconditionally. See
    // the note at the top of the file before making this conditional again.
    rex_config::set('ai_platform', 'changes_stale_policy', 'warn' === rex_post('changes_stale_policy', 'string', 'block') ? 'warn' : 'block');
    rex_config::set('ai_platform', 'changes_allow_all_yform_tables', rex_post('changes_allow_all_yform_tables', 'int', 0));

    // The list is stored either way, so switching "allow all" off again
    // restores the previous selection instead of an empty one.
    $tables = array_values(array_keys(rex_post('allowed_yform_tables', 'array', [])));
    rex_config::set('ai_platform', 'changes_allowed_yform_tables', json_encode($tables, JSON_THROW_ON_ERROR));

    echo rex_view::success(rex_i18n::msg('ai_platform_settings_saved'));
}

// --- default profiles per type ----------------------------------------------

$types = [
    'text' => ['config_key' => 'default_text_profile', 'label' => rex_i18n::msg('ai_platform_default_text'), 'notice' => rex_i18n::msg('ai_platform_default_text_notice')],
    'image_generation' => ['config_key' => 'default_image_generation_profile', 'label' => rex_i18n::msg('ai_platform_default_image_generation'), 'notice' => rex_i18n::msg('ai_platform_default_image_generation_notice')],
    'image_understanding' => ['config_key' => 'default_image_understanding_profile', 'label' => rex_i18n::msg('ai_platform_default_image_understanding'), 'notice' => rex_i18n::msg('ai_platform_default_image_understanding_notice')],
    'embedding' => ['config_key' => 'default_embedding_profile', 'label' => rex_i18n::msg('ai_platform_default_embedding'), 'notice' => rex_i18n::msg('ai_platform_default_embedding_notice')],
];

$formFields = '';
foreach ($types as $type => $config) {
    $current = (int) rex_config::get('ai_platform', $config['config_key'], 0);
    $options = '<option value="0">' . rex_i18n::msg('ai_platform_please_select') . '</option>';

    foreach ($service->getProfiles($type) as $profile) {
        $providers = Service::getProviders();
        $providerLabel = $providers[$profile['provider']] ?? $profile['provider'];
        $label = rex_escape($profile['name'] . ' (' . $providerLabel . ' - ' . $profile['model'] . ')');
        $selected = (int) $profile['id'] === $current ? ' selected' : '';
        $options .= '<option value="' . (int) $profile['id'] . '"' . $selected . '>' . $label . '</option>';
    }

    $formFields .= '
        <div class="form-group">
            <label class="control-label col-sm-3" for="default-' . $type . '-profile">' . $config['label'] . '</label>
            <div class="col-sm-9">
                <select class="form-control selectpicker" id="default-' . $type . '-profile" name="' . $config['config_key'] . '">
                    ' . $options . '
                </select>
                <p class="help-block">' . $config['notice'] . '</p>
            </div>
        </div>';
}

// --- change requests: the master switch -------------------------------------

$changesEnabled = (int) rex_config::get('ai_platform', 'changes_enabled', 1);

// Everything marked `data-ai-changes-detail` belongs to the master switch: the
// detail fieldset, the wrapper around the two read-only tables, and the link into
// the inbox — while the feature is off there is no inbox to open, the menu entry
// is removed with it. `assets/changes.js` owns the state from the first paint on;
// this attribute only decides how the page arrives, so nothing flashes.
$detailHidden = 1 === $changesEnabled ? '' : ' hidden';

// A count in the notice, so switching the feature off does not silently hide
// pending requests that somebody is waiting on.
$openRequests = 0;
try {
    $openRequests = (new ChangeRequestStore())->countOpen();
} catch (Throwable) {
    // Tables missing because the addon was not reinstalled after the update.
    $openRequests = 0;
}

$changesNotice = rex_i18n::msg('ai_platform_changes_enabled_notice');
if ($openRequests > 0) {
    $changesNotice .= ' ' . rex_i18n::msg('ai_platform_changes_enabled_open_hint', $openRequests);
}

// Change requests arrive through the REST routes this addon registers with the
// api addon — there is no other way in since the backend form was removed. With
// the api addon missing or inactive, the feature can be switched on and then
// simply never receives anything, which is a confusing state to debug from the
// inbox. Say so here instead.
$apiAddonReady = rex_addon::get('api')->isAvailable();
$changesWarning = '';
if (!$apiAddonReady) {
    $changesWarning = rex_view::warning(rex_i18n::msg(
        1 === $changesEnabled
            ? 'ai_platform_changes_needs_api_active'
            : 'ai_platform_changes_needs_api',
    ));
}

$changesField = '
        <div class="form-group">
            <label class="control-label col-sm-3" for="changes-enabled">' . rex_i18n::msg('ai_platform_changes_enabled') . '</label>
            <div class="col-sm-9">
                <select class="form-control selectpicker" id="changes-enabled" name="changes_enabled">
                    <option value="1"' . (1 === $changesEnabled ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_status_active') . '</option>
                    <option value="0"' . (0 === $changesEnabled ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_status_inactive') . '</option>
                </select>
                <p class="help-block">' . $changesNotice . '</p>
                ' . $changesWarning . '
                <p class="form-control-static" data-ai-changes-detail' . $detailHidden . '>
                    <a href="' . rex_url::backendPage('ai_changes') . '">' . rex_i18n::msg('ai_platform_changes_open_inbox') . '</a>
                </p>
            </div>
        </div>';

// --- change requests: the detail settings -----------------------------------
//
// Always rendered, hidden by `assets/changes.js` while the master switch is off.
// The `hidden` attribute here only decides the state on arrival, so a page load
// with the feature off does not flash the settings before the script runs.

$stalePolicy = (string) rex_config::get('ai_platform', 'changes_stale_policy', 'block');
$allowAllTables = 1 === (int) rex_config::get('ai_platform', 'changes_allow_all_yform_tables', 0);

$decodeList = static function (string $key): array {
    $raw = rex_config::get('ai_platform', $key, '');
    if (is_array($raw)) {
        return $raw;
    }
    try {
        $decoded = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
};

$allowedTables = array_map('strval', $decodeList('changes_allowed_yform_tables'));

$tableRows = '';
if (class_exists(rex_yform_manager_table::class)) {
    foreach (rex_yform_manager_table::getAll() as $table) {
        $name = $table->getTableName();
        $checked = in_array($name, $allowedTables, true) ? ' checked' : '';
        // A YForm table's own name may be a language key rather than a label:
        // addons ship tables titled `translate:ymedia_tags_title`. Left raw, the
        // key is what an admin reads in the list.
        //
        // Resolved with $escape = false because rex_escape() runs over the result
        // anyway — the table name is data, and at least one installation has a
        // table called `Company (Users) <script>alert(2)</script>`.
        //
        // When the key has no translation — the owning addon is installed but its
        // language file does not carry it — rex_i18n hands back
        // `[translate:the_key]`, which is worse to read than the key itself. In
        // that case the technical table name is the honest label, and the `<small>`
        // repetition is dropped rather than printed twice.
        $rawName = $table->getName();
        $label = rex_i18n::translate($rawName, false);
        $technical = '';

        if (str_starts_with($label, '[translate:')) {
            $label = $name;
        } else {
            $technical = ' <small class="text-muted">' . rex_escape($name) . '</small>';
        }

        $tableRows .= '<div class="checkbox"><label>'
            . '<input type="checkbox" name="allowed_yform_tables[' . rex_escape($name) . ']" value="1"' . $checked . '> '
            . rex_escape($label) . $technical
            . '</label></div>';
    }
}
if ('' === $tableRows) {
    $tableRows = '<p class="help-block">' . rex_i18n::msg('ai_platform_change_no_yform_installed') . '</p>';
}

$detailFieldsets = '
    <fieldset data-ai-changes-detail' . $detailHidden . '>
        <legend>' . rex_i18n::msg('ai_platform_changes_settings') . '</legend>

        <div class="form-group">
            <label class="control-label col-sm-3" for="changes-stale">' . rex_i18n::msg('ai_platform_change_setting_stale') . '</label>
            <div class="col-sm-9">
                <select class="form-control" id="changes-stale" name="changes_stale_policy">
                    <option value="block"' . ('warn' !== $stalePolicy ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_change_setting_stale_block') . '</option>
                    <option value="warn"' . ('warn' === $stalePolicy ? ' selected' : '') . '>' . rex_i18n::msg('ai_platform_change_setting_stale_warn') . '</option>
                </select>
                <p class="help-block">' . rex_i18n::msg('ai_platform_change_setting_stale_help') . '</p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">' . rex_i18n::msg('ai_platform_change_setting_yform') . '</label>
            <div class="col-sm-9">
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="changes_allow_all_yform_tables" value="1" id="ai-allow-all-yform"' . ($allowAllTables ? ' checked' : '') . '>
                        <strong>' . rex_i18n::msg('ai_platform_change_setting_all_yform') . '</strong>
                    </label>
                </div>
                <p class="help-block">' . rex_i18n::msg('ai_platform_change_setting_yform_help') . '</p>
                ' . ($allowAllTables ? rex_view::warning(rex_i18n::msg('ai_platform_change_setting_all_yform_warning')) : '') . '
                <div id="ai-yform-list"' . ($allowAllTables ? ' class="ai-list-disabled"' : '') . '>
                    ' . $tableRows . '
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-9">
                <p class="help-block">' . rex_i18n::msg('ai_platform_change_setting_thin_hint') . '</p>
            </div>
        </div>
    </fieldset>';

// --- the two read-only tables ------------------------------------------------
// The `content` slot renders flush with the panel edges, which is right for a
// table and wrong for a heading or a form. They follow the form rather than
// sitting inside it: nothing here is editable. One wrapper around both, so the
// script hides them with a single attribute.

$handlerRows = '';
foreach (HandlerRegistry::all() as $type => $handler) {
    $operations = [];
    foreach ($handler->supportedOperations() as $operation) {
        $operations[] = $operation->label();
    }
    $handlerRows .= '<tr>'
        . '<td>' . rex_escape($handler->getLabel()) . '</td>'
        . '<td><code>' . rex_escape($type) . '</code></td>'
        . '<td>' . rex_escape(implode(', ', $operations)) . '</td>'
        . '<td>' . ($handler->supportsRevert() ? rex_i18n::msg('ai_platform_change_revert_yes') : rex_i18n::msg('ai_platform_change_revert_no')) . '</td>'
        . '</tr>';
}

$handlerTable = '
<table class="table">
    <thead>
        <tr>
            <th>' . rex_i18n::msg('ai_platform_change_col_type') . '</th>
            <th>' . rex_i18n::msg('ai_platform_change_handler_key') . '</th>
            <th>' . rex_i18n::msg('ai_platform_change_col_operation') . '</th>
            <th>' . rex_i18n::msg('ai_platform_change_handler_revert') . '</th>
        </tr>
    </thead>
    <tbody>' . $handlerRows . '</tbody>
</table>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('ai_platform_change_handlers_heading'), false);
$fragment->setVar('content', $handlerTable, false);
$extraSections = $fragment->parse('core/page/section.php');

// Volume per status. Every row links into the inbox filtered to that status — a
// bare number invites the question "which ones?", and the answer is one click
// away.
$statusCounts = (new ChangeRequestStore())->countByStatus();
$countRows = '';
$countTotal = 0;

foreach (ChangeStatus::cases() as $case) {
    $count = (int) ($statusCounts[$case->value] ?? 0);
    $countTotal += $count;

    if (0 === $count) {
        continue;
    }

    $inboxUrl = rex_url::backendPage('ai_changes', ['status' => $case->value]);
    $countRows .= '<tr>'
        . '<td><span class="label ' . $case->cssClass() . '">' . rex_escape($case->label()) . '</span></td>'
        . '<td class="rex-table-width-6">' . $count . '</td>'
        . '<td><a href="' . $inboxUrl . '">' . rex_i18n::msg('ai_platform_change_show_in_inbox') . '</a></td>'
        . '</tr>';
}

if ('' === $countRows) {
    $countRows = '<tr><td colspan="3">' . rex_i18n::msg('ai_platform_change_list_empty') . '</td></tr>';
}

$countTable = '
<table class="table">
    <thead>
        <tr>
            <th>' . rex_i18n::msg('ai_platform_change_col_status') . '</th>
            <th>' . rex_i18n::msg('ai_platform_change_count') . '</th>
            <th></th>
        </tr>
    </thead>
    <tbody>' . $countRows . '</tbody>
</table>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('ai_platform_change_counts_heading'), false);
$fragment->setVar('options', rex_i18n::msg('ai_platform_change_count_total', $countTotal), false);
$fragment->setVar('content', $countTable, false);
$extraSections .= $fragment->parse('core/page/section.php');

$content = '
<form action="' . rex_url::currentBackendPage() . '" method="post" class="form-horizontal">
    ' . $csrfToken->getHiddenField() . '

    <fieldset>
        <legend>' . rex_i18n::msg('ai_platform_default_models') . '</legend>
        ' . $formFields . '
    </fieldset>

    <fieldset>
        <legend>' . rex_i18n::msg('ai_platform_changes_title') . '</legend>
        ' . $changesField . '
    </fieldset>
    ' . $detailFieldsets . '

    <fieldset>
        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-9">
                <button class="btn btn-save" type="submit">' . rex_i18n::msg('ai_platform_save') . '</button>
            </div>
        </div>
    </fieldset>
</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('ai_platform_settings'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

echo '<div data-ai-changes-detail' . $detailHidden . '>' . $extraSections . '</div>';
