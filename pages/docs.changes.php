<?php

/**
 * Documentation tab for change requests.
 *
 * Renders the same file that agents read as a skill
 * (`.claude/skills/ai-platform-changes/SKILL.md`), so there is one source for
 * both audiences. Two copies of this text would drift apart, and the version an
 * agent acts on is the one that has to be right.
 *
 * The YAML front matter is stripped — it addresses the agent runtime, not a
 * reader.
 */

declare(strict_types=1);

$addon = rex_addon::get('ai_platform');

// Skill path first, docs/ as a fallback: dot-directories are occasionally
// dropped by release packagers, and the tab should still say something then.
$candidates = [
    '.claude/skills/ai-platform-changes/SKILL.md',
    'docs/change-requests.md',
];

$content = null;
foreach ($candidates as $candidate) {
    $content = rex_file::get($addon->getPath($candidate));
    if (null !== $content && '' !== trim($content)) {
        break;
    }
}

if (null === $content || '' === trim($content)) {
    echo rex_view::warning(rex_i18n::msg('ai_platform_changes_docs_missing', implode(', ', $candidates)));

    return;
}

// Strip the front matter block.
$content = preg_replace('/\A---\R.*?\R---\R/s', '', $content) ?? $content;

[$toc, $body] = rex_markdown::factory()->parseWithToc($content, 2, 3, [
    rex_markdown::SOFT_LINE_BREAKS => false,
    rex_markdown::HIGHLIGHT_PHP => true,
]);

// Where the same text lives for agents, so nobody edits the rendered copy.
$hint = rex_view::info(
    rex_i18n::msg('ai_platform_changes_docs_source', '<code>.claude/skills/ai-platform-changes/SKILL.md</code>'),
);

$fragment = new rex_fragment();
$fragment->setVar('content', $hint . $body, false);
$fragment->setVar('toc', $toc, false);
echo $fragment->parse('core/page/docs.php');
