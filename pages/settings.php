<?php

/**
 * Standardprofil je Typ und Zeitgrenzen der Anfragen.
 *
 * @var Addon $this
 */

use FriendsOfRedaxo\AiPlatform\Backend\SettingsData;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Form\Form;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\View\Component\Section;
use Redaxo\Core\View\Component\SectionVariant;
use Redaxo\Core\View\Html;
use Redaxo\Core\View\Message;

$addon = $this;
$data = SettingsData::fromConfig($addon);
$form = new Form($data, name: 'ai_platform_settings', title: $addon->i18n('settings'));

if ($form->handleRequest() && $form->isValid()) {
    $data->save($addon);
    Response::sendRedirect(Url::currentBackendPage(['info' => 'saved']));
}

if ('saved' === Request::get('info', 'string', '')) {
    echo Message::success($addon->i18n('settings_saved'));
}

echo $form->capture(static function (Form $form) use ($addon): void {
    $body = Html::capture(static function () use ($form, $addon): void {
        foreach ($form->getFormErrors() as $error) {
            echo Message::error((string) Html::from($error));
        }
        echo '<p>', $addon->i18n('settings_defaults_intro'), '</p>';
        echo $form->rest();
    });
    echo new Section(
        $form->title ?? '',
        $body,
        footer: Html::raw('<div class="rex-form-panel-footer">' . $form->buttons() . '</div>'),
        variant: SectionVariant::Edit,
    )->render();
});
