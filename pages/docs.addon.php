<?php

declare(strict_types=1);

$file = rex_addon::get('ai_platform')->getPath('README.md');
$content = rex_file::get($file);

if ($content) {
    [$toc, $body] = rex_markdown::factory()->parseWithToc($content, 2, 3, [
        rex_markdown::SOFT_LINE_BREAKS => false,
        rex_markdown::HIGHLIGHT_PHP => true,
    ]);

    $fragment = new rex_fragment();
    $fragment->setVar('content', $body, false);
    $fragment->setVar('toc', $toc, false);
    echo $fragment->parse('core/page/docs.php');
} else {
    echo rex_view::warning('README.md nicht gefunden.');
}
