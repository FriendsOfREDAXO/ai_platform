<?php

/**
 * Profile anlegen, bearbeiten, testen und löschen.
 *
 * @var Addon $this
 */

use FriendsOfRedaxo\AiPlatform\AiPlatformAddon;
use FriendsOfRedaxo\AiPlatform\Api\TestConnectionFunction;
use FriendsOfRedaxo\AiPlatform\Backend\ProfileData;
use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use FriendsOfRedaxo\AiPlatform\Service;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Backend\Accesskey;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Form\Form;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\Security\CsrfToken;
use Redaxo\Core\Translation\I18n;
use Redaxo\Core\View\Component\Button;
use Redaxo\Core\View\Component\Section;
use Redaxo\Core\View\Component\SectionVariant;
use Redaxo\Core\View\Component\TableColumnType;
use Redaxo\Core\View\DataList;
use Redaxo\Core\View\Html;
use Redaxo\Core\View\HtmlAttributes;
use Redaxo\Core\View\Message;

use function Redaxo\Core\View\escape;

$addon = $this;
$func = Request::request('func', 'string');
$id = Request::request('id', 'int', 0);
$csrf = CsrfToken::factory('ai_platform_profile');
$table = AiPlatformAddon::TABLE_PROFILE;

if ('delete' === $func) {
    if (!$csrf->isValid()) {
        echo Message::error(I18n::msg('csrf_token_invalid'));
    } else {
        Sql::factory()->setQuery('DELETE FROM ' . $table . ' WHERE id = ? LIMIT 1', [$id]);
        echo Message::success($addon->i18n('profile_deleted'));
    }
    $func = '';
}

if ('add' === $func || 'edit' === $func) {
    $row = 'edit' === $func ? (Sql::factory()->getArray('SELECT * FROM ' . $table . ' WHERE id = ?', [$id])[0] ?? null) : null;
    $data = null !== $row ? ProfileData::fromRow($row) : new ProfileData();

    $form = new Form(
        $data,
        name: 'ai_profile',
        title: null !== $row ? $addon->i18n('profile_edit') : $addon->i18n('profile_add'),
        cancelUrl: Url::currentBackendPage(),
    );

    if ($form->handleRequest()) {
        if ([] !== Sql::factory()->getArray('SELECT id FROM ' . $table . ' WHERE name = ? AND id <> ?', [trim($data->name), null !== $row ? $id : 0])) {
            $form->addError($addon->i18n('profile_name_exists'), 'name');
        }
        if (null !== $data->apiKeyEnv && '' !== trim($data->apiKeyEnv) && 1 !== preg_match('/^[A-Z][A-Z0-9_]*$/', trim($data->apiKeyEnv))) {
            $form->addError($addon->i18n('api_key_env_invalid'), 'apiKeyEnv');
        }
        if (!isset(Service::getProviders()[$data->provider])) {
            $form->addError($addon->i18n('provider_unknown'), 'provider');
        }

        if ($form->isValid()) {
            $sql = Sql::factory();
            $sql->setTable($table);
            $sql->setValues($data->toRow());
            if (null !== $row) {
                $sql->setWhere(['id' => $id]);
                $sql->addGlobalUpdateFields();
                $sql->update();
            } else {
                $sql->addGlobalCreateFields();
                $sql->addGlobalUpdateFields();
                $sql->insert();
                $id = (int) $sql->getLastId();
            }
            Response::sendRedirect(Url::currentBackendPage(['func' => 'edit', 'id' => $id, 'info' => null !== $row ? 'updated' : 'added']));
        }
    }

    $info = Request::get('info', 'string', '');
    if ('added' === $info || 'updated' === $info) {
        echo Message::success($addon->i18n('profile_' . $info));
    }

    // Was zu Typ und Anbieter gehört, steht in ProviderRegistry; profiles.js liest es von hier und kennt selbst keinen
    // Anbieter. Neben dem Formular statt im Kopf, weil der Kopf schon gesendet ist, wenn das Skript läuft.
    $config = '<script type="application/json" id="ai-provider-config">'
        . json_encode(ProviderRegistry::formConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';

    echo $form->capture(static function (Form $form) use ($config, $row, $addon): void {
        $body = Html::capture(static function () use ($form, $config, $row, $addon): void {
            foreach ($form->getFormErrors() as $error) {
                echo Message::error((string) Html::from($error));
            }
            echo $form->row('name'), $form->row('type'), $form->row('provider');
            echo '<div data-ai-field="api_key">', $form->row('apiKeyEnv');
            if (null !== $row) {
                $env = ProviderRegistry::apiKeyEnv($row);
                [$state, $message] = match (true) {
                    ProviderRegistry::hasApiKey($row) => ['is-set', 'api_key_env_set'],
                    ProviderRegistry::requiresApiKey($row) => ['is-missing', 'api_key_env_missing'],
                    default => ['is-optional', 'api_key_env_unset_optional'],
                };
                echo '<p class="ai-env-status ', $state, '">', $addon->i18n($message, $env), '</p>';
            }
            echo '</div>';
            echo '<div data-ai-field="base_url">', $form->row('baseUrl'), '</div>';
            echo $form->row('model');
            foreach (['temperature', 'maxTokens', 'systemPrompt', 'imageSize', 'imageQuality', 'imageStyle', 'detailLevel'] as $field) {
                echo '<div data-ai-field="', escape(strtolower((string) preg_replace('/[A-Z]/', '_$0', $field))), '">', $form->row($field), '</div>';
            }
            echo $form->rest();
            echo $config;
        });
        echo new Section(
            $form->title ?? '',
            $body,
            footer: Html::raw('<div class="rex-form-panel-footer">' . $form->buttons() . '</div>'),
            variant: SectionVariant::Edit,
        )->render();
    });

    if (null !== $row) {
        $test = '<div class="ai-test" data-ai-test-url="' . escape(TestConnectionFunction::getTestUrl($id)) . '">'
            . new Button(Html::raw($addon->i18n('test_connection')), attributes: new HtmlAttributes(['data-ai-test' => true]))->render()
            . '<span class="ai-test-running" hidden> ' . $addon->i18n('test_running') . '</span>'
            . '<div class="ai-test-result" aria-live="polite"></div></div>';
        echo new Section($addon->i18n('test_title'), Html::raw($test))->render();
    }

    return;
}

$list = new DataList('SELECT id, name, type, provider, model, api_key_env, status FROM ' . $table . ' ORDER BY type, name', rowsPerPage: 100);
$list->addParam('page', 'ai_platform/profiles');
$list->empty = $addon->i18n('profiles_empty');
$editUrl = static fn (array $row): string => $list->getUrl(['func' => 'edit', 'id' => $row['id']]);
$types = Service::getTypes();
$providers = Service::getProviders();
$defaults = [];
foreach (array_keys($types) as $type) {
    $defaults[(int) $addon->getConfig('default_' . $type . '_profile', 0)][] = $types[$type];
}

$list->column(
    'icon',
    Html::raw('<a href="' . escape($list->getUrl(['func' => 'add'])) . '"' . Accesskey::attributes(I18n::rawMsg('ai_platform_profile_add'), 'add') . '><i class="rex-icon rex-icon-add" aria-hidden="true"></i></a>'),
    static fn (array $row): Html => Html::raw('<i class="rex-icon fa-brain" aria-hidden="true"></i>'),
    type: TableColumnType::Icon,
    link: $editUrl,
);
$list->column('name', $addon->i18n('profile_name'), sortable: true, link: $editUrl);
$list->column('type', $addon->i18n('type'), static function (array $row) use ($types, $defaults): Html {
    $label = escape($types[$row['type']] ?? (string) $row['type']);
    if (in_array($types[$row['type']] ?? null, $defaults[(int) $row['id']] ?? [], true)) {
        $label .= ' <span class="rex-u-text-muted">· ' . I18n::msg('ai_platform_default') . '</span>';
    }

    return Html::raw($label);
});
$list->column('provider', $addon->i18n('provider'), static fn (array $row): string => $providers[$row['provider']] ?? (string) $row['provider']);
$list->column('model', $addon->i18n('model'));
$list->column('api_key_env', $addon->i18n('api_key_env'), static function (array $row) use ($addon): Html {
    $needsKey = ProviderRegistry::requiresApiKey($row);
    $env = ProviderRegistry::apiKeyEnv($row);
    if (ProviderRegistry::hasApiKey($row)) {
        return Html::raw('<code>' . escape($env) . '</code> <span class="rex-u-text-success">✔</span>');
    }

    return Html::raw('<code>' . escape($env) . '</code> ' . ($needsKey ? '<span class="rex-u-text-danger">' . $addon->i18n('api_key_env_missing_short') . '</span>' : '<span class="rex-u-text-muted">' . $addon->i18n('api_key_env_optional') . '</span>'));
});
$list->column(
    'status',
    $addon->i18n('status'),
    static fn (array $row): Html => 1 == $row['status']
        ? Html::raw('<span class="rex-u-text-success">' . I18n::msg('ai_platform_status_active') . '</span>')
        : Html::raw('<span class="rex-u-text-danger">' . I18n::msg('ai_platform_status_inactive') . '</span>'),
);
$list->column(
    'delete',
    I18n::msg('function'),
    static fn (): Html => Html::raw('<i class="rex-icon rex-icon-delete" aria-hidden="true"></i> ' . I18n::msg('delete')),
    type: TableColumnType::Action,
    link: static fn (array $row): string => $list->getUrl(['id' => $row['id'], 'func' => 'delete'] + $csrf->getUrlParams()),
    linkAttributes: ['data-confirm' => $addon->i18n('delete_confirm')],
);

echo new Section($addon->i18n('profiles'), content: $list)->render();
