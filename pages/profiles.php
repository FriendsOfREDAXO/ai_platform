<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use FriendsOfRedaxo\AiPlatform\Service;

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');

// Handle form view
if ('add' === $func || 'edit' === $func) {
    $title = 'add' === $func ? rex_i18n::msg('ai_platform_profile_add') : rex_i18n::msg('ai_platform_profile_edit');

    $form = rex_form::factory(rex::getTable('ai_profile'), '', 'id = ' . $id);
    $form->addParam('page', 'ai_platform/profiles');
    $form->addParam('func', $func);
    $form->addParam('id', $id);
    $form->setApplyUrl(rex_url::currentBackendPage());
    if ('edit' === $func) {
        $form->setEditMode(true);
    }

    // Profile Name
    $field = $form->addTextField('name');
    $field->setLabel(rex_i18n::msg('ai_platform_profile_name'));
    $field->setAttribute('class', 'form-control');
    $field->setAttribute('autocomplete', 'ai-profile-name');
    $field->setNotice(rex_i18n::msg('ai_platform_profile_name_notice'));

    // Type
    $field = $form->addSelectField('type');
    $field->setLabel(rex_i18n::msg('ai_platform_type'));
    $select = $field->getSelect();
    foreach (Service::getTypes() as $key => $label) {
        $select->addOption($label, $key);
    }
    $field->setAttribute('class', 'form-control selectpicker');
    $field->setAttribute('id', 'ai-type-select');

    // Provider
    $field = $form->addSelectField('provider');
    $field->setLabel(rex_i18n::msg('ai_platform_provider'));
    $select = $field->getSelect();
    $select->addOption(rex_i18n::msg('ai_platform_please_select'), '');
    foreach (Service::getProviders() as $key => $label) {
        $select->addOption($label, $key);
    }
    $field->setAttribute('class', 'form-control selectpicker');
    $field->setAttribute('id', 'ai-provider-select');

    // API Key
    $field = $form->addTextField('api_key');
    $field->setLabel(rex_i18n::msg('ai_platform_api_key'));
    $field->setAttribute('class', 'form-control');
    $field->setAttribute('autocomplete', 'new-password');
    $field->setNotice(rex_i18n::msg('ai_platform_api_key_notice'));

    // Base URL (for Ollama)
    $field = $form->addTextField('base_url');
    $field->setLabel(rex_i18n::msg('ai_platform_base_url'));
    $field->setAttribute('class', 'form-control');
    $field->setNotice(rex_i18n::msg('ai_platform_base_url_notice'));

    // Model. The select in front of the input is filled by assets/profiles.js from the
    // provider's catalog; picking an entry writes it into the input, which stays the
    // single field bound to the column. The input only shows itself for a name the
    // catalog does not have -- necessary, not a nicety: the catalogs have gaps
    // (llama3.2-vision is missing entirely) and the generic provider has no catalog at
    // all, so a closed select would lock out model names that work. The select renders
    // as the field's prefix, which the core places inside the same <dd>
    // (core/fragments/core/form/form.php).
    $field = $form->addTextField('model');
    $field->setLabel(rex_i18n::msg('ai_platform_model'));
    $field->setAttribute('class', 'form-control');
    $field->setAttribute('autocomplete', 'off');
    $field->setAttribute('placeholder', rex_i18n::msg('ai_platform_model_placeholder'));
    $field->setPrefix(
        '<select id="ai-model-select" class="form-control selectpicker ai-model-select" data-custom-label="'
        . rex_escape(rex_i18n::msg('ai_platform_model_custom_option'), 'html_attr')
        . '"></select>',
    );
    $field->setNotice(rex_i18n::msg('ai_platform_model_notice'));

    // --- Type-specific fields ---

    $isAdd = 'add' === $func;

    // Temperature (text + image_understanding)
    $field = $form->addTextField('temperature');
    $field->setLabel(rex_i18n::msg('ai_platform_temperature'));
    $field->setAttribute('class', 'form-control');
    $field->setAttribute('type', 'number');
    $field->setAttribute('step', '0.1');
    $field->setAttribute('min', '0');
    $field->setAttribute('max', '2');
    $field->setNotice(rex_i18n::msg('ai_platform_temperature_notice'));
    if ($isAdd) {
        $field->setValue('1.0');
    }

    // Max Tokens (text + image_understanding)
    $field = $form->addTextField('max_tokens');
    $field->setLabel(rex_i18n::msg('ai_platform_max_tokens'));
    $field->setAttribute('class', 'form-control');
    $field->setAttribute('type', 'number');
    $field->setAttribute('min', '1');
    $field->setAttribute('max', '200000');
    $field->setNotice(rex_i18n::msg('ai_platform_max_tokens_notice'));
    if ($isAdd) {
        $field->setValue('4096');
    }

    // System Prompt (text only)
    $field = $form->addTextAreaField('system_prompt');
    $field->setLabel(rex_i18n::msg('ai_platform_system_prompt'));
    $field->setAttribute('class', 'form-control');
    $field->setAttribute('rows', '4');
    $field->setNotice(rex_i18n::msg('ai_platform_system_prompt_notice'));

    // Image Size (image_generation only)
    $field = $form->addSelectField('image_size');
    $field->setLabel(rex_i18n::msg('ai_platform_image_size'));
    $select = $field->getSelect();
    $select->addOption(rex_i18n::msg('ai_platform_default'), '');
    $select->addOption('1024x1024', '1024x1024');
    $select->addOption('1792x1024 (Landscape)', '1792x1024');
    $select->addOption('1024x1792 (Portrait)', '1024x1792');
    $select->addOption('512x512', '512x512');
    $select->addOption('256x256', '256x256');
    $field->setAttribute('class', 'form-control selectpicker');
    if ($isAdd) {
        $select->setSelected('1024x1024');
    }

    // Image Quality (image_generation only)
    $field = $form->addSelectField('image_quality');
    $field->setLabel(rex_i18n::msg('ai_platform_image_quality'));
    $select = $field->getSelect();
    $select->addOption(rex_i18n::msg('ai_platform_default'), '');
    $select->addOption('Standard', 'standard');
    $select->addOption('HD', 'hd');
    $field->setAttribute('class', 'form-control selectpicker');
    if ($isAdd) {
        $select->setSelected('standard');
    }

    // Image Style (image_generation only)
    $field = $form->addSelectField('image_style');
    $field->setLabel(rex_i18n::msg('ai_platform_image_style'));
    $select = $field->getSelect();
    $select->addOption(rex_i18n::msg('ai_platform_default'), '');
    $select->addOption('Vivid', 'vivid');
    $select->addOption('Natural', 'natural');
    $field->setAttribute('class', 'form-control selectpicker');
    if ($isAdd) {
        $select->setSelected('vivid');
    }

    // Detail Level (image_understanding only)
    $field = $form->addSelectField('detail_level');
    $field->setLabel(rex_i18n::msg('ai_platform_detail_level'));
    $select = $field->getSelect();
    $select->addOption(rex_i18n::msg('ai_platform_default'), '');
    $select->addOption('Auto', 'auto');
    $select->addOption('Low', 'low');
    $select->addOption('High', 'high');
    $field->setAttribute('class', 'form-control selectpicker');
    if ($isAdd) {
        $select->setSelected('auto');
    }

    // Status
    $field = $form->addSelectField('status');
    $field->setLabel(rex_i18n::msg('ai_platform_status'));
    $select = $field->getSelect();
    $select->addOption(rex_i18n::msg('ai_platform_status_active'), '1');
    $select->addOption(rex_i18n::msg('ai_platform_status_inactive'), '0');
    $field->setAttribute('class', 'form-control selectpicker');

    // Which fields a provider needs, its default model per type and the catalog
    // suggestions -- all of it from ProviderRegistry, so assets/profiles.js carries no
    // provider knowledge of its own. Written here rather than via
    // rex_view::setJsProperty() because that renders in the head, which is already out
    // the door by the time a page script runs.
    $content = $form->get()
        . '<script type="application/json" id="ai-provider-config">'
        . json_encode(ProviderRegistry::formConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
        . '</script>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $title, false);
    $fragment->setVar('body', $content, false);
    echo $fragment->parse('core/page/section.php');

    // Show test button for existing profiles
    if ('edit' === $func && $id > 0) {
        $testUrl = rex_url::backendController([
            'rex-api-call' => 'ai_test',
            'profile_id' => $id,
        ], false);

        $testContent = '
        <div class="row">
            <div class="col-sm-offset-3 col-sm-9">
                <button type="button" class="btn btn-default" id="ai-test-btn">
                    <i class="rex-icon fa-bolt"></i> ' . rex_i18n::msg('ai_platform_test_connection') . '
                </button>
                <span id="ai-test-spinner" style="display:none; margin-left:10px;">
                    <i class="rex-icon fa-spinner fa-spin"></i> ' . rex_i18n::msg('ai_platform_test_running') . '
                </span>
            </div>
        </div>
        <div id="ai-test-result" style="margin-top:15px;"></div>
        <script>
        document.getElementById("ai-test-btn").addEventListener("click", function() {
            var btn = this;
            var spinner = document.getElementById("ai-test-spinner");
            var resultDiv = document.getElementById("ai-test-result");
            btn.disabled = true;
            spinner.style.display = "inline";
            resultDiv.innerHTML = "";
            fetch("' . $testUrl . '", {credentials: "same-origin"})
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    spinner.style.display = "none";
                    var cls = data.success ? "alert alert-success" : "alert alert-danger";
                    var html = "<div class=\"" + cls + "\"><strong>" + data.message + "</strong>";
                    if (data.details) {
                        html += "<br><small>";
                        html += "Provider: " + data.details.provider;
                        html += " | Model: " + data.details.model;
                        if (data.details.response) {
                            html += "<br>Response: <code>" + data.details.response + "</code>";
                        }
                        if (data.details.error) {
                            html += "<br>Error: <code>" + data.details.error + "</code>";
                        }
                        html += "</small>";
                    }
                    html += "</div>";
                    resultDiv.innerHTML = html;
                })
                .catch(function(err) {
                    btn.disabled = false;
                    spinner.style.display = "none";
                    resultDiv.innerHTML = "<div class=\"alert alert-danger\"><strong>Request failed:</strong> " + err.message + "</div>";
                });
        });
        </script>';

        $fragment = new rex_fragment();
        $fragment->setVar('class', 'edit', false);
        $fragment->setVar('title', rex_i18n::msg('ai_platform_test_title'), false);
        $fragment->setVar('body', $testContent, false);
        echo $fragment->parse('core/page/section.php');
    }
} else {
    // Handle delete before rendering list
    if ('delete' === $func && $id > 0) {
        $sql = rex_sql::factory();
        $sql->setQuery('DELETE FROM ' . rex::getTable('ai_profile') . ' WHERE id = ?', [$id]);
        echo rex_view::success(rex_i18n::msg('ai_platform_profile_deleted'));
    }

    // List view
    $list = rex_list::factory('SELECT id, name, type, provider, model, status FROM ' . rex::getTable('ai_profile') . ' ORDER BY type, name');
    $list->addTableAttribute('class', 'table-striped');

    $list->addTableColumnGroup([40, '*', 130, '*', '*', 100, 90]);

    $thIcon = '<a href="' . $list->getUrl(['func' => 'add']) . '"><i class="rex-icon rex-icon-add-action"></i></a>';
    $tdIcon = '<i class="rex-icon fa-key"></i>';
    $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
    $list->setColumnParams($thIcon, ['func' => 'edit', 'id' => '###id###']);

    $list->setColumnLabel('name', rex_i18n::msg('ai_platform_profile_name'));
    $list->setColumnParams('name', ['func' => 'edit', 'id' => '###id###']);

    $list->setColumnLabel('type', rex_i18n::msg('ai_platform_type'));
    $list->setColumnFormat('type', 'custom', static function ($params) {
        $types = Service::getTypes();
        return $types[$params['value']] ?? $params['value'];
    });

    $list->setColumnLabel('provider', rex_i18n::msg('ai_platform_provider'));
    $list->setColumnFormat('provider', 'custom', static function ($params) {
        $providers = Service::getProviders();
        return $providers[$params['value']] ?? $params['value'];
    });

    $list->setColumnLabel('model', rex_i18n::msg('ai_platform_model'));

    $list->setColumnLabel('status', rex_i18n::msg('ai_platform_status'));
    $list->setColumnFormat('status', 'custom', static function ($params) {
        return 1 == $params['value']
            ? '<span class="text-success">' . rex_i18n::msg('ai_platform_status_active') . '</span>'
            : '<span class="text-danger">' . rex_i18n::msg('ai_platform_status_inactive') . '</span>';
    });

    // Delete column
    $list->addColumn(rex_i18n::msg('ai_platform_delete'), '<i class="rex-icon rex-icon-delete"></i>', -1, ['<th>' . rex_i18n::msg('ai_platform_actions') . '</th>', '<td>###VALUE###</td>']);
    $list->setColumnParams(rex_i18n::msg('ai_platform_delete'), ['func' => 'delete', 'id' => '###id###']);
    $list->addLinkAttribute(rex_i18n::msg('ai_platform_delete'), 'data-confirm', rex_i18n::msg('ai_platform_delete_confirm'));

    $content = $list->get();

    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('ai_platform_profiles'), false);
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
}
