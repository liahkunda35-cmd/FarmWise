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
        $def = WorkerRecords::withoutValuation($def);

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
        if (($def['table'] ?? '') === 'farms') {
            [$clause, $clauseParams] = farm_directory_constraint();
            $where .= ' AND ' . $clause;
            $params = array_merge($params, $clauseParams);
        } elseif (!empty($def['farm_scoped'])) {
            [$clause, $clauseParams] = active_farm_constraint('farm_id');
            $where .= ' AND ' . $clause;
            $params = array_merge($params, $clauseParams);
        }
        // Workers: only tasks assigned to their linked employee record
        if (($def['table'] ?? '') === 'tasks' && Auth::role() === 'worker') {
            $empId = class_exists('TaskAccess') ? TaskAccess::employeeIdForUser() : null;
            if ($empId) {
                $where .= ' AND assigned_to = ?';
                $params[] = $empId;
            } else {
                $where .= ' AND 1=0';
            }
        }
        // Workers: only their own attendance rows
        if (($def['table'] ?? '') === 'attendance' && Auth::role() === 'worker') {
            $empId = class_exists('TaskAccess') ? TaskAccess::employeeIdForUser() : null;
            if ($empId) {
                $where .= ' AND employee_id = ?';
                $params[] = $empId;
            } else {
                $where .= ' AND 1=0';
            }
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
        // Relation filters from workflow links (?planting_id=, ?field_id=, etc.)
        foreach ($def['fields'] as $name => $field) {
            if (($field['type'] ?? '') !== 'rel' && !str_ends_with($name, '_id')) {
                continue;
            }
            $val = input($name);
            if ($val === null || $val === '') {
                continue;
            }
            if (!is_numeric($val)) {
                continue;
            }
            $where .= ' AND ' . $name . ' = ?';
            $params[] = (int) $val;
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
        // Quotation → PO workflow: /module/purchase_orders/create?quotation_id=N
        if (!$id && $module === 'purchase_orders') {
            $qid = (int) input('quotation_id', 0);
            if ($qid > 0) {
                $quote = db()->find('quotations', $qid);
                if ($quote && Auth::canAccessFarm((int) ($quote['farm_id'] ?? 0))) {
                    $existingPo = db()->first('SELECT id FROM purchase_orders WHERE quotation_id = ?', [$qid]);
                    if ($existingPo) {
                        flash('success', 'This quotation already has a draft purchase order.');
                        redirect('module/purchase_orders/view/' . (int) $existingPo['id']);
                    }
                    if ((string) ($quote['status'] ?? '') !== 'accepted') {
                        flash('error', 'Accept the quotation before converting it to a purchase order.');
                        redirect('module/quotations/view/' . $qid);
                    }
                    $row = array_merge($row, [
                        'supplier_id' => $quote['supplier_id'] ?? null,
                        'quotation_id' => $qid,
                        'item_name' => $quote['item_name'] ?? null,
                        'quantity' => $quote['quantity'] ?? null,
                        'unit_price' => $quote['unit_price'] ?? null,
                        'total' => $quote['total'] ?? null,
                        'order_date' => date('Y-m-d'),
                        'status' => 'pending_approval',
                        'payment_status' => 'unpaid',
                        'po_number' => 'PO-' . date('Ymd') . '-' . $qid,
                        'notes' => 'Converted from quotation #' . ($quote['quote_number'] ?? (string) $qid),
                    ]);
                }
            }
        }
        if (!$id && $module === 'payroll') {
            $employeeId = (int) ($row['employee_id'] ?? input('employee_id', 0));
            $start = (string) ($row['period_start'] ?? input('period_start', ''));
            $end = (string) ($row['period_end'] ?? input('period_end', ''));
            if ($employeeId > 0 && $start !== '' && $end !== '') {
                $employee = db()->find('employees', $employeeId);
                $farmId = (int) ($employee['farm_id'] ?? 0);
                if ($employee && Auth::canAccessFarm($farmId)) {
                    $suggestion = WorkflowService::attendanceSuggestion($employeeId, $farmId, $start, $end);
                    $row = array_merge($row, [
                        'employee_id' => $employeeId,
                        'farm_id' => $farmId,
                        'period_start' => $start,
                        'period_end' => $end,
                        'status' => 'draft',
                        'days_worked' => $suggestion['days_worked'],
                        'gross_pay' => $suggestion['gross_pay'],
                        'net_pay' => $suggestion['net_pay'],
                        'calculation_note' => $suggestion['calculation_note'],
                    ]);
                }
            }
        }
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
            $raw = input($name, '');
            if (is_string($raw)) {
                $raw = trim($raw);
            }
            // Hidden workflow links are preserved on edit so a normal save cannot clear them.
            if (!empty($field['hide_in_form']) && $id && ($raw === '' || $raw === null)) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');
            if ($raw === '' || $raw === null) {
                $payload[$name] = null;
                continue;
            }
            if ($type === 'tel') {
                $normalized = normalize_phone((string) $raw);
                // Keep a non-empty value when invalid so Validator rejects instead of treating as blank.
                $payload[$name] = valid_phone($normalized) ? $normalized : (string) $raw;
            } elseif ($type === 'email') {
                $payload[$name] = strtolower(trim((string) $raw));
            } elseif (in_array($type, ['number', 'decimal', 'float', 'integer'], true) && is_numeric($raw)) {
                $payload[$name] = $raw;
            } else {
                $payload[$name] = $raw;
            }
        }
        // Farms: build a readable location summary; keep coords optional
        if (($def['table'] ?? '') === 'farms') {
            $parts = array_filter([
                $payload['town'] ?? null,
                $payload['district'] ?? null,
                $payload['province'] ?? null,
                $payload['country'] ?? null,
            ], static fn ($p) => $p !== null && $p !== '');
            if (!empty($payload['landmark'])) {
                array_unshift($parts, (string) $payload['landmark']);
            }
            if ($parts) {
                $payload['location'] = implode(', ', $parts);
            }
            $prec = (string) ($payload['location_precision'] ?? 'none');
            if (!in_array($prec, ['none', 'approximate', 'confirmed'], true)) {
                $prec = 'none';
            }
            $hasCoords = isset($payload['latitude'], $payload['longitude'])
                && $payload['latitude'] !== null && $payload['longitude'] !== null
                && $payload['latitude'] !== '' && $payload['longitude'] !== '';
            if (!$hasCoords) {
                $payload['location_precision'] = $parts ? 'none' : 'none';
                // Still allow saving — weather will ask for a map pin later
            } elseif ($prec === 'none') {
                $payload['location_precision'] = 'approximate';
            } else {
                $payload['location_precision'] = $prec;
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
        // Loans: balance is NOT NULL in Postgres — default to principal when omitted on create/update.
        if (($def['table'] ?? '') === 'loans') {
            if (($payload['balance'] === null || $payload['balance'] === '') && $payload['principal'] !== null && $payload['principal'] !== '') {
                $payload['balance'] = $payload['principal'];
            }
        }
        foreach ($def['fields'] as $name => $field) {
            if (!empty($field['auto_farm']) && !$payload[$name] && Auth::farmId()) {
                $payload[$name] = Auth::farmId();
            }
        }
        if (!empty($def['farm_scoped']) && !Auth::isAdmin()) {
            $fid = Auth::farmId();
            if (!$fid || !Auth::canAccessFarm((int) $fid)) {
                flash('error', 'Select a farm you can access before saving records.');
                redirect($id ? 'module/' . $module . '/edit/' . $id : 'module/' . $module . '/create');
            }
            if (array_key_exists('farm_id', $def['fields'])) {
                $payload['farm_id'] = $fid;
            }
        }
        // Tasks: map ?employee_id= to assigned_to; set creator; accept related_* from query
        if (($def['table'] ?? '') === 'tasks') {
            if (empty($payload['assigned_to']) && input('employee_id')) {
                $payload['assigned_to'] = (int) input('employee_id');
            }
            if (!$id && empty($payload['created_by']) && Auth::id()) {
                $payload['created_by'] = Auth::id();
            }
            if (empty($payload['related_module']) && input('related_module')) {
                $payload['related_module'] = preg_replace('/[^a-z0-9_]/', '', strtolower((string) input('related_module')));
            }
            if (empty($payload['related_id']) && input('related_id') && is_numeric(input('related_id'))) {
                $payload['related_id'] = (int) input('related_id');
            }
            // Workers may only update status/description on their own tasks
            if ($id && Auth::role() === 'worker') {
                $existing = db()->find('tasks', $id);
                if ($existing && class_exists('TaskAccess') && !TaskAccess::workerCanAccessTask($existing)) {
                    abort(403, 'You can only update tasks assigned to you.');
                }
                $allowed = ['status', 'description'];
                foreach (array_keys($payload) as $key) {
                    if (!in_array($key, $allowed, true) && isset($existing[$key])) {
                        $payload[$key] = $existing[$key];
                    }
                }
                $payload['farm_id'] = $existing['farm_id'] ?? $payload['farm_id'];
                $payload['assigned_to'] = $existing['assigned_to'] ?? $payload['assigned_to'];
                $payload['title'] = $existing['title'] ?? $payload['title'];
            }
        }
        if (($def['table'] ?? '') === 'crop_growth' && !$id && Auth::id()) {
            $payload['recorded_by'] = Auth::id();
        }
        // Strip cost before validation/hooks so workers cannot post ledger amounts.
        $payload = WorkerRecords::sanitizePayload($module, $payload);
        $workerErr = WorkerRecords::saveError($module, $payload, $id ? db()->find($def['table'], $id) : null);
        if ($workerErr !== null) {
            flash('error', $workerErr);
            redirect($id ? 'module/' . $module . '/edit/' . $id : 'module/' . $module . '/create');
        }
        $errors = Validator::validate($payload, $def['fields']);
        if (($def['table'] ?? '') === 'tasks' && empty($errors) && Auth::role() !== 'worker') {
            if ($msg = TaskAccess::validateAssignee(
                !empty($payload['assigned_to']) ? (int) $payload['assigned_to'] : null,
                !empty($payload['farm_id']) ? (int) $payload['farm_id'] : Auth::farmId()
            )) {
                $errors['assigned_to'] = $msg;
            }
        }
        if (($def['table'] ?? '') === 'stock_movements') {
            if (!$id && empty($payload['created_by']) && Auth::id()) {
                $payload['created_by'] = Auth::id();
            }
            if (empty($errors)) {
                if ($msg = OperationHooks::validateStockOut($payload, $id ? (int) $id : null)) {
                    $errors['quantity'] = $msg;
                }
            }
        }
        $usageTables = ['fertilizer_applications', 'pest_treatments', 'livestock_feed', 'spray_schedules'];
        if (in_array($def['table'] ?? '', $usageTables, true) && empty($errors)) {
            $prevUsage = $id ? db()->find($def['table'], $id) : null;
            if ($msg = OperationHooks::validateInventoryUsage($def['table'], $payload, $prevUsage ?: null)) {
                $errors['quantity'] = $msg;
            }
        }
        if (($def['table'] ?? '') === 'dispatches' && empty($errors)) {
            if ($msg = OperationHooks::validateDispatch($payload)) {
                $errors['quantity'] = $msg;
            }
        }
        if (($def['table'] ?? '') === 'sales_orders' && empty($errors)) {
            if ($msg = OperationHooks::validateSalesOrder($payload)) {
                $errors['quantity'] = $msg;
            }
            $prevSo = $id ? db()->find('sales_orders', $id) : null;
            if ($msg = ReversalService::validateSalesOrderCancel($payload, $prevSo ?: null)) {
                $errors['status'] = $msg;
            }
        }
        if (($def['table'] ?? '') === 'storage_lots' && empty($errors)) {
            if ($msg = OperationHooks::validateStorageLot($payload, $id ? (int) $id : null)) {
                $errors['harvest_id'] = $msg;
            }
        }
        if (($def['table'] ?? '') === 'payments' && empty($errors) && !$id) {
            if ($msg = OperationHooks::validatePayment($payload)) {
                $errors['amount'] = $msg;
            }
        }
        if (($def['table'] ?? '') === 'tasks' && empty($errors)) {
            $fieldId = (int) ($payload['field_id'] ?? 0);
            $farmId = (int) ($payload['farm_id'] ?? 0);
            if ($fieldId > 0) {
                $field = db()->find('fields', $fieldId);
                if (!$field || (int) ($field['farm_id'] ?? 0) !== $farmId) {
                    $errors['field_id'] = 'Choose a field on this farm.';
                }
            }
            if ((string) ($payload['related_module'] ?? '') === 'pest_scouting' && (int) ($payload['related_id'] ?? 0) > 0) {
                $scout = db()->find('pest_scouting', (int) $payload['related_id']);
                if ($scout && empty($payload['field_id']) && !empty($scout['field_id'])) {
                    $payload['field_id'] = (int) $scout['field_id'];
                }
                if ($msg = WorkflowService::scoutLinkError($payload, $id ? (int) $id : null)) {
                    if (!$id && $msg === 'A follow-up task already exists for this scouting record.') {
                        $existingTask = WorkflowService::findScoutFollowUp((int) $payload['related_id']);
                        if ($existingTask) {
                            flash('success', 'A follow-up task already exists for this scouting record.');
                            redirect('module/tasks/view/' . $existingTask);
                        }
                    }
                    $errors['title'] = $msg;
                }
            }
        }
        if (($def['table'] ?? '') === 'pest_scouting' && empty($errors)) {
            $farmId = (int) ($payload['farm_id'] ?? 0);
            $fieldId = (int) ($payload['field_id'] ?? 0);
            if ($fieldId > 0) {
                $field = db()->find('fields', $fieldId);
                if (!$field || (int) ($field['farm_id'] ?? 0) !== $farmId) {
                    $errors['field_id'] = 'Choose a field on this farm.';
                }
            }
            $plantingId = (int) ($payload['planting_id'] ?? 0);
            if ($plantingId > 0) {
                $planting = db()->find('plantings', $plantingId);
                if (!$planting || (int) ($planting['farm_id'] ?? 0) !== $farmId) {
                    $errors['planting_id'] = 'Choose a planting on this farm.';
                }
            }
        }
        if (($def['table'] ?? '') === 'purchase_orders' && empty($errors)) {
            $qid = (int) ($payload['quotation_id'] ?? 0);
            if ($qid > 0) {
                $quote = db()->find('quotations', $qid);
                if (!$quote || (int) ($quote['farm_id'] ?? 0) !== (int) ($payload['farm_id'] ?? 0)) {
                    $errors['supplier_id'] = 'That quotation is not on this farm.';
                } elseif ((string) ($quote['status'] ?? '') !== 'accepted') {
                    $errors['supplier_id'] = 'Only an accepted quotation can be converted to a purchase order.';
                } elseif ((int) ($quote['supplier_id'] ?? 0) !== (int) ($payload['supplier_id'] ?? 0)) {
                    $errors['supplier_id'] = 'The purchase order supplier must match the quotation.';
                } else {
                    $dup = db()->first('SELECT id FROM purchase_orders WHERE quotation_id = ?', [$qid]);
                    if ($dup && (int) $dup['id'] !== (int) $id) {
                        $errors['po_number'] = 'This quotation was already converted to a purchase order.';
                    }
                }
            }
        }
        if (($def['table'] ?? '') === 'payroll' && empty($errors)) {
            $previousPay = $id ? db()->find('payroll', $id) : null;
            $lockedPay = $previousPay && in_array((string) ($previousPay['status'] ?? ''), ['approved', 'paid'], true);
            $nextStatus = (string) ($payload['status'] ?? 'draft');
            if ($lockedPay && $previousPay) {
                foreach (['days_worked', 'gross_pay', 'net_pay', 'deductions', 'calculation_note'] as $payField) {
                    if (($payload[$payField] ?? null) === null || $payload[$payField] === '') {
                        $payload[$payField] = $previousPay[$payField] ?? null;
                    }
                }
            } elseif (in_array($nextStatus, ['approved', 'paid'], true)) {
                $daysBlank = ($payload['days_worked'] ?? null) === null || $payload['days_worked'] === '';
                $grossBlank = ($payload['gross_pay'] ?? null) === null || $payload['gross_pay'] === '';
                if ($daysBlank || $grossBlank) {
                    $errors['status'] = 'Save a reviewed draft with days and pay before approving or marking payroll paid.';
                }
            }
            if (!$lockedPay) {
                $payload = WorkflowService::applyAttendanceSuggestion($payload, $previousPay ?: null);
            }
        }
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
            db()->beginTransaction();
            try {
                db()->update($def['table'], $payload, $id);
                OperationHooks::afterSave($def, $payload, (int) $id, false, $row);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                $msg = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Could not save changes. Please check stock levels and try again.';
                flash('error', $msg);
                redirect('module/' . $module . '/edit/' . $id);
            }
            Audit::log('update', $module, $id, $def['singular'] . ' updated');
            flash('success', $def['singular'] . ' updated.');
        } else {
            $newId = 0;
            db()->beginTransaction();
            try {
                if (($def['table'] ?? '') === 'tasks' && (string) ($payload['related_module'] ?? '') === 'pest_scouting') {
                    $scoutId = (int) ($payload['related_id'] ?? 0);
                    WorkflowService::lockScoutFollowUp($scoutId);
                    $existingTask = WorkflowService::findScoutFollowUp($scoutId);
                    if ($existingTask) {
                        db()->rollBack();
                        flash('success', 'A follow-up task already exists for this scouting record.');
                        redirect('module/tasks/view/' . $existingTask);
                    }
                }
                $newId = (int) db()->insert($def['table'], $payload);
                OperationHooks::afterSave($def, $payload, $newId, true, null);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                if (($def['table'] ?? '') === 'tasks' && (string) ($payload['related_module'] ?? '') === 'pest_scouting') {
                    $existingTask = WorkflowService::findScoutFollowUp((int) ($payload['related_id'] ?? 0));
                    if ($existingTask && str_contains(strtolower($e->getMessage()), 'idx_tasks_scout_followup')) {
                        flash('success', 'A follow-up task already exists for this scouting record.');
                        redirect('module/tasks/view/' . $existingTask);
                    }
                }
                $msg = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Could not save record. Please check stock levels and try again.';
                flash('error', $msg);
                redirect('module/' . $module . '/create');
            }
            Audit::log('create', $module, $newId, $def['singular'] . ' created');
            flash('success', $def['singular'] . ' saved.');
        }
        redirect('module/' . $module);
    }

    private static function destroy(string $module, array $def, $id): void
    {
        if (!is_post()) {
            flash('error', 'Use the Delete button to remove a record.');
            redirect('module/' . $module);
        }
        Csrf::verify();
        $row = db()->find($def['table'], $id);
        if (!$row) {
            flash('error', $def['singular'] . ' was not found (it may already be deleted).');
            redirect('module/' . $module);
        }
        if (!Auth::canAccessRecord($row)) {
            abort(403, 'You cannot access this record.');
        }
        if (($def['table'] ?? '') === 'farms' && !Auth::isAdmin() && !Auth::canAccessFarm((int) $row['id'])) {
            abort(403, 'You cannot access this record.');
        }
        if (($def['table'] ?? '') === 'tasks' && class_exists('TaskAccess') && !TaskAccess::workerCanAccessTask($row)) {
            abort(403, 'You can only update tasks assigned to you.');
        }
        if ($msg = ReversalService::validateDelete($def['table'] ?? '', $row)) {
            flash('error', $msg);
            redirect('module/' . $module . '/view/' . $id);
        }
        // Optional reverse actions via POST reverse=1
        if (input('reverse') && ($def['table'] ?? '') === 'stock_movements') {
            $res = ReversalService::reverseStockMovement((int) $id, (string) (input('reason') ?: 'User requested reversal'));
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Compensating stock movement recorded.' : ($res['error'] ?? 'Reversal failed.'));
            redirect('module/' . $module);
        }
        if (input('refund') && ($def['table'] ?? '') === 'payments') {
            $res = ReversalService::refundPayment((int) $id, (string) (input('reason') ?: 'User requested refund'));
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Refund recorded with ledger offset.' : ($res['error'] ?? 'Refund failed.'));
            redirect('module/' . $module);
        }
        try {
            db()->delete($def['table'], $id);
        } catch (Throwable $e) {
            flash('error', 'Could not delete this ' . strtolower($def['singular']) . ' because related records still depend on it. Remove those first.');
            redirect('module/' . $module);
        }
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
        if (($def['table'] ?? '') === 'farms' && !Auth::isAdmin() && !Auth::canAccessFarm((int) $row['id'])) {
            abort(403, 'You cannot access this record.');
        }
        if (($def['table'] ?? '') === 'tasks' && class_exists('TaskAccess') && !TaskAccess::workerCanAccessTask($row)) {
            abort(403, 'You can only view tasks assigned to you.');
        }
        if (($def['table'] ?? '') === 'attendance' && Auth::role() === 'worker') {
            $empId = TaskAccess::employeeIdForUser();
            if (!$empId || (int) ($row['employee_id'] ?? 0) !== $empId) {
                abort(403, 'You can only view your own attendance.');
            }
        }
        return $row;
    }

    /** @deprecated Use OperationHooks::afterSave */
    private static function afterSave(array $def, array $payload, int $id, bool $created, ?array $previous = null): void
    {
        OperationHooks::afterSave($def, $payload, $id, $created, $previous);
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
            $add(
                'Related expenses',
                'transactions',
                'description',
                "SELECT * FROM transactions WHERE farm_id = ? AND (
                    (related_module = 'fertilizer_applications' AND related_id IN (SELECT id FROM fertilizer_applications WHERE planting_id = ?))
                    OR (related_module IN ('pest_treatments','spray_schedules','water_logs') AND related_id IS NOT NULL)
                ) ORDER BY txn_date DESC LIMIT 8",
                [$row['farm_id'] ?? 0, $id]
            );
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
