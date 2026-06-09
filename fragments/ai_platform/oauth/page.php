<?php

/**
 * Outer HTML shell for the standalone OAuth pages (login / consent / error).
 *
 * Rendered outside REDAXO's normal frontend by FriendsOfRedaxo\AiPlatform\OAuth\AuthorizationEndpoint.
 * Override by placing a file with the same path in a fragments dir that loads
 * later (e.g. project addon: project/fragments/ai_platform/oauth/page.php).
 *
 * @var rex_fragment $this
 * @psalm-scope-this rex_fragment
 *
 * Vars:
 * - string title    Page title (already i18n-translated)
 * - string content  Inner HTML (raw — produced by login/consent/error fragment)
 */

$title = (string) $this->getVar('title', '');
$content = (string) $this->getVar('content', '');
?>
<!doctype html>
<html lang="<?= rex_escape(substr(rex_i18n::getLocale(), 0, 2)) ?>">
<head>
    <meta charset="utf-8">
    <title><?= $title ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style nonce="<?= rex_response::getNonce() ?>">
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               margin: 0; background: #f4f6f8; color: #1f2933; }
        .wrap { max-width: 32rem; margin: 4rem auto; padding: 2rem;
                background: #fff; border-radius: 6px; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        p { margin: 0 0 1rem; line-height: 1.5; }
        label { display: block; margin: 0 0 1rem; }
        input[type=text], input[type=password] { width: 100%; padding: .5rem .6rem;
                border: 1px solid #cbd2d9; border-radius: 4px; font-size: 1rem;
                box-sizing: border-box; margin-top: .25rem; }
        button { padding: .55rem 1rem; border-radius: 4px; border: 1px solid #cbd2d9;
                 background: #fff; color: #1f2933; cursor: pointer; margin-right: .5rem; }
        button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .error { color: #b42318; background: #fef3f2; padding: .5rem .75rem;
                 border-radius: 4px; border: 1px solid #fecdca; }
        .warning { color: #92400e; background: #fffaeb; padding: .5rem .75rem;
                   border-radius: 4px; border: 1px solid #fde68a; }
        .muted { color: #6b7280; font-size: .9rem; }
        ul.scopes { list-style: none; padding: 0; margin: 0 0 1rem; }
        ul.scopes li { padding: .35rem 0; border-bottom: 1px solid #f0f2f4; }
        code { font-family: ui-monospace, SFMono-Regular, monospace; background: #f4f6f8;
               padding: .15rem .3rem; border-radius: 3px; }
    </style>
</head>
<body><div class="wrap"><?= $content ?></div></body>
</html>
