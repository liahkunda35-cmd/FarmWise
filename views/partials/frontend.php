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

if (!function_exists('fe_load_planting')) {
    function fe_load_planting($id): ?array
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        static $cache = [];
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        $cache[$id] = db()->first(
            'SELECT p.*, c.name AS crop_name, c.variety, f.name AS field_name
             FROM plantings p
             LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
             LEFT JOIN fields f ON f.id = p.field_id
             WHERE p.id = ?',
            [$id]
        ) ?: null;
        return $cache[$id];
    }
}

if (!function_exists('fe_rel_label')) {
    function fe_rel_label(string $table, $id): string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return '—';
        }
        if ($table === 'plantings') {
            return fe_planting_label(fe_load_planting($id));
        }
        if ($table === 'harvests') {
            $h = db()->first(
                'SELECT h.id, h.quantity, h.unit, h.harvest_date, p.id AS planting_id
                 FROM harvests h LEFT JOIN plantings p ON p.id = h.planting_id WHERE h.id = ?',
                [$id]
            );
            if (!$h) {
                return '#' . $id;
            }
            $plant = !empty($h['planting_id']) ? fe_planting_label(fe_load_planting($h['planting_id'])) : 'Harvest';
            $qty = trim(($h['quantity'] ?? '') . ' ' . ($h['unit'] ?? ''));
            return trim($plant . ($qty !== '' ? ' · ' . $qty : '') . (!empty($h['harvest_date']) ? ' · ' . dater($h['harvest_date']) : ''));
        }
        if ($table === 'pest_scouting') {
            $s = db()->find('pest_scouting', $id);
            if (!$s) {
                return '#' . $id;
            }
            return trim(($s['severity'] ?? 'Scouting') . (!empty($s['scouting_date']) ? ' · ' . dater($s['scouting_date']) : '') . ' #' . $id);
        }
        $rel = db()->find($table, $id);
        if (!$rel) {
            return '#' . $id;
        }
        $label = option_label($rel);
        return $label !== '' ? $label : ('#' . $id);
    }
}

if (!function_exists('fe_rel_href')) {
    function fe_rel_href(string $table, $id): ?string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        // Map table name to module key (usually the same)
        $module = $table;
        if (!module_def($module)) {
            return null;
        }
        if (!Auth::can($module, 'view')) {
            return null;
        }
        return url('module/' . $module . '/view/' . $id);
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
        $text = ResourceController::displayValue($field, $value);
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

if (!function_exists('fe_extra_related')) {
    /**
     * Extra related groups not supplied by ResourceController::relatedGroups.
     * @return list<array{title:string,module:string,label:string,rows:array,filter_url?:string,create_url?:string}>
     */
    function fe_extra_related(string $module, array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return [];
        }
        $out = [];
        $add = function (string $title, string $mod, string $label, string $sql, array $params, ?string $fk = null) use (&$out, $id) {
            if (!module_def($mod) || !Auth::can($mod, 'view')) {
                return;
            }
            $table = module_def($mod)['table'] ?? $mod;
            try {
                $rows = table_exists($table) ? db()->select($sql, $params) : [];
            } catch (Throwable $e) {
                $rows = [];
            }
            $create = null;
            if (Auth::can($mod, 'create') && $fk) {
                $create = url('module/' . $mod . '/create?' . $fk . '=' . $id);
            }
            $out[] = [
                'title' => $title,
                'module' => $mod,
                'label' => $label,
                'rows' => $rows,
                'filter_url' => url('module/' . $mod),
                'create_url' => $create,
            ];
        };

        if ($module === 'fields') {
            $add('Soil records', 'soil_records', 'soil_type', 'SELECT * FROM soil_records WHERE field_id = ? ORDER BY id DESC LIMIT 10', [$id], 'field_id');
            $add('Crop rotations', 'crop_rotations', 'season', 'SELECT * FROM crop_rotations WHERE field_id = ? ORDER BY id DESC LIMIT 10', [$id], 'field_id');
            $add('Land-use plans', 'land_use_plans', 'plan_name', 'SELECT * FROM land_use_plans WHERE field_id = ? ORDER BY id DESC LIMIT 10', [$id], 'field_id');
            $add('Plantings', 'plantings', 'season', 'SELECT * FROM plantings WHERE field_id = ? ORDER BY planting_date DESC LIMIT 12', [$id], 'field_id');
            $add('Irrigation systems', 'irrigation_systems', 'name', 'SELECT * FROM irrigation_systems WHERE field_id = ? ORDER BY id DESC LIMIT 10', [$id], 'field_id');
            $add('Scouting', 'pest_scouting', 'severity', 'SELECT * FROM pest_scouting WHERE field_id = ? ORDER BY id DESC LIMIT 10', [$id], 'field_id');
            $add('Tasks', 'tasks', 'title', 'SELECT * FROM tasks WHERE field_id = ? ORDER BY id DESC LIMIT 10', [$id], 'field_id');
            $add('Harvests', 'harvests', 'quantity', 'SELECT h.* FROM harvests h JOIN plantings p ON p.id = h.planting_id WHERE p.field_id = ? ORDER BY h.id DESC LIMIT 10', [$id]);
        }
        if ($module === 'inventory_items') {
            $add('Stock movements', 'stock_movements', 'movement_type', 'SELECT * FROM stock_movements WHERE inventory_item_id = ? ORDER BY id DESC LIMIT 12', [$id], 'inventory_item_id');
        }
        if ($module === 'equipment') {
            $add('Equipment logs', 'equipment_logs', 'log_type', 'SELECT * FROM equipment_logs WHERE equipment_id = ? ORDER BY id DESC LIMIT 12', [$id], 'equipment_id');
        }
        if ($module === 'employees') {
            $add('Attendance', 'attendance', 'status', 'SELECT * FROM attendance WHERE employee_id = ? ORDER BY id DESC LIMIT 12', [$id], 'employee_id');
            $add('Tasks', 'tasks', 'title', 'SELECT * FROM tasks WHERE assigned_to = ? ORDER BY id DESC LIMIT 12', [$id], 'assigned_to');
            $add('Payroll', 'payroll', 'status', 'SELECT * FROM payroll WHERE employee_id = ? ORDER BY id DESC LIMIT 12', [$id], 'employee_id');
        }
        if ($module === 'suppliers') {
            $add('Quotations', 'quotations', 'quote_number', 'SELECT * FROM quotations WHERE supplier_id = ? ORDER BY id DESC LIMIT 12', [$id], 'supplier_id');
            $add('Purchase orders', 'purchase_orders', 'po_number', 'SELECT * FROM purchase_orders WHERE supplier_id = ? ORDER BY id DESC LIMIT 12', [$id], 'supplier_id');
        }
        if ($module === 'warehouses') {
            $add('Storage lots', 'storage_lots', 'produce_name', 'SELECT * FROM storage_lots WHERE warehouse_id = ? ORDER BY id DESC LIMIT 12', [$id], 'warehouse_id');
        }
        if ($module === 'harvests') {
            $add('Storage lots', 'storage_lots', 'produce_name', 'SELECT * FROM storage_lots WHERE harvest_id = ? ORDER BY id DESC LIMIT 12', [$id], 'harvest_id');
        }
        if ($module === 'water_sources') {
            $add('Irrigation systems', 'irrigation_systems', 'name', 'SELECT * FROM irrigation_systems WHERE water_source_id = ? ORDER BY id DESC LIMIT 12', [$id], 'water_source_id');
        }
        if ($module === 'customers') {
            $add('Sales orders', 'sales_orders', 'order_number', 'SELECT * FROM sales_orders WHERE customer_id = ? ORDER BY id DESC LIMIT 12', [$id], 'customer_id');
            $add('Invoices', 'invoices', 'invoice_number', 'SELECT * FROM invoices WHERE customer_id = ? ORDER BY id DESC LIMIT 12', [$id], 'customer_id');
            $add('Payments', 'payments', 'amount', 'SELECT p.* FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.customer_id = ? ORDER BY p.id DESC LIMIT 12', [$id]);
            $add('Contracts', 'contracts', 'title', 'SELECT * FROM contracts WHERE customer_id = ? ORDER BY id DESC LIMIT 12', [$id], 'customer_id');
            $add('Dispatches', 'dispatches', 'destination', 'SELECT * FROM dispatches WHERE customer_id = ? ORDER BY id DESC LIMIT 12', [$id], 'customer_id');
        }
        return $out;
    }
}

if (!function_exists('fe_money_rows')) {
    function fe_money_rows(string $module, array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || !table_exists('transactions')) {
            return [];
        }
        try {
            return db()->select(
                'SELECT * FROM transactions WHERE related_module = ? AND related_id = ? ORDER BY txn_date DESC, id DESC LIMIT 20',
                [$module, $id]
            );
        } catch (Throwable $e) {
            // Some schemas use source_module / reference fields
            try {
                return db()->select(
                    'SELECT * FROM transactions WHERE description LIKE ? ORDER BY id DESC LIMIT 20',
                    ['%#' . $id . '%']
                );
            } catch (Throwable $e2) {
                return [];
            }
        }
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
