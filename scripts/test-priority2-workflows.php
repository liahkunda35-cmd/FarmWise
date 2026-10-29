<?php
declare(strict_types=1);

/**
 * Priority 2: role gates, scout task, quotation conversion, payroll suggestion, budget report.
 * Rows created here are rolled back.
 */
require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$as = static function (string $role, int $farmId = 0): void {
    Auth::setOverride([
        'id' => 900000200 + strlen($role),
        'role' => $role,
        'status' => 'active',
        'farm_id' => $farmId ?: null,
        'session_version' => 1,
    ]);
    $_SESSION['session_version'] = 1;
    $_SESSION['active_farm_id'] = $farmId ?: null;
};

$as('worker');
$ok('worker cannot open payroll', !Auth::can('payroll', 'view'));
$ok('worker cannot open transactions', !Auth::can('transactions', 'view'));
$ok('worker cannot create tasks for others', !Auth::can('tasks', 'create'));
$ok('worker can update assigned tasks', Auth::can('tasks', 'edit'));
$ok('worker cannot adjust stock', !Auth::can('stock_movements', 'create'));
$as('manager');
$ok('manager cannot record payments', !Auth::can('payments', 'create'));
$ok('manager can assign tasks', Auth::can('tasks', 'create'));
$as('agronomist');
$agroReports = array_keys(ReportService::visibleTypes());
$ok('agronomist cannot open payroll', !Auth::can('payroll', 'view'));
$ok('agronomist reports omit finance and payroll', !in_array('budget', $agroReports, true) && !in_array('financial', $agroReports, true) && !in_array('labour', $agroReports, true));
$ok('agronomist reports keep crop and scouting reports', in_array('crops', $agroReports, true) && in_array('pests', $agroReports, true));
$ok('agronomist cannot open sales orders', !Auth::can('sales_orders', 'view'));
$ok('agronomist can record scouting', Auth::can('pest_scouting', 'create'));
$as('accountant');
$ok('accountant can open budgets', Auth::can('budgets', 'view'));
$ok('accountant cannot create plantings', !Auth::can('plantings', 'create'));
$ok('accountant cannot assign tasks', !Auth::can('tasks', 'view'));
$as('owner');
$ok('owner cannot open audit logs', !Auth::can('audit_logs', 'view'));
$ok('owner cannot open integrations', !Auth::can('integrations', 'view'));
$ok('owner can view transactions', Auth::can('transactions', 'view'));
$as('admin');
$ok('admin can open audit logs', Auth::can('audit_logs', 'view'));
$ok('payment delete stays blocked', ReversalService::validateDelete('payments', ['id' => 1, 'status' => 'paid']) !== null);

$focusOf = static function (string $role, int $farmId) use ($as): array {
    $as($role, $farmId);
    $scope = [
        'farms' => ['sql' => 'WHERE id = ?', 'params' => [$farmId]],
        'plain' => ['sql' => 'WHERE farm_id = ?', 'params' => [$farmId]],
    ];
    [$taskSql, $taskParams] = TaskAccess::listConstraint();
    $method = new ReflectionMethod(DashboardController::class, 'focus');
    $method->setAccessible(true);
    return $method->invoke(null, $scope, [
        'farms' => 1,
        'fields' => 2,
        'active_crops' => 3,
        'pending_tasks' => 4,
    ], $taskSql, $taskParams);
};

db()->beginTransaction();
try {
    $marker = 'p2wf_' . bin2hex(random_bytes(3));
    $farmId = (int) db()->insert('farms', [
        'name' => $marker,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $otherFarm = (int) db()->insert('farms', [
        'name' => $marker . '_other',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $fieldId = (int) db()->insert('fields', [
        'farm_id' => $farmId,
        'name' => $marker . ' field',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $pestId = (int) db()->insert('pest_catalog', [
        'farm_id' => $farmId,
        'name' => $marker . ' armyworm',
        'recommended_control' => 'Scout and spray the affected rows',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $scout = [
        'farm_id' => $farmId,
        'field_id' => $fieldId,
        'pest_id' => $pestId,
        'severity' => 'high',
        'notes' => $marker . ' observation',
        'status' => 'reported',
    ];
    $taskA = WorkflowService::ensureScoutTask($scout, 880001);
    $taskB = WorkflowService::ensureScoutTask($scout, 880001);
    $ok('high severity scouting creates a draft task', $taskA > 0);
    $ok('repeating the scouting follow-up does not create another task', $taskA === $taskB);
    $taskRow = db()->find('tasks', (int) $taskA);
    $ok('draft task stays on the scouting farm', (int) ($taskRow['farm_id'] ?? 0) === $farmId);
    $ok('draft task is unassigned for a manager to review', empty($taskRow['assigned_to']));
    $ok('draft task includes the recommended action', str_contains((string) ($taskRow['description'] ?? ''), 'spray'));
    $ok('low severity does not create a task', WorkflowService::ensureScoutTask(['farm_id' => $farmId, 'field_id' => $fieldId, 'severity' => 'low'], 880002) === null);

    $supplierId = (int) db()->insert('suppliers', [
        'farm_id' => $farmId,
        'name' => $marker . ' supplies',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $otherSupplier = (int) db()->insert('suppliers', [
        'farm_id' => $otherFarm,
        'name' => $marker . ' other supplies',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $quote = [
        'farm_id' => $farmId,
        'supplier_id' => $supplierId,
        'quote_number' => 'Q-' . $marker,
        'item_name' => 'Seed',
        'quantity' => 4,
        'unit_price' => 12.5,
        'total' => 50,
        'status' => 'received',
    ];
    $ok('a quotation that is not accepted does not become a purchase order', WorkflowService::ensurePurchaseOrder($quote, 880003) === null);
    $quote['status'] = 'accepted';
    $poA = WorkflowService::ensurePurchaseOrder($quote, 880004);
    $poB = WorkflowService::ensurePurchaseOrder($quote, 880004);
    $ok('accepted quotation creates one draft purchase order', $poA > 0 && $poA === $poB);
    $po = db()->find('purchase_orders', (int) $poA);
    $ok('purchase order copies supplier, quantity and price', (int) ($po['supplier_id'] ?? 0) === $supplierId && (float) $po['quantity'] === 4.0 && (float) $po['unit_price'] === 12.5);
    $ok('converted purchase order stays pending approval', ($po['status'] ?? '') === 'pending_approval');
    $ok('a supplier from another farm is not converted', WorkflowService::ensurePurchaseOrder([
        'farm_id' => $farmId,
        'supplier_id' => $otherSupplier,
        'item_name' => 'Seed',
        'quantity' => 1,
        'unit_price' => 1,
        'status' => 'accepted',
    ], 880005) === null);

    $employeeId = (int) db()->insert('employees', [
        'farm_id' => $farmId,
        'name' => $marker . ' worker',
        'daily_rate' => 100,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $start = '2026-10-01';
    $end = '2026-10-03';
    foreach ([
        ['2026-10-01', 'present'],
        ['2026-10-01', 'present'],
        ['2026-10-02', 'half_day'],
        ['2026-10-03', 'absent'],
    ] as [$day, $status]) {
        db()->insert('attendance', [
            'farm_id' => $farmId,
            'employee_id' => $employeeId,
            'work_date' => $day,
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $suggestion = WorkflowService::attendanceSuggestion($employeeId, $farmId, $start, $end);
    $ok('duplicate attendance is counted once and half days are included', (float) $suggestion['days_worked'] === 1.5);
    $ok('suggested pay uses the daily rate', (float) $suggestion['gross_pay'] === 150.0);
    $ok('duplicate dates are explained', str_contains($suggestion['calculation_note'], 'Duplicate'));
    $ok('absences are not paid', str_contains($suggestion['calculation_note'], 'absence'));
    $missing = WorkflowService::attendanceSuggestion($employeeId, $farmId, '2026-09-01', '2026-09-02');
    $ok('missing attendance is not treated as zero days', $missing['days_worked'] === null && str_contains($missing['calculation_note'], 'left blank'));
    $noRate = (int) db()->insert('employees', [
        'farm_id' => $farmId,
        'name' => $marker . ' norate',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    db()->insert('attendance', [
        'farm_id' => $farmId,
        'employee_id' => $noRate,
        'work_date' => '2026-10-01',
        'status' => 'present',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $unpriced = WorkflowService::attendanceSuggestion($noRate, $farmId, '2026-10-01', '2026-10-01');
    $ok('missing pay rate does not invent a wage', $unpriced['gross_pay'] === null && (float) $unpriced['days_worked'] === 1.0);
    $approved = WorkflowService::applyAttendanceSuggestion([
        'status' => 'draft',
        'employee_id' => $employeeId,
        'farm_id' => $farmId,
        'period_start' => $start,
        'period_end' => $end,
        'days_worked' => null,
        'gross_pay' => null,
        'net_pay' => null,
        'deductions' => null,
        'calculation_note' => '',
    ], ['status' => 'approved', 'days_worked' => 9, 'gross_pay' => 900]);
    $ok('approved payroll is not replaced by a new suggestion', $approved['days_worked'] === null && $approved['gross_pay'] === null);

    db()->insert('budgets', [
        'farm_id' => $farmId,
        'name' => $marker . ' labour',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'category' => 'labour',
        'planned_amount' => 200,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    db()->insert('budgets', [
        'farm_id' => $farmId,
        'name' => $marker . ' empty',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'category' => 'seed',
        'planned_amount' => 80,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    db()->insert('budgets', [
        'farm_id' => $otherFarm,
        'name' => $marker . ' foreign budget',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'category' => 'labour',
        'planned_amount' => 9999,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    db()->insert('transactions', [
        'farm_id' => $farmId,
        'txn_date' => '2026-10-15',
        'type' => 'expense',
        'category' => 'labour',
        'description' => $marker,
        'amount' => 40,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    db()->insert('transactions', [
        'farm_id' => $farmId,
        'txn_date' => '2026-10-16',
        'type' => 'income',
        'category' => 'sales',
        'description' => $marker,
        'amount' => 25,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $as('accountant', $farmId);
    $report = ReportService::build('budget', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
    $flat = json_encode($report);
    $ok('budget report includes the labour plan', is_string($flat) && str_contains($flat, $marker . ' labour'));
    $ok('budget report shows the cash expense', is_string($flat) && str_contains($flat, '40'));
    $ok('budget report keeps a category with no expenses distinct from zero', is_string($flat) && str_contains($flat, 'No expenses allocated'));
    db()->insert('budgets', [
        'farm_id' => $farmId,
        'name' => $marker . ' labour short',
        'period_start' => '2026-10-10',
        'period_end' => '2026-10-20',
        'category' => 'labour',
        'planned_amount' => 50,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    db()->insert('transactions', [
        'farm_id' => $farmId,
        'txn_date' => '2026-10-18',
        'type' => 'expense',
        'category' => 'fuel',
        'description' => $marker . ' fuel',
        'amount' => 11.11,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $overlap = ReportService::build('budget', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
    $overlapText = json_encode($overlap);
    $ok('overlapping budgets count a category expense once', is_string($overlapText) && substr_count($overlapText, '40.00') === 1);
    $ok('the shorter matching budget receives the expense', is_string($overlapText) && str_contains($overlapText, $marker . ' labour short') && str_contains($overlapText, 'No expenses allocated'));
    $ok('an expense with no budget is listed once as unallocated', is_string($overlapText) && str_contains($overlapText, 'Unallocated cash expenses') && substr_count($overlapText, '11.11') === 1);
    $ok('expense total adds allocated and unallocated without repeating rows', is_string($overlapText) && str_contains($overlapText, '51.11'));
    $scoutRowId = (int) db()->insert('pest_scouting', [
        'farm_id' => $farmId,
        'field_id' => $fieldId,
        'scout_date' => '2026-10-01',
        'severity' => 'high',
        'notes' => $marker,
        'status' => 'reported',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $linked = WorkflowService::ensureScoutTask(db()->find('pest_scouting', $scoutRowId), $scoutRowId);
    $ok('a second follow-up for the same observation is rejected', WorkflowService::scoutLinkError([
        'farm_id' => $farmId,
        'field_id' => $fieldId,
        'related_module' => 'pest_scouting',
        'related_id' => $scoutRowId,
    ], null) === 'A follow-up task already exists for this scouting record.');
    $ok('a follow-up on another farm is rejected', WorkflowService::scoutLinkError([
        'farm_id' => $otherFarm,
        'field_id' => $fieldId,
        'related_module' => 'pest_scouting',
        'related_id' => $scoutRowId,
    ], null) === 'That scouting record is not on this farm.');
    $otherScout = (int) db()->insert('pest_scouting', [
        'farm_id' => $farmId,
        'field_id' => $fieldId,
        'scout_date' => '2026-10-02',
        'severity' => 'critical',
        'notes' => $marker . ' other',
        'status' => 'reported',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $second = WorkflowService::ensureScoutTask(db()->find('pest_scouting', $otherScout), $otherScout);
    $ok('a different scouting observation can have its own task', $second > 0 && $second !== $linked);
    $index = db()->value("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_tasks_scout_followup_uq'");
    $ok('scouting follow-up has a unique index', $index === 'idx_tasks_scout_followup_uq');
    $ok('budget report does not include another farm', is_string($flat) && !str_contains($flat, 'foreign budget'));
    $ok('budget report states the cash basis', is_string($flat) && str_contains($flat, 'Unpaid purchase orders'));

    $workerFocus = $focusOf('worker', $farmId);
    $workerText = json_encode($workerFocus['cards']);
    $ok('worker dashboard is limited to assigned work', is_string($workerText) && str_contains($workerText, 'My open tasks') && !str_contains($workerText, 'Income'));
    $accountantFocus = $focusOf('accountant', $farmId);
    $accountantText = json_encode($accountantFocus['cards']);
    $ok('accountant dashboard shows cash and invoices', is_string($accountantText) && str_contains($accountantText, 'Unpaid invoices') && !str_contains($accountantText, 'Active crops'));
    $adminFocus = $focusOf('admin', $farmId);
    $ok('admin dashboard leads with account approvals', str_contains(json_encode($adminFocus['cards']), 'Accounts awaiting approval'));
    $ownerFocus = $focusOf('owner', $farmId);
    $ok('owner dashboard includes production and income', str_contains(json_encode($ownerFocus['cards']), 'Active crops') && str_contains(json_encode($ownerFocus['cards']), 'Income this month'));
    $managerFocus = $focusOf('manager', $farmId);
    $ok('manager dashboard includes tasks and inventory', str_contains(json_encode($managerFocus['cards']), 'Open tasks') && str_contains(json_encode($managerFocus['cards']), 'reorder'));
    $agroFocus = $focusOf('agronomist', $farmId);
    $ok('agronomist dashboard includes scouting', str_contains(json_encode($agroFocus['cards']), 'scouting'));
} catch (Throwable $e) {
    $ok('workflow fixture', false, $e->getMessage());
} finally {
    db()->rollBack();
    Auth::setOverride(null);
}

echo PHP_EOL . "failures=$fail" . PHP_EOL;
echo ($fail === 0 ? 'ALL PASSED' : 'HAS FAILURES') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
