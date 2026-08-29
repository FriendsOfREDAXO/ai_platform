<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Diff;

use rex_i18n;

use function count;
use function max;
use function mb_strlen;
use function rex_escape;

/**
 * Renders a change request's diff as backend HTML.
 *
 * Everything is escaped. That is not routine hygiene here: payload values come
 * from an LLM or an external adapter and are shown to a logged-in backend user
 * with full rights. A proposal is free to contain `<script>`, so the diff must
 * never emit raw content. For the same reason there is no rendered preview of a
 * slice's module output — it would be a script injection channel in exchange
 * for cosmetics.
 *
 * Long or multi-line values get a line-based diff (a small LCS, no external
 * library) so a reviewer can see which paragraph moved rather than re-reading
 * two blocks of text.
 *
 * Unlike the rest of lib/Change, this class emits HTML, so it uses
 * rex_i18n::msg() — which escapes — rather than rawMsg(). Its siblings return
 * plain text for a caller to escape; here the caller is the page, and the
 * output goes straight through.
 */
final class DiffRenderer
{
    /** Values longer than this are rendered as a block diff rather than a cell. */
    private const INLINE_LIMIT = 120;

    /**
     * @param list<DiffField> $fields
     * @param bool $showCurrent Render the three-column base / proposed / current
     *        view, for a target that moved after the proposal was made
     */
    public static function render(array $fields, bool $showCurrent = false): string
    {
        if ([] === $fields) {
            return '<p class="text-muted">' . rex_i18n::msg('ai_platform_change_diff_empty') . '</p>';
        }

        $rows = '';
        foreach ($fields as $field) {
            $rows .= self::renderRow($field, $showCurrent);
        }

        $head = '<th class="ai-diff-field">' . rex_i18n::msg('ai_platform_change_diff_field') . '</th>'
            . '<th>' . rex_i18n::msg('ai_platform_change_diff_before') . '</th>'
            . '<th>' . rex_i18n::msg('ai_platform_change_diff_after') . '</th>';

        if ($showCurrent) {
            $head .= '<th>' . rex_i18n::msg('ai_platform_change_diff_current') . '</th>';
        }

        return '<div class="table-responsive"><table class="table table-striped ai-diff-table">'
            . '<thead><tr>' . $head . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table></div>';
    }

    private static function renderRow(DiffField $field, bool $showCurrent): string
    {
        $label = '<th scope="row">' . rex_escape($field->label);
        if ($field->label !== $field->key) {
            $label .= '<br><small class="text-muted"><code>' . rex_escape($field->key) . '</code></small>';
        }
        if (null !== $field->note) {
            $label .= '<br><small class="text-muted">' . rex_escape($field->note) . '</small>';
        }
        $label .= '</th>';

        $long = $field->longText
            || mb_strlen((string) $field->before) > self::INLINE_LIMIT
            || mb_strlen((string) $field->after) > self::INLINE_LIMIT;

        if ($long) {
            $cells = '<td colspan="' . ($showCurrent ? 3 : 2) . '">'
                . self::renderLineDiff($field->before, $field->after)
                . ($showCurrent && $field->isConflicting()
                    ? '<div class="ai-diff-conflict"><strong>' . rex_i18n::msg('ai_platform_change_diff_current') . ':</strong> '
                        . self::renderValue($field->current) . '</div>'
                    : '')
                . '</td>';
        } else {
            $cells = '<td class="ai-diff-before">' . self::renderValue($field->before) . '</td>'
                . '<td class="ai-diff-after">' . self::renderValue($field->after) . '</td>';

            if ($showCurrent) {
                $conflict = $field->isConflicting() ? ' ai-diff-conflict' : '';
                $cells .= '<td class="ai-diff-current' . $conflict . '">' . self::renderValue($field->current) . '</td>';
            }
        }

        return '<tr class="' . $field->cssClass() . '">' . $label . $cells . '</tr>';
    }

    private static function renderValue(?string $value): string
    {
        if (null === $value) {
            return '<span class="text-muted">—</span>';
        }
        if ('' === trim($value)) {
            return '<span class="text-muted">' . rex_i18n::msg('ai_platform_change_diff_empty_value') . '</span>';
        }

        return '<span class="ai-diff-value">' . nl2br(rex_escape($value)) . '</span>';
    }

    /**
     * Line-based diff of two blocks of text.
     */
    private static function renderLineDiff(?string $before, ?string $after): string
    {
        $beforeLines = self::splitLines($before);
        $afterLines = self::splitLines($after);

        $html = '<pre class="ai-diff-block">';
        foreach (self::diffLines($beforeLines, $afterLines) as [$marker, $line]) {
            $class = match ($marker) {
                '+' => 'ai-diff-line-add',
                '-' => 'ai-diff-line-del',
                default => 'ai-diff-line-same',
            };
            $html .= '<span class="' . $class . '">' . rex_escape($marker . ' ' . $line) . "</span>\n";
        }

        return $html . '</pre>';
    }

    /**
     * @return list<string>
     */
    private static function splitLines(?string $value): array
    {
        if (null === $value || '' === $value) {
            return [];
        }

        return preg_split('/\R/u', $value) ?: [];
    }

    /**
     * Longest-common-subsequence diff over lines.
     *
     * Deliberately hand-rolled: the addon ships its whole vendor tree to
     * production, and a diff library would be a lot of weight for forty lines
     * of table filling. Falls back to "everything replaced" beyond a size where
     * the quadratic table stops being reasonable — a reviewer reading a
     * thousand-line field is not helped by a per-line diff anyway.
     *
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{string, string}> marker (' ', '-', '+') and line
     */
    private static function diffLines(array $before, array $after): array
    {
        $countBefore = count($before);
        $countAfter = count($after);

        if ($countBefore * $countAfter > 250_000) {
            $result = [];
            foreach ($before as $line) {
                $result[] = ['-', $line];
            }
            foreach ($after as $line) {
                $result[] = ['+', $line];
            }

            return $result;
        }

        // lcs[i][j] = length of the longest common subsequence of the first i
        // lines of $before and the first j lines of $after.
        $lcs = array_fill(0, $countBefore + 1, array_fill(0, $countAfter + 1, 0));
        for ($i = $countBefore - 1; $i >= 0; --$i) {
            for ($j = $countAfter - 1; $j >= 0; --$j) {
                $lcs[$i][$j] = $before[$i] === $after[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $result = [];
        $i = 0;
        $j = 0;
        while ($i < $countBefore && $j < $countAfter) {
            if ($before[$i] === $after[$j]) {
                $result[] = [' ', $before[$i]];
                ++$i;
                ++$j;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $result[] = ['-', $before[$i]];
                ++$i;
            } else {
                $result[] = ['+', $after[$j]];
                ++$j;
            }
        }
        while ($i < $countBefore) {
            $result[] = ['-', $before[$i]];
            ++$i;
        }
        while ($j < $countAfter) {
            $result[] = ['+', $after[$j]];
            ++$j;
        }

        return $result;
    }

    /**
     * Compact one-line summary for the list view: "3 Felder, 1 neu".
     *
     * @param list<DiffField> $fields
     */
    public static function summarise(array $fields): string
    {
        $changed = 0;
        $added = 0;
        $removed = 0;

        foreach ($fields as $field) {
            match ($field->kind) {
                DiffField::KIND_ADDED => ++$added,
                DiffField::KIND_REMOVED => ++$removed,
                DiffField::KIND_CHANGED => ++$changed,
                default => null,
            };
        }

        $parts = [];
        if ($changed > 0) {
            $parts[] = rex_i18n::msg('ai_platform_change_summary_changed', $changed);
        }
        if ($added > 0) {
            $parts[] = rex_i18n::msg('ai_platform_change_summary_added', $added);
        }
        if ($removed > 0) {
            $parts[] = rex_i18n::msg('ai_platform_change_summary_removed', $removed);
        }

        return [] === $parts ? rex_i18n::msg('ai_platform_change_summary_none') : implode(', ', $parts);
    }
}
