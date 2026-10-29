<?php

/**
 * Dispatcher for the change request pages.
 *
 * Refuses everything while the feature is switched off. The menu entry is
 * already removed in that case (see ChangeService::preparePages()), but removing
 * a page from the navigation is not the same as making it unreachable: a
 * bookmark or a hand-typed `?page=ai_changes` still arrives here. A feature that is off has to be off on every route into it, not
 * just the one through the menu.
 *
 * The check runs before rex_view::title(), which builds the sub navigation from
 * the current page object — and that object is gone once the page has been
 * removed from the list, so calling it first would fatal instead of explaining.
 *
 * There are no subpages any more: settings and guide moved under `ai_platform`,
 * where the rest of the addon's administration and documentation lives. What is
 * left is the inbox, which is the only part an editor needs.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Change\ChangeService;

if (!ChangeService::getInstance()->isEnabled()) {
    $hint = rex_i18n::msg('ai_platform_changes_disabled_page');

    if (rex::requireUser()->isAdmin()) {
        $hint .= ' <a href="' . rex_url::backendPage('ai_platform/settings') . '">'
            . rex_i18n::msg('ai_platform_changes_disabled_page_admin_link') . '</a>';
    }

    echo rex_view::warning($hint);

    return;
}

echo rex_view::title(rex_i18n::msg('ai_platform_changes_title'));

// pjax keeps the scroll position on a link by default (see `pjaxHandler` in
// the core's standard.js). Opening a request from the bottom of a long list
// would then land at the bottom of the detail view, with its header and diff
// out of sight. `data-pjax-scroll-to` makes every link and form in the inbox
// start at the top of the page.
echo '<div data-pjax-scroll-to="0">';
require __DIR__ . '/ai_changes.list.php';
echo '</div>';
