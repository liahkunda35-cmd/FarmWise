<?php

class ResourceController
{
    public static function handle(string $module, ?string $action, ?string $id): void
    {
        $def = module_def($module);
        if (!$def) {
            abort(404, 'Module not found.');
        }
        $action = $action ?: 'index';
        $map = [
            'index' => 'view',
            'view' => 'view',
            'create' => 'create',
            'store' => 'create',
            'edit' => 'edit',
            'update' => 'edit',
            'delete' => 'delete',
            'print' => 'view',
            'pdf' => 'view',
            'email' => 'view',
            'attach' => 'edit',
            'file' => 'view',
            'unattach' => 'edit',
        ];
        Auth::authorize($module, $map[$action] ?? 'view');

        match ($action) {
            'index' => self::index($module, $def),
            'create' => self::form($module, $def, null),
            'store' => self::save($module, $def, null),
            'view' => self::show($module, $def, $id),
            'edit' => self::form($module, $def, $id),
            'update' => self::save($module, $def, $id),
            'delete' => self::destroy($module, $def, $id),
            'print' => self::printDoc($module, $def, $id),
            'pdf' => self::pdfDoc($module, $def, $id),
            'email' => self::emailDoc($module, $def, $id),
            'attach' => self::attach($module, $def, $id),
            'file' => self::file($module, $id),
            'unattach' => self::unattach($module, $def, $id),
            default => abort(404, 'Unknown action.'),
        };
    }

    private static function scopedWhere(array $def): array
    {
        $where = '1=1';
        $params = [];
        if (!empty($def['farm_scoped']) && Auth::farmId()) {
            $where .= ' AND farm_id = ?';
            $params[] = Auth::farmId();
        }
        $q = input('q');
        if ($q && !empty($def['search'])) {
            $likes = [];
            foreach ($def['search'] as $col) {
                $likes[] = $col . ' LIKE ?';
                $params[] = '%' . $q . '%';
            }
            $where .= ' AND (' . implode(' OR ', $likes) . ')';
        }
        $status = input('status');
        if ($status && isset($def['fields']['status'])) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $dateFrom = input('date_from');
        $dateTo = input('date_to');
        $dateCol = self::dateColumn($def);
        if ($dateCol && $dateFrom && is_valid_date($dateFrom)) {
            $where .= ' AND ' . $dateCol . ' >= ?';
            $params[] = $dateFrom;
        }
        if ($dateCol && $dateTo && is_valid_date($dateTo)) {
            $where .= ' AND ' . $dateCol . ' <= ?';
            $params[] = $dateTo;
        }
        return [$where, $params];
    }

    private static function dateColumn(array $def): ?string
    {
        foreach ($def['fields'] as $name => $field) {
            if (($field['type'] ?? '') === 'date') {
                return $name;
            }
        }
        return null;
    }

    private static function index(string $module, array $def): void
    {
        [$where, $params] = self::scopedWhere($def);
        $columns = [];
        foreach ($def['fields'] as $name => $field) {
            if (!empty($field['list'])) {
                $columns[$name] = $field;
            }
        }
        $sort = (string) input('sort', 'id');
        $dir = strtolower((string) input('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $allowedSort = array_merge(['id'], array_keys($columns));
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }
        if (input('export') === 'csv') {
            $all = db()->select('SELECT * FROM ' . $def['table'] . ' WHERE ' . $where . ' ORDER BY ' . $sort . ' ' . $dir, $params);
            $headers = array_map(fn($f) => $f['label'], $columns);
            $csvRows = [];
            foreach ($all as $row) {
                $line = [];
                foreach ($columns as $name => $field) {
                    $line[] = html_entity_decode(strip_tags(ResourceController::displayValue($field, $row[$name] ?? null)), ENT_QUOTES, 'UTF-8');
                }
                $csvRows[] = $line;
            }
            csv_download($module . '.csv', array_values($headers), $csvRows);
        }
        $page = max(1, (int) input('page', 1));
        $per = 15;
        $total = db()->count($def['table'], $where, $params);
        $pages = max(1, (int) ceil($total / $per));
        $offset = ($page - 1) * $per;
        $rows = db()->select(
            'SELECT * FROM ' . $def['table'] . ' WHERE ' . $where . ' ORDER BY ' . $sort . ' ' . $dir . ' LIMIT ' . $per . ' OFFSET ' . $offset,
            $params
        );
        $dateCol = self::dateColumn($def);
        view('layouts/app', [
            'title' => $def['title'],
            'content' => render('resource/list', compact('module', 'def', 'rows', 'columns', 'total', 'page', 'pages', 'sort', 'dir', 'dateCol')),
        ]);
    }

    private static function show(string $module, array $def, $id): void
    {
        $row = self::load($def, $id);
        $related = self::relatedGroups($module, $row);
        $attachments = AttachmentService::allowed($module) ? AttachmentService::for($module, (int) $row['id']) : [];
        $document = DocumentService::isDocument($module) ? DocumentService::payload($module, $row) : null;
        view('layouts/app', [
            'title' => $def['singular'],
            'content' => render('resource/view', compact('module', 'def', 'row', 'related', 'attachments', 'document')),
        ]);
    }

    private static function form(string $module, array $def, $id): void
    {
        $row = $id ? self::load($def, $id) : [];
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        view('layouts/app', [
            'title' => ($id ? 'Edit ' : 'New ') . $def['singular'],
            'content' => render('resource/form', compact('module', 'def', 'row', 'errors', 'id')),
        ]);
    }

    private static function save(string $module, array $def, $id): void
    {
        Csrf::verify();
        $payload = [];
        foreach ($def['fields'] as $name => $field) {
            $payload[$name] = input($name, '');
            if ($payload[$name] === '') {
                $payload[$name] = null;
            }
        }
        if (!empty($def['compute'])) {
            foreach ($def['compute'] as $target => $expr) {
                [$a, $op, $b] = $expr;
                if ($op === '*') {
                    $payload[$target] = round(((float) ($payload[$a] ?? 0)) * ((float) ($payload[$b] ?? 0)), 2);
                }
            }
        }
        foreach ($def['fields'] as $name => $field) {
            if (!empty($field['auto_farm']) && !$payload[$name] && Auth::farmId()) {
                $payload[$name] = Auth::farmId();
            }
        }
        $errors = Validator::validate($payload, $def['fields']);
        if (!empty($def['unique'])) {
            $sql = 'SELECT id FROM ' . $def['table'] . ' WHERE ' . $def['unique'][0] . ' = ? AND ' . $def['unique'][1] . ' = ?';
            $params = [$payload[$def['unique'][0]] ?? '', $payload[$def['unique'][1]] ?? ''];
            $found = db()->first($sql, $params);
            if ($found && (int) $found['id'] !== (int) $id) {
                $errors[$def['unique'][0]] = 'This record already exists.';
            }
        }
        if ($errors) {
            $_SESSION['_errors'] = $errors;
            remember_old($payload);
            flash('error', 'Please correct the highlighted fields.');
            redirect($id ? 'module/' . $module . '/edit/' . $id : 'module/' . $module . '/create');
        }
        clear_old();
        if ($id) {
            $row = self::load($def, $id);
            db()->update($def['table'], $payload, $id);
            Audit::log('update', $module, $id, $def['singular'] . ' updated');
            self::afterSave($def, $payload, (int) $id, false);
            flash('success', $def['singular'] . ' updated.');
        } else {
            $newId = db()->insert($def['table'], $payload);
            Audit::log('create', $module, $newId, $def['singular'] . ' created');
            self::afterSave($def, $payload, $newId, true);
            flash('success', $def['singular'] . ' saved.');
        }
        redirect('module/' . $module);
    }

    private static function destroy(string $module, array $def, $id): void
    {
        Csrf::verify();
        $row = self::load($def, $id);
        db()->delete($def['table'], $id);
        Audit::log('delete', $module, $id, $def['singular'] . ' deleted');
        flash('success', $def['singular'] . ' deleted.');
        redirect('module/' . $module);
    }

    private static function load(array $def, $id): array
    {
        $row = db()->find($def['table'], $id);
        if (!$row) {
            abort(404, 'Record not found.');
        }
        if (!Auth::canAccessRecord($row)) {
            abort(403, 'You cannot access this record.');
        }
        return $row;
    }

    private static function afterSave(array $def, array $payload, int $id, bool $created): void
    {
        $hooks = $def['hooks'] ?? [];
        if (!empty($hooks['stock_adjust']) && $created) {
            $item = db()->find('inventory_items', $payload['inventory_item_id']);
            if ($item) {
                $qty = (float) $payload['quantity'];
                $newQty = (float) $item['quantity'];
                if ($payload['movement_type'] === 'in') {
                    $newQty += $qty;
                } elseif ($payload['movement_type'] === 'out') {
                    $newQty -= $qty;
                } else {
                    $newQty = $qty;
                }
                $status = $newQty <= 0 ? 'out' : ($newQty <= (float) $item['reorder_level'] ? 'low' : 'in_stock');
                db()->update('inventory_items', ['quantity' => $newQty, 'status' => $status], $item['id']);
            }
        }
        if (!empty($hooks['finance_expense']) && $created) {
            $h = $hooks['finance_expense'];
            $amount = (float) ($payload[$h['amount']] ?? 0);
            $date = $payload[$h['date']] ?? date('Y-m-d');
            if ($amount > 0 && $date) {
                db()->insert('transactions', [
                    'farm_id' => $payload['farm_id'] ?? Auth::farmId(),
                    'txn_date' => $date,
                    'type' => 'expense',
                    'category' => $h['category'],
                    'description' => is_string($payload[$h['description']] ?? null) ? $payload[$h['description']] : ($def['singular'] ?? 'Expense'),
                    'amount' => $amount,
                    'reference' => $def['table'] . '#' . $id,
                    'related_module' => $def['table'],
                ]);
            }
        }
        if (!empty($hooks['finance_income']) && $created) {
            $h = $hooks['finance_income'];
            $amount = (float) ($payload[$h['amount']] ?? 0);
            if ($amount > 0) {
                db()->insert('transactions', [
                    'farm_id' => $payload['farm_id'] ?? Auth::farmId(),
                    'txn_date' => $payload[$h['date']] ?? date('Y-m-d'),
                    'type' => 'income',
                    'category' => $h['category'],
                    'description' => $payload[$h['description']] ?? 'Income',
                    'amount' => $amount,
                    'reference' => $def['table'] . '#' . $id,
                    'related_module' => $def['table'],
                ]);
            }
        }
        if (!empty($hooks['auto_invoice']) && $created) {
            db()->insert('invoices', [
                'farm_id' => $payload['farm_id'],
                'sales_order_id' => $id,
                'customer_id' => $payload['customer_id'],
                'invoice_number' => generate_code('INV'),
                'invoice_date' => $payload['order_date'] ?? date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+14 days')),
                'amount' => $payload['total_amount'] ?? 0,
                'status' => 'unpaid',
                'notes' => 'Generated from sales order ' . ($payload['order_number'] ?? $id),
            ]);
        }
        if (!empty($hooks['mark_invoice']) && !empty($payload['invoice_id'])) {
            $inv = db()->find('invoices', $payload['invoice_id']);
            if ($inv) {
                $paid = (float) db()->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$inv['id']]);
                $status = $paid >= (float) $inv['amount'] ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                db()->update('invoices', ['status' => $status], $inv['id']);
            }
        }
        if ($def['table'] === 'livestock_weights' && !empty($payload['animal_id'])) {
            db()->update('animals', ['current_weight' => $payload['weight_kg']], $payload['animal_id']);
        }
        if ($def['table'] === 'livestock_health' && ($payload['record_type'] ?? '') === 'death' && !empty($payload['animal_id'])) {
            db()->update('animals', ['status' => 'deceased'], $payload['animal_id']);
        }
        if ($def['table'] === 'harvests' && !empty($payload['planting_id'])) {
            db()->update('plantings', ['status' => 'harvested', 'growth_stage' => 'harvested'], $payload['planting_id']);
        }
        if ($def['table'] === 'crop_growth' && !empty($payload['planting_id']) && !empty($payload['growth_stage'])) {
            db()->update('plantings', ['growth_stage' => $payload['growth_stage'], 'status' => 'growing'], $payload['planting_id']);
        }
        if ($def['table'] === 'farms') {
            $farm = db()->find('farms', $id);
            if ($farm && empty($farm['join_code'])) {
                db()->update('farms', ['join_code' => generate_join_code()], $id);
            }
            if ($created && Auth::role() === 'owner') {
                db()->update('farms', ['owner_id' => Auth::id()], $id);
                $user = Auth::user();
                if ($user && empty($user['farm_id'])) {
                    db()->update('users', ['farm_id' => $id], $user['id']);
                    Auth::setActiveFarm($id);
                }
            }
        }
        if (in_array($def['table'], ['quotations', 'purchase_orders'], true)) {
            $qty = (float) ($payload['quantity'] ?? 0);
            $unit = (float) ($payload['unit_price'] ?? 0);
            db()->update($def['table'], ['total' => round($qty * $unit, 2)], $id);
        }
    }

    private static function printDoc(string $module, array $def, $id): void
    {
        if (!DocumentService::isDocument($module)) {
            abort(404, 'This record has no printable document.');
        }
        $row = self::load($def, $id);
        $doc = DocumentService::payload($module, $row);
        view('layouts/print', [
            'title' => $doc['title'] . ' ' . $doc['number'],
            'content' => render('document', compact('doc', 'module', 'row')),
        ]);
    }

    private static function pdfDoc(string $module, array $def, $id): void
    {
        if (!DocumentService::isDocument($module)) {
            abort(404, 'This record has no PDF document.');
        }
        $row = self::load($def, $id);
        $doc = DocumentService::payload($module, $row);
        $bytes = DocumentService::pdfBytes($doc);
        $name = DocumentService::filename($doc);
        Audit::log('export', $module, (int) $id, 'PDF downloaded');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    private static function emailDoc(string $module, array $def, $id): void
    {
        if (!DocumentService::isDocument($module) || !is_post()) {
            abort(404, 'This record cannot be emailed.');
        }
        Csrf::verify();
        $row = self::load($def, $id);
        $to = strtolower(trim((string) input('email')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid recipient email address.');
            redirect('module/' . $module . '/view/' . $id);
        }
        $doc = DocumentService::payload($module, $row);
        $result = DocumentService::email($doc, $to, (string) input('message'));
        Audit::log('email', $module, (int) $id, 'Document emailed to ' . $to);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Document emailed to ' . $to . '.' : ($result['error'] ?: 'Email could not be sent.'));
        redirect('module/' . $module . '/view/' . $id);
    }

    private static function attach(string $module, array $def, $id): void
    {
        Csrf::verify();
        $row = self::load($def, $id);
        $result = AttachmentService::store($module, $row, $_FILES['file'] ?? []);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'File uploaded.' : $result['error']);
        redirect('module/' . $module . '/view/' . $id);
    }

    private static function file(string $module, $id): void
    {
        $att = AttachmentService::load((int) $id);
        if (!$att || $att['module'] !== $module) {
            abort(404, 'File not found.');
        }
        $def = module_def($att['module']);
        if (!$def) {
            abort(404, 'File not found.');
        }
        $row = self::load($def, $att['record_id']);
        $path = AttachmentService::path($att);
        if (!is_file($path)) {
            abort(404, 'The stored file is missing.');
        }
        header('Content-Type: ' . ($att['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename($att['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    private static function unattach(string $module, array $def, $id): void
    {
        Csrf::verify();
        $att = AttachmentService::load((int) $id);
        if (!$att || $att['module'] !== $module) {
            abort(404, 'Attachment not found.');
        }
        self::load($def, $att['record_id']);
        $recordId = $att['record_id'];
        AttachmentService::destroy($att);
        flash('success', 'Attachment removed.');
        redirect('module/' . $module . '/view/' . $recordId);
    }

    private static function relatedGroups(string $module, array $row): array
    {
        $id = (int) $row['id'];
        $groups = [];
        $add = function (string $title, string $relModule, string $label, string $sql, array $params = []) use (&$groups) {
            $def = module_def($relModule);
            if (!$def || !table_exists($def['table']) || !Auth::can($relModule, 'view')) {
                return;
            }
            $rows = db()->select($sql, $params);
            $groups[] = ['title' => $title, 'module' => $relModule, 'label' => $label, 'rows' => $rows];
        };
        if ($module === 'plantings') {
            $add('Growth observations', 'crop_growth', 'growth_stage', 'SELECT * FROM crop_growth WHERE planting_id = ? ORDER BY record_date DESC LIMIT 8', [$id]);
            $add('Fertilizer / treatments', 'fertilizer_applications', 'product_name', 'SELECT * FROM fertilizer_applications WHERE planting_id = ? ORDER BY application_date DESC LIMIT 8', [$id]);
            $add('Irrigation schedules', 'irrigation_schedules', 'scheduled_date', 'SELECT * FROM irrigation_schedules WHERE field_id = ? ORDER BY scheduled_date DESC LIMIT 8', [$row['field_id'] ?? 0]);
            $add('Assigned tasks', 'tasks', 'title', 'SELECT * FROM tasks WHERE field_id = ? ORDER BY due_date DESC LIMIT 8', [$row['field_id'] ?? 0]);
            $add('Pest scouting', 'pest_scouting', 'severity', 'SELECT * FROM pest_scouting WHERE planting_id = ? OR field_id = ? ORDER BY scout_date DESC LIMIT 8', [$id, $row['field_id'] ?? 0]);
            $add('Harvests', 'harvests', 'quantity', 'SELECT * FROM harvests WHERE planting_id = ? ORDER BY harvest_date DESC LIMIT 8', [$id]);
            $add('Related expenses', 'transactions', 'description', "SELECT * FROM transactions WHERE related_module = 'fertilizer_applications' AND farm_id = ? ORDER BY txn_date DESC LIMIT 8", [$row['farm_id'] ?? 0]);
        }
        if ($module === 'animals') {
            $add('Health & vaccinations', 'livestock_health', 'title', 'SELECT * FROM livestock_health WHERE animal_id = ? ORDER BY record_date DESC LIMIT 8', [$id]);
            $add('Feeding', 'livestock_feed', 'feed_name', 'SELECT * FROM livestock_feed WHERE animal_id = ? ORDER BY feed_date DESC LIMIT 8', [$id]);
            $add('Weight history', 'livestock_weights', 'weight_kg', 'SELECT * FROM livestock_weights WHERE animal_id = ? ORDER BY weigh_date DESC LIMIT 8', [$id]);
            $add('Breeding', 'breeding_records', 'status', 'SELECT * FROM breeding_records WHERE female_id = ? OR male_id = ? ORDER BY breeding_date DESC LIMIT 8', [$id, $id]);
            $add('Production', 'livestock_production', 'product_type', 'SELECT * FROM livestock_production WHERE animal_id = ? ORDER BY production_date DESC LIMIT 8', [$id]);
            $add('Related expenses', 'transactions', 'description', "SELECT * FROM transactions WHERE farm_id = ? AND category IN ('feed','veterinary') ORDER BY txn_date DESC LIMIT 8", [$row['farm_id'] ?? 0]);
        }
        if ($module === 'sales_orders') {
            $add('Invoices', 'invoices', 'invoice_number', 'SELECT * FROM invoices WHERE sales_order_id = ? ORDER BY id DESC LIMIT 8', [$id]);
            $add('Customer orders', 'customers', 'name', 'SELECT * FROM customers WHERE id = ?', [$row['customer_id'] ?? 0]);
        }
        if ($module === 'invoices') {
            $add('Payments', 'payments', 'amount', 'SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC LIMIT 8', [$id]);
            $add('Sales order', 'sales_orders', 'order_number', 'SELECT * FROM sales_orders WHERE id = ?', [$row['sales_order_id'] ?? 0]);
            $add('Customer', 'customers', 'name', 'SELECT * FROM customers WHERE id = ?', [$row['customer_id'] ?? 0]);
        }
        if ($module === 'purchase_orders' || $module === 'quotations') {
            $add('Supplier', 'suppliers', 'name', 'SELECT * FROM suppliers WHERE id = ?', [$row['supplier_id'] ?? 0]);
        }
        if ($module === 'farms') {
            $add('Fields', 'fields', 'name', 'SELECT * FROM fields WHERE farm_id = ? ORDER BY name LIMIT 8', [$id]);
        }
        return $groups;
    }

    public static function displayValue(array $field, $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (($field['type'] ?? '') === 'rel' && !empty($field['table'])) {
            $rel = db()->find($field['table'], $value);
            $col = $field['label_col'] ?? 'name';
            return $rel[$col] ?? ('#' . $value);
        }
        if (($field['type'] ?? '') === 'select' && !empty($field['options'][$value])) {
            return $field['options'][$value];
        }
        if (in_array($field['type'] ?? '', ['date'], true)) {
            return dater($value);
        }
        if (str_contains((string) ($field['label'] ?? ''), 'Amount') || str_contains((string) ($field['label'] ?? ''), 'Cost') || str_contains((string) ($field['label'] ?? ''), 'price') || str_contains((string) ($field['label'] ?? ''), 'Pay') || ($field['label'] ?? '') === 'Total' || ($field['label'] ?? '') === 'Balance' || ($field['label'] ?? '') === 'Principal') {
            return is_numeric($value) ? money($value) : e($value);
        }
        return (string) $value;
    }
}
