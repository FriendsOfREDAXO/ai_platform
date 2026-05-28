<?php

declare(strict_types=1);

$addon = rex_addon::get('ai_platform');
$service = rex_ai_platform_service::getInstance();
$csrfToken = rex_csrf_token::factory('ai_platform_settings');

// Handle form submission
if ('post' === rex_request::requestMethod() && $csrfToken->isValid()) {
    rex_config::set('ai_platform', 'default_text_profile', rex_post('default_text_profile', 'int', 0));
    rex_config::set('ai_platform', 'default_image_generation_profile', rex_post('default_image_generation_profile', 'int', 0));
    rex_config::set('ai_platform', 'default_image_understanding_profile', rex_post('default_image_understanding_profile', 'int', 0));

    echo rex_view::success(rex_i18n::msg('ai_platform_settings_saved'));
}

$defaultText = (int) rex_config::get('ai_platform', 'default_text_profile', 0);
$defaultImageGen = (int) rex_config::get('ai_platform', 'default_image_generation_profile', 0);
$defaultImageUnderstanding = (int) rex_config::get('ai_platform', 'default_image_understanding_profile', 0);

$types = [
    'text' => ['config_key' => 'default_text_profile', 'current' => $defaultText, 'label' => rex_i18n::msg('ai_platform_default_text'), 'notice' => rex_i18n::msg('ai_platform_default_text_notice')],
    'image_generation' => ['config_key' => 'default_image_generation_profile', 'current' => $defaultImageGen, 'label' => rex_i18n::msg('ai_platform_default_image_generation'), 'notice' => rex_i18n::msg('ai_platform_default_image_generation_notice')],
    'image_understanding' => ['config_key' => 'default_image_understanding_profile', 'current' => $defaultImageUnderstanding, 'label' => rex_i18n::msg('ai_platform_default_image_understanding'), 'notice' => rex_i18n::msg('ai_platform_default_image_understanding_notice')],
];

$formFields = '';
foreach ($types as $type => $config) {
    $profiles = $service->getProfiles($type);
    $options = '<option value="0">' . rex_i18n::msg('ai_platform_please_select') . '</option>';
    foreach ($profiles as $profile) {
        $providers = rex_ai_platform_service::getProviders();
        $providerLabel = $providers[$profile['provider']] ?? $profile['provider'];
        $label = rex_escape($profile['name'] . ' (' . $providerLabel . ' - ' . $profile['model'] . ')');
        $selected = (int) $profile['id'] === $config['current'] ? ' selected' : '';
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

$content = '
<form action="' . rex_url::currentBackendPage() . '" method="post">
    ' . $csrfToken->getHiddenField() . '
    <fieldset>
        <legend>' . rex_i18n::msg('ai_platform_default_models') . '</legend>
        ' . $formFields . '
    </fieldset>

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
