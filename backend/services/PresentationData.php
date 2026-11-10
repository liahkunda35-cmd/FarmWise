<?php

/**
 * Data used by pages. SQL stays here so frontend templates do not query the database.
 */
function farmwise_bind_frontend(): void
{
    FrontendBridge::bind('can', static fn(string $module, string $action = 'view'): bool => Auth::can($module, $action));
    FrontendBridge::bind('user', static function (): ?array {
        $user = Auth::user();
        if (!$user) {
            return null;
        }
        unset($user['password']);
        return $user;
    });
    FrontendBridge::bind('check', static fn(): bool => Auth::check());
    FrontendBridge::bind('id', static fn(): ?int => Auth::id());
    FrontendBridge::bind('role', static fn(): string => (string) Auth::role());
    FrontendBridge::bind('roles', static fn(): array => Auth::roles());
    FrontendBridge::bind('is_admin', static fn(): bool => Auth::isAdmin());
    FrontendBridge::bind('farm_id', static fn(): ?int => Auth::farmId());
    FrontendBridge::bind('accessible_farms', static fn(): array => Auth::accessibleFarms());
    FrontendBridge::bind('can_manage_user', static fn(array $user): bool => Auth::canManageUser($user));
    FrontendBridge::bind('csrf_field', static fn(): string => Csrf::field());
    FrontendBridge::bind('csrf_token', static fn(): string => Csrf::token());
    FrontendBridge::bind('unread', static fn($userId = null, $farmId = null): int => NotificationService::unreadCount($userId, $farmId));
    FrontendBridge::bind('public_config', static function (): array {
        $keys = ['name', 'full_name', 'tagline', 'hero_images', 'hero_image', 'farm_photo', 'promo_photo'];
        $public = [];
        foreach ($keys as $key) {
            $public[$key] = config($key);
        }
        return $public;
    });
    FrontendBridge::bind('active_farm', static function (): ?array {
        $id = Auth::farmId();
        if (!$id) {
            return null;
        }
        $cacheKey = 'active-farm:' . $id;
        $cached = session_memo_get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $farm = db()->find('farms', $id);
        if ($farm) {
            session_memo_set($cacheKey, $farm, 25);
        }
        return $farm ?: null;
    });
}

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

function &fe_rel_cache(): array
{
    static $cache = [];
    return $cache;
}

function fe_rel_preload(string $table, array $ids): void
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) {
        return;
    }
    $cache = &fe_rel_cache();
    $missing = [];
    foreach ($ids as $id) {
        if (!isset($cache[$table . ':' . $id])) {
            $missing[] = $id;
        }
    }
    if ($missing === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($missing), '?'));
    try {
        $rows = db()->select('SELECT * FROM ' . $table . ' WHERE id IN (' . $placeholders . ')', $missing);
    } catch (Throwable $e) {
        return;
    }
    foreach ($rows as $row) {
        $cache[$table . ':' . (int) $row['id']] = $row;
    }
}

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
    $cache = &fe_rel_cache();
    $rel = $cache[$table . ':' . $id] ?? null;
    if (!$rel) {
        $rel = db()->find($table, $id);
        if ($rel) {
            $cache[$table . ':' . $id] = $rel;
        }
    }
    if (!$rel) {
        return '#' . $id;
    }
    $label = option_label($rel);
    return $label !== '' ? $label : ('#' . $id);
}

function fe_rel_href(string $table, $id): ?string
{
    $id = (int) $id;
    if ($id <= 0 || !module_def($table) || !Auth::can($table, 'view')) {
        return null;
    }
    return url('module/' . $table . '/view/' . $id);
}

function fe_format_field(array $field, $value): string
{
    return ResourceController::displayValue($field, $value);
}

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
    if ($module === 'land_use_plans') {
        $fieldId = (int) ($row['field_id'] ?? 0);
        $farmId = (int) ($row['farm_id'] ?? 0);
        if ($fieldId > 0 && module_def('plantings') && Auth::can('plantings', 'view') && table_exists('plantings')) {
            try {
                $plantRows = db()->select(
                    'SELECT p.*, c.name AS crop_name FROM plantings p
                     LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
                     WHERE p.field_id = ? ORDER BY p.planting_date DESC, p.id DESC LIMIT 20',
                    [$fieldId]
                );
            } catch (Throwable $e) {
                $plantRows = [];
            }
            $out[] = [
                'title' => 'Crops on this field',
                'module' => 'plantings',
                'label' => 'crop_name',
                'rows' => $plantRows,
                'filter_url' => url('module/plantings?field_id=' . $fieldId),
                'create_url' => Auth::can('plantings', 'create')
                    ? url('module/plantings/create?field_id=' . $fieldId . ($farmId ? '&farm_id=' . $farmId : ''))
                    : null,
            ];
        }
        if ($farmId > 0 && module_def('plantings') && Auth::can('plantings', 'view') && table_exists('plantings')) {
            try {
                $allPlantRows = db()->select(
                    'SELECT p.*, c.name AS crop_name FROM plantings p
                     LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
                     WHERE p.farm_id = ? ORDER BY p.planting_date DESC, p.id DESC LIMIT 20',
                    [$farmId]
                );
            } catch (Throwable $e) {
                $allPlantRows = [];
            }
            $out[] = [
                'title' => 'All farm plantings',
                'module' => 'plantings',
                'label' => 'crop_name',
                'rows' => $allPlantRows,
                'filter_url' => url('module/plantings?farm_id=' . $farmId),
                'create_url' => Auth::can('plantings', 'create')
                    ? url('module/plantings/create?farm_id=' . $farmId)
                    : null,
            ];
        }
        if ($farmId > 0 && module_def('crop_varieties') && Auth::can('crop_varieties', 'view') && table_exists('crop_varieties')) {
            try {
                $cropRows = db()->select(
                    'SELECT * FROM crop_varieties WHERE farm_id = ? OR farm_id IS NULL ORDER BY name ASC LIMIT 30',
                    [$farmId]
                );
            } catch (Throwable $e) {
                $cropRows = [];
            }
            $out[] = [
                'title' => 'All crops & varieties',
                'module' => 'crop_varieties',
                'label' => 'name',
                'rows' => $cropRows,
                'filter_url' => url('module/crop_varieties'),
                'create_url' => Auth::can('crop_varieties', 'create')
                    ? url('module/crop_varieties/create?farm_id=' . $farmId)
                    : null,
            ];
        }
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

/** @return array{options:array<int|string,string>,meta:array<int|string,array<string,string>>} */
function fe_relation_choices(array $field, array $def): array
{
    $opts = [];
    $optMeta = [];
    if (($field['table'] ?? '') === 'farms') {
        return ['options' => farm_options(), 'meta' => []];
    }
    $where = null;
    $params = [];
    $relDef = module_def($field['table'] ?? '');
    $relHasFarm = $relDef && isset($relDef['fields']['farm_id']);
    $relTable = (string) ($field['table'] ?? '');
    if (!empty($def['farm_scoped']) && Auth::farmId() && $relHasFarm) {
        // Shared catalogues (farm_id NULL) stay available alongside this farm's rows.
        if ($relTable === 'crop_varieties') {
            $where = '(farm_id = ? OR farm_id IS NULL)';
            $params = [Auth::farmId()];
        } else {
            $where = 'farm_id = ?';
            $params = [Auth::farmId()];
        }
    }
    $labelCol = $field['label_col'] ?? 'name';
    if (($field['table'] ?? '') === 'plantings') {
        $sql = 'SELECT p.id, p.field_id, p.season, c.name AS crop_name, c.variety, f.name AS field_name FROM plantings p LEFT JOIN crop_varieties c ON c.id=p.crop_variety_id LEFT JOIN fields f ON f.id=p.field_id';
        if ($where) {
            $sql .= ' WHERE p.' . $where;
        }
        $sql .= ' ORDER BY p.id DESC';
        foreach (db()->select($sql, $params) as $r) {
            $opts[$r['id']] = fe_planting_label($r);
            $optMeta[$r['id']] = ['parent' => (string) ($r['field_id'] ?? '')];
        }
    } elseif (($field['table'] ?? '') === 'harvests') {
        $hrows = db()->select('SELECT id, planting_id, quantity, unit, harvest_date FROM harvests' . ($where ? ' WHERE ' . $where : '') . ' ORDER BY id DESC LIMIT 200', $params);
        foreach ($hrows as $r) {
            $opts[$r['id']] = fe_rel_label('harvests', $r['id']);
            $optMeta[$r['id']] = ['parent' => (string) ($r['planting_id'] ?? '')];
        }
    } elseif ($labelCol === 'id') {
        $rows = db()->select('SELECT id FROM ' . $field['table'] . ($where ? ' WHERE ' . $where : ''), $params);
        foreach ($rows as $r) {
            $opts[$r['id']] = fe_rel_label($field['table'], $r['id']);
        }
    } elseif (($field['table'] ?? '') === 'fields') {
        $fieldWhere = $where;
        $fieldParams = $params;
        // If no active farm filter yet, but exactly one accessible farm exists, scope to it.
        if (!$fieldWhere) {
            $accessible = Auth::check() ? Auth::accessibleFarms() : [];
            if (count($accessible) === 1) {
                $fieldWhere = 'farm_id = ?';
                $fieldParams = [(int) $accessible[0]['id']];
            }
        }
        // New activity dropdowns: show usable fields only (keep selected historical id via form.php)
        if ($fieldWhere) {
            $fieldWhere .= " AND COALESCE(status, 'active') IN ('active','fallow','reserved')";
        } else {
            $fieldWhere = "COALESCE(status, 'active') IN ('active','fallow','reserved')";
        }
        $opts = rel_options('fields', $labelCol, $fieldWhere, $fieldParams);
        $frows = db()->select(
            'SELECT id, farm_id, status FROM fields' . ($fieldWhere ? ' WHERE ' . $fieldWhere : '') . ' ORDER BY name',
            $fieldParams
        );
        foreach ($frows as $r) {
            $optMeta[$r['id']] = [
                'parent' => (string) ($r['farm_id'] ?? ''),
                'farm' => (string) ($r['farm_id'] ?? ''),
                'status' => (string) ($r['status'] ?? 'active'),
            ];
        }
    } elseif (($field['table'] ?? '') === 'crop_varieties') {
        $rows = db()->select(
            'SELECT id, name, variety, farm_id FROM crop_varieties' . ($where ? ' WHERE ' . $where : '') . ' ORDER BY name, variety',
            $params
        );
        foreach ($rows as $r) {
            $label = trim((string) ($r['name'] ?? 'Crop'));
            $variety = trim((string) ($r['variety'] ?? ''));
            if ($variety !== '') {
                $label .= ' — ' . $variety;
            }
            $opts[(int) $r['id']] = $label;
        }
    } elseif (($field['table'] ?? '') === 'breeds') {
        $rows = db()->select(
            'SELECT id, name, species, purpose, farm_id FROM breeds'
            . ($where ? ' WHERE ' . $where : '')
            . ' ORDER BY species, name LIMIT 300',
            $params
        );
        foreach ($rows as $r) {
            $label = trim((string) ($r['name'] ?? 'Breed'));
            $species = trim((string) ($r['species'] ?? ''));
            $purpose = trim((string) ($r['purpose'] ?? ''));
            if ($species !== '') {
                $label .= ' · ' . $species;
            }
            if ($purpose !== '') {
                $label .= ' · ' . $purpose;
            }
            $opts[(int) $r['id']] = $label;
            $optMeta[(int) $r['id']] = [
                'species' => $species,
                'purpose' => $purpose,
            ];
        }
    } elseif (($field['table'] ?? '') === 'inventory_items') {
        $invWhere = $where;
        $invParams = $params;
        if (!$invWhere) {
            $accessible = Auth::check() ? Auth::accessibleFarms() : [];
            if (count($accessible) === 1) {
                $invWhere = 'farm_id = ?';
                $invParams = [(int) $accessible[0]['id']];
            }
        }
        $rows = db()->select(
            'SELECT id, name, category, unit, quantity, status FROM inventory_items'
            . ($invWhere ? ' WHERE ' . $invWhere : '')
            . ' ORDER BY category, name LIMIT 300',
            $invParams
        );
        foreach ($rows as $r) {
            $qty = trim((string) ($r['quantity'] ?? '0') . ' ' . (string) ($r['unit'] ?? ''));
            $cat = trim((string) ($r['category'] ?? ''));
            $label = trim((string) ($r['name'] ?? 'Item'));
            if ($cat !== '') {
                $label .= ' · ' . $cat;
            }
            if ($qty !== '') {
                $label .= ' · stock ' . $qty;
            }
            $opts[(int) $r['id']] = $label;
        }
    } else {
        $opts = rel_options($field['table'], $labelCol, $where, $params);
    }
    if (($field['table'] ?? '') === 'irrigation_systems') {
        $irows = db()->select('SELECT id, field_id FROM irrigation_systems' . ($where ? ' WHERE ' . $where : ''), $params);
        foreach ($irows as $r) {
            $optMeta[$r['id']] = ['parent' => (string) ($r['field_id'] ?? ''), 'field' => (string) ($r['field_id'] ?? '')];
        }
    }
    if (($field['table'] ?? '') === 'animals') {
        $arows = db()->select('SELECT id, breed_id FROM animals' . ($where ? ' WHERE ' . $where : ''), $params);
        foreach ($arows as $r) {
            $optMeta[$r['id']] = ['breed' => (string) ($r['breed_id'] ?? '')];
        }
    }
    if (($field['table'] ?? '') === 'employees') {
        $erows = db()->select(
            'SELECT id, daily_rate, role_title FROM employees' . ($where ? ' WHERE ' . $where : ''),
            $params
        );
        foreach ($erows as $r) {
            $optMeta[$r['id']] = [
                'rate' => (string) ($r['daily_rate'] ?? ''),
                'role' => (string) ($r['role_title'] ?? ''),
            ];
        }
    }
    return ['options' => $opts, 'meta' => $optMeta];
}

function fe_inventory_choices(): ?array
{
    if (!Auth::can('inventory_items', 'view') || !table_exists('inventory_items')) {
        return null;
    }
    $where = Auth::farmId() ? 'WHERE farm_id = ?' : '';
    $params = Auth::farmId() ? [Auth::farmId()] : [];
    return db()->select('SELECT id, name, unit, quantity, reorder_level, category FROM inventory_items ' . $where . ' ORDER BY name LIMIT 300', $params);
}

/** @return array{cost:?float,per_ha:?float} */
function fe_planting_cost(array $row): array
{
    if (!table_exists('transactions')) {
        return ['cost' => null, 'per_ha' => null];
    }
    try {
        $plantingCost = (float) db()->value('SELECT COALESCE(SUM(cost),0) FROM fertilizer_applications WHERE planting_id = ?', [$row['id']]);
        $perHa = null;
        if (!empty($row['field_id'])) {
            $fieldArea = db()->value('SELECT size_hectares FROM fields WHERE id = ?', [$row['field_id']]);
            if ($fieldArea && (float) $fieldArea > 0) {
                $perHa = $plantingCost / (float) $fieldArea;
            }
        }
        return ['cost' => $plantingCost, 'per_ha' => $perHa];
    } catch (Throwable $e) {
        return ['cost' => null, 'per_ha' => null];
    }
}

function fe_animal_weights(int $animalId): array
{
    if ($animalId <= 0 || !table_exists('livestock_weights')) {
        return [];
    }
    return db()->select('SELECT weigh_date, weight_kg FROM livestock_weights WHERE animal_id = ? ORDER BY weigh_date ASC LIMIT 30', [$animalId]);
}

function fe_record_activity(string $module, int $id): array
{
    if ($id <= 0 || !table_exists('audit_logs') || !Auth::can('audit_logs', 'view')) {
        return [];
    }
    try {
        return db()->select(
            'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.module = ? AND a.record_id = ? ORDER BY a.id DESC LIMIT 20',
            [$module, $id]
        );
    } catch (Throwable $e) {
        return [];
    }
}

function fe_user_directory_summary(): array
{
    $stats = ['users' => 0, 'active' => 0, 'inactive' => 0, 'farms' => 0];
    if (table_exists('users')) {
        if (db()->driver() === 'pgsql') {
            $counts = db()->first("SELECT COUNT(*) AS users, COUNT(*) FILTER (WHERE status = 'active') AS active, COUNT(*) FILTER (WHERE status <> 'active') AS inactive FROM users");
            if ($counts) {
                $stats['users'] = (int) $counts['users'];
                $stats['active'] = (int) $counts['active'];
                $stats['inactive'] = (int) $counts['inactive'];
            }
        } else {
            $stats['users'] = (int) db()->value('SELECT COUNT(*) FROM users');
            $stats['active'] = (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'active'");
            $stats['inactive'] = (int) db()->value("SELECT COUNT(*) FROM users WHERE status != 'active'");
        }
    }
    if (table_exists('farms')) {
        $stats['farms'] = (int) db()->value('SELECT COUNT(*) FROM farms');
    }
    $byRole = [];
    if (table_exists('users')) {
        try {
            foreach (db()->select('SELECT role, COUNT(*) AS total FROM users GROUP BY role') as $r) {
                $byRole[$r['role']] = (int) $r['total'];
            }
        } catch (Throwable $e) {
            $byRole = [];
        }
    }
    $lastBackup = null;
    $backupDir = dirname(__DIR__, 2) . '/storage/backups';
    if (is_dir($backupDir)) {
        $files = glob($backupDir . '/*.sqlite') ?: [];
        rsort($files);
        if ($files) {
            $lastBackup = ['file' => basename($files[0]), 'mtime' => filemtime($files[0]), 'size' => filesize($files[0])];
        }
    }
    $latestAudit = [];
    if (table_exists('audit_logs') && Auth::can('audit_logs', 'view')) {
        try {
            $latestAudit = db()->select('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 5');
        } catch (Throwable $e) {
            $latestAudit = [];
        }
    }
    return [
        'stats' => $stats,
        'by_role' => $byRole,
        'last_backup' => $lastBackup,
        'latest_audit' => $latestAudit,
    ];
}

/** @return array{fields:array,has_coords:bool,active_farm:?array,edit_url:?string} */
function fe_map_layers(array $fields, array $farms): array
{
    $fieldIds = array_map(static fn($f) => (int) $f['id'], $fields);
    $irrigated = [];
    $scouted = [];
    if ($fieldIds && table_exists('irrigation_systems')) {
        try {
            $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
            foreach (db()->select("SELECT DISTINCT field_id FROM irrigation_systems WHERE field_id IN ($placeholders)", $fieldIds) as $r) {
                $irrigated[(int) $r['field_id']] = true;
            }
        } catch (Throwable $e) {
            $irrigated = [];
        }
    }
    if ($fieldIds && table_exists('pest_scouting')) {
        try {
            $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
            foreach (db()->select("SELECT DISTINCT field_id FROM pest_scouting WHERE field_id IN ($placeholders)", $fieldIds) as $r) {
                $scouted[(int) $r['field_id']] = true;
            }
        } catch (Throwable $e) {
            $scouted = [];
        }
    }
    $mapFields = [];
    $hasCoords = false;
    foreach ($fields as $f) {
        $f['_irrigation'] = !empty($irrigated[(int) $f['id']]);
        $f['_scouting'] = !empty($scouted[(int) $f['id']]);
        $mapFields[] = $f;
        if (!empty($f['latitude']) && !empty($f['longitude'])) {
            $hasCoords = true;
        }
    }
    foreach ($farms as $f) {
        if ($f && !empty($f['latitude']) && !empty($f['longitude'])) {
            $hasCoords = true;
        }
    }
    $activeFarm = fe_active_farm();
    $editUrl = ($activeFarm && Auth::can('farms', 'edit')) ? url('module/farms/edit/' . $activeFarm['id']) : null;
    return [
        'fields' => $mapFields,
        'has_coords' => $hasCoords,
        'active_farm' => $activeFarm,
        'edit_url' => $editUrl,
    ];
}
