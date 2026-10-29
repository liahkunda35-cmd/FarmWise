<?php
/**
 * Front-end display helpers (views-only). Safe to require multiple times.
 */

if (!function_exists('fe_require')) {
    function fe_require(): void
    {
        // no-op marker
    }
}

if (!function_exists('fe_planting_label')) {
    function fe_planting_label(?array $p): string
    {
        if (!$p) {
            return '—';
        }
        $crop = trim((string) ($p['crop_name'] ?? ''));
        $variety = trim((string) ($p['variety'] ?? ''));
        $field = trim((string) ($p['field_name'] ?? ''));
        $season = trim((string) ($p['season'] ?? ''));
        $head = $crop !== '' ? $crop : 'Planting';
        if ($variety !== '') {
            $head .= ' (' . $variety . ')';
        }
        $parts = [$head];
        if ($field !== '') {
            $parts[] = $field;
        }
        if ($season !== '') {
            $parts[] = $season;
        }
        return implode(' — ', $parts);
    }
}

if (!function_exists('fe_display')) {
    /**
     * Human-readable field value; relations become linked HTML when possible.
     */
    function fe_display(array $field, $value, bool $asHtml = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (($field['type'] ?? '') === 'rel' && !empty($field['table'])) {
            $label = fe_rel_label($field['table'], $value);
            $href = fe_rel_href($field['table'], $value);
            if ($asHtml && $href) {
                return '<a class="rel-link" href="' . e($href) . '">' . e($label) . '</a>';
            }
            return $asHtml ? e($label) : $label;
        }
        // Fall back to controller formatting for money/dates/selects
        $text = function_exists('fe_format_field') ? fe_format_field($field, $value) : (string) $value;
        if (($field['type'] ?? '') === 'select' || str_contains((string) ($field['label'] ?? ''), 'Amount') || str_contains((string) ($field['label'] ?? ''), 'Cost')) {
            return $asHtml ? e($text) : $text;
        }
        return $asHtml ? e($text) : $text;
    }
}

if (!function_exists('fe_strip_geojson_notes')) {
    function fe_strip_geojson_notes(?string $notes): string
    {
        $notes = (string) $notes;
        $notes = preg_replace('/\n?\[\[GEOJSON\]\].*$/s', '', $notes) ?? $notes;
        return trim($notes);
    }
}

if (!function_exists('fe_extract_geojson')) {
    function fe_extract_geojson(?string $notes): ?array
    {
        if (!$notes || !preg_match('/\[\[GEOJSON\]\](\{.*\})\s*$/s', $notes, $m)) {
            return null;
        }
        $decoded = json_decode($m[1], true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('fe_pager_pages')) {
    /** @return list<int|string> */
    function fe_pager_pages(int $page, int $pages): array
    {
        if ($pages <= 1) {
            return [];
        }
        $set = [1, $pages];
        for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
            $set[] = $i;
        }
        $set = array_values(array_unique($set));
        sort($set);
        $out = [];
        $prev = 0;
        foreach ($set as $n) {
            if ($prev && $n > $prev + 1) {
                $out[] = '…';
            }
            $out[] = $n;
            $prev = $n;
        }
        return $out;
    }
}
