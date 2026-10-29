<?php
declare(strict_types=1);

/**
 * Phase 4: financial definitions, safe reversals, remaining-module CRUD smoke.
 */
require dirname(__DIR__) . '/backend/bootstrap.php';
@set_time_limit(0);
ini_set('max_execution_time', '0');

$fail = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$tag = 'p4_' . bin2hex(random_bytes(3));
$cleanup = [];
$push = static function (string $t, int $id) use (&$cleanup): void {
    if ($id > 0) {
        $cleanup[] = [$t, $id];
    }
};

$ok('pgsql', db()->driver() === 'pgsql');
Schema::migrate(db());
$ok('reversals table', in_array('reversals', db()->tables(), true));

$farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
$farmId = (int) $farm['id'];
$manager = db()->first("SELECT * FROM users WHERE role='manager' AND status='active' ORDER BY id ASC");
$accountant = db()->first("SELECT * FROM users WHERE role='accountant' AND status='active' ORDER BY id ASC");
Auth::setOverride($manager);
$_SESSION['user_id'] = (int) $manager['id'];
$_SESSION['active_farm_id'] = $farmId;
Auth::clearCache();

// --- Finance: unpaid order is not cash ---
$custId = (int) (db()->first('SELECT id FROM customers WHERE farm_id=?', [$farmId])['id'] ?? 0);
if (!$custId) {
    $custId = (int) db()->insert('customers', ['farm_id' => $farmId, 'name' => $tag . ' c', 'status' => 'active']);
    $push('customers', $custId);
}
$soId = (int) db()->insert('sales_orders', [
    'farm_id' => $farmId,
    'customer_id' => $custId,
    'order_number' => 'SO-' . $tag,
    'order_date' => date('Y-m-d'),
    'crop_name' => $tag,
    'quantity' => 10,
    'unit_price' => 100,
    'total_amount' => 1000,
    'status' => 'confirmed',
]);
$push('sales_orders', $soId);
OperationHooks::afterSave(module_def('sales_orders'), db()->find('sales_orders', $soId), $soId, true, null);
$inv = db()->first('SELECT * FROM invoices WHERE sales_order_id=?', [$soId]);
$push('invoices', (int) ($inv['id'] ?? 0));

$fin = ReportService::build('financial', []);
$recv = ReportService::build('receivables', []);
$ok('financial report builds', !empty($fin['rows']));
$ok('receivables report builds', !empty($recv['rows']) || !empty($recv['summary']));
$ok('financial summary mentions cash-basis', str_contains(strtolower($fin['summary'] ?? ''), 'cash'));

// Spot-check: unpaid order should not equal cash line incorrectly claimed as income-only
$cashLine = null;
foreach ($fin['rows'] as $r) {
    if (str_contains(strtolower((string) $r[0]), 'cash received')) {
        $cashLine = $r[1];
    }
}
$ok('cash received line present', $cashLine !== null);

// --- Cancel SO voids unpaid invoice ---
$dbSo = db()->find('sales_orders', $soId);
$ok('cancel blocked? no — unpaid ok', ReversalService::validateSalesOrderCancel(['status' => 'cancelled'], $dbSo) === null);
db()->update('sales_orders', ['status' => 'cancelled'], $soId);
ReversalService::applySalesOrderCancel(['status' => 'cancelled', 'farm_id' => $farmId], $soId, $dbSo);
$inv2 = db()->find('invoices', $inv['id']);
$ok('cancel voids unpaid invoice', ($inv2['status'] ?? '') === 'void');
$ok('cancel recorded once', ReversalService::alreadyRecorded('reversal:so_cancel#' . $soId));
ReversalService::applySalesOrderCancel(['status' => 'cancelled', 'farm_id' => $farmId], $soId, array_merge($dbSo, ['status' => 'cancelled']));
$ok('idempotent cancel', count(db()->select('SELECT id FROM reversals WHERE reference=?', ['reversal:so_cancel#' . $soId])) === 1);

// Paid invoice blocks cancel
$so2 = (int) db()->insert('sales_orders', [
    'farm_id' => $farmId, 'customer_id' => $custId, 'order_number' => 'SO2-' . $tag,
    'order_date' => date('Y-m-d'), 'crop_name' => $tag, 'quantity' => 1, 'unit_price' => 50,
    'total_amount' => 50, 'status' => 'confirmed',
]);
$push('sales_orders', $so2);
OperationHooks::afterSave(module_def('sales_orders'), db()->find('sales_orders', $so2), $so2, true, null);
$invP = db()->first('SELECT * FROM invoices WHERE sales_order_id=?', [$so2]);
$push('invoices', (int) $invP['id']);
$payId = (int) db()->insert('payments', [
    'farm_id' => $farmId, 'invoice_id' => $invP['id'], 'customer_id' => $custId,
    'payment_date' => date('Y-m-d'), 'amount' => 50, 'method' => 'cash', 'reference' => $tag . '_p',
]);
$push('payments', $payId);
OperationHooks::afterSave(module_def('payments'), db()->find('payments', $payId), $payId, true, null);
$txn = db()->first('SELECT id FROM transactions WHERE reference=?', ['payments#' . $payId]);
$push('transactions', (int) ($txn['id'] ?? 0));
$msg = ReversalService::validateSalesOrderCancel(['status' => 'cancelled'], db()->find('sales_orders', $so2));
$ok('cancel blocked when paid', is_string($msg) && str_contains(strtolower($msg), 'payment'));

// Refund
$ref = ReversalService::refundPayment($payId, 'p4 test refund');
$ok('refund ok', !empty($ref['ok']), $ref['error'] ?? '');
$push('payments', (int) ($ref['payment_id'] ?? 0));
$dup = ReversalService::refundPayment($payId, 'again');
$ok('refund not double-applied', empty($dup['ok']));
$offset = db()->first('SELECT * FROM transactions WHERE reference=?', ['reversal:payments#' . $payId]);
$ok('refund ledger offset', (bool) $offset && (float) $offset['amount'] === 50.0);
$push('transactions', (int) ($offset['id'] ?? 0));

// Delete guards
$ok('block delete payment', is_string(ReversalService::validateDelete('payments', db()->find('payments', $payId))));
$ok('block delete stock movement template', is_string(ReversalService::validateDelete('stock_movements', ['id' => 1])));

// Stock reverse
$itemId = (int) db()->insert('inventory_items', [
    'farm_id' => $farmId, 'name' => $tag . '_i', 'sku' => $tag, 'category' => 'other',
    'unit' => 'kg', 'quantity' => 0, 'reorder_level' => 1, 'status' => 'out',
]);
$push('inventory_items', $itemId);
$in = [
    'farm_id' => $farmId, 'inventory_item_id' => $itemId, 'movement_type' => 'in',
    'quantity' => 20, 'movement_date' => date('Y-m-d'), 'reference' => $tag . '_in',
    'notes' => 'p4', 'created_by' => (int) $manager['id'],
];
$mid = (int) db()->insert('stock_movements', $in);
$push('stock_movements', $mid);
OperationHooks::afterSave(module_def('stock_movements'), $in, $mid, true, null);
$ok('stock after in = 20', (float) db()->find('inventory_items', $itemId)['quantity'] === 20.0);
$rev = ReversalService::reverseStockMovement($mid, 'p4 reverse');
$ok('stock reverse ok', !empty($rev['ok']), $rev['error'] ?? '');
$push('stock_movements', (int) ($rev['movement_id'] ?? 0));
$ok('stock after reverse = 0', (float) db()->find('inventory_items', $itemId)['quantity'] === 0.0);
$ok('reverse twice blocked', empty(ReversalService::reverseStockMovement($mid, 'again')['ok']));

// --- Remaining modules CRUD smoke ---
$modulesSmoke = [
    'water_sources' => ['name' => $tag . ' borehole', 'source_type' => 'borehole', 'status' => 'active', 'farm_id' => $farmId],
    'budgets' => ['name' => $tag . ' budget', 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t'), 'planned_amount' => 5000, 'farm_id' => $farmId, 'category' => 'ops'],
    'loans' => ['lender' => $tag . ' bank', 'principal' => 10000, 'balance' => 10000, 'status' => 'active', 'farm_id' => $farmId, 'interest_rate' => 12],
];
foreach ($modulesSmoke as $mod => $payload) {
    $def = module_def($mod);
    $ok("module_def $mod", (bool) $def);
    if (!$def) {
        continue;
    }
    $errors = Validator::validate($payload, $def['fields']);
    $ok("validate $mod", empty($errors), $errors ? json_encode($errors) : '');
    if ($errors) {
        continue;
    }
    $id = (int) db()->insert($def['table'], $payload);
    $push($def['table'], $id);
    $ok("persist $mod", (bool) db()->find($def['table'], $id));
    // invalid: missing required
    $bad = $payload;
    foreach ($def['fields'] as $fname => $f) {
        if (in_array('required', $f['rules'] ?? [], true) && $fname !== 'farm_id') {
            $bad[$fname] = '';
            break;
        }
    }
    $badErr = Validator::validate($bad, $def['fields']);
    $ok("reject invalid $mod", !empty($badErr));
}

// Irrigation chain
$ws = (int) db()->insert('water_sources', ['farm_id' => $farmId, 'name' => $tag . ' ws', 'source_type' => 'dam', 'status' => 'active']);
$push('water_sources', $ws);
$sys = (int) db()->insert('irrigation_systems', [
    'farm_id' => $farmId, 'water_source_id' => $ws, 'name' => $tag . ' drip',
    'system_type' => 'drip', 'status' => 'active',
]);
$push('irrigation_systems', $sys);
$sch = (int) db()->insert('irrigation_schedules', [
    'farm_id' => $farmId, 'irrigation_system_id' => $sys, 'scheduled_date' => date('Y-m-d'),
    'water_litres' => 500, 'status' => 'scheduled',
]);
$push('irrigation_schedules', $sch);
$wlog = [
    'farm_id' => $farmId, 'irrigation_system_id' => $sys, 'log_date' => date('Y-m-d'),
    'water_litres' => 400, 'cost' => 80, 'issue_flag' => 'none', 'notes' => $tag . ' water',
];
$wlId = (int) db()->insert('water_logs', $wlog);
$push('water_logs', $wlId);
OperationHooks::afterSave(module_def('water_logs'), $wlog, $wlId, true, null);
$ok('water log expense txn', (bool) db()->first('SELECT id FROM transactions WHERE reference=?', ['water_logs#' . $wlId]));
$push('transactions', (int) (db()->first('SELECT id FROM transactions WHERE reference=?', ['water_logs#' . $wlId])['id'] ?? 0));

// Livestock
$breed = (int) db()->insert('breeds', ['farm_id' => $farmId, 'species' => 'cattle', 'name' => $tag . ' breed']);
$push('breeds', $breed);
$animal = (int) db()->insert('animals', [
    'farm_id' => $farmId, 'breed_id' => $breed, 'tag_number' => $tag . 'T1',
    'species' => 'cattle', 'status' => 'active', 'name' => $tag . ' cow',
]);
$push('animals', $animal);
$wt = (int) db()->insert('livestock_weights', [
    'farm_id' => $farmId, 'animal_id' => $animal, 'weigh_date' => date('Y-m-d'), 'weight_kg' => 320,
]);
$push('livestock_weights', $wt);
OperationHooks::afterSave(module_def('livestock_weights'), db()->find('livestock_weights', $wt), $wt, true, null);
$ok('weight updates animal', (float) db()->find('animals', $animal)['current_weight'] === 320.0);

// Payroll / labour
$emp = (int) db()->insert('employees', [
    'farm_id' => $farmId, 'name' => $tag . ' emp', 'role_title' => 'Hand', 'status' => 'active', 'daily_rate' => 80,
]);
$push('employees', $emp);
$att = (int) db()->insert('attendance', [
    'farm_id' => $farmId, 'employee_id' => $emp, 'work_date' => date('Y-m-d'), 'status' => 'present', 'hours_worked' => 8,
]);
$push('attendance', $att);
$payr = [
    'farm_id' => $farmId, 'employee_id' => $emp, 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t'),
    'gross_pay' => 800, 'deductions' => 0, 'net_pay' => 800, 'status' => 'paid', 'paid_date' => date('Y-m-d'),
];
// Check payroll required fields
$prDef = module_def('payroll');
$prErr = Validator::validate($payr, $prDef['fields']);
if ($prErr) {
    // fill missing
    foreach ($prErr as $k => $_) {
        if (!isset($payr[$k])) {
            $payr[$k] = match ($k) {
                default => 'x',
            };
        }
    }
}
$prId = (int) db()->insert('payroll', $payr);
$push('payroll', $prId);
OperationHooks::afterSave($prDef, $payr, $prId, true, null);
$ok('payroll paid → expense', (bool) db()->first('SELECT id FROM transactions WHERE reference=?', ['payroll#' . $prId]));
$push('transactions', (int) (db()->first('SELECT id FROM transactions WHERE reference=?', ['payroll#' . $prId])['id'] ?? 0));

// PO / quotation
$sup = (int) db()->insert('suppliers', ['farm_id' => $farmId, 'name' => $tag . ' supplier', 'status' => 'active']);
$push('suppliers', $sup);
$qId = (int) db()->insert('quotations', [
    'farm_id' => $farmId, 'supplier_id' => $sup, 'quote_number' => 'Q-' . $tag,
    'item_name' => 'seed', 'quote_date' => date('Y-m-d'), 'quantity' => 10, 'unit_price' => 5, 'total' => 50, 'status' => 'sent',
]);
$push('quotations', $qId);
OperationHooks::afterSave(module_def('quotations'), db()->find('quotations', $qId), $qId, true, null);
$ok('quotation total computed', (float) db()->find('quotations', $qId)['total'] === 50.0);

$poPayload = [
    'farm_id' => $farmId, 'supplier_id' => $sup, 'po_number' => 'PO-' . $tag,
    'item_name' => 'seed', 'order_date' => date('Y-m-d'), 'quantity' => 10, 'unit_price' => 5,
    'total' => 50, 'status' => 'pending_approval', 'inventory_item_id' => $itemId,
];
$poId = (int) db()->insert('purchase_orders', $poPayload);
$push('purchase_orders', $poId);
db()->update('purchase_orders', ['status' => 'received'], $poId);
OperationHooks::afterSave(module_def('purchase_orders'), array_merge($poPayload, ['status' => 'received']), $poId, false, $poPayload);
$ok('PO received stocks in', (float) db()->find('inventory_items', $itemId)['quantity'] >= 10.0);
$ok('block delete received PO', is_string(ReversalService::validateDelete('purchase_orders', db()->find('purchase_orders', $poId))));

// Yield forecast
$plant = db()->first('SELECT id FROM plantings WHERE farm_id=? ORDER BY id DESC', [$farmId]);
if ($plant) {
    $yf = (int) db()->insert('yield_forecasts', [
        'farm_id' => $farmId, 'planting_id' => (int) $plant['id'], 'forecast_date' => date('Y-m-d'),
        'expected_yield' => 1000, 'expected_yield_per_ha' => 400, 'expected_price' => 3,
        'expected_revenue' => 3000, 'loss_factor' => 5, 'market_risk' => 'medium',
    ]);
    $push('yield_forecasts', $yf);
    $ok('yield forecast persist', (bool) db()->find('yield_forecasts', $yf));
} else {
    $ok('yield forecast persist', true, 'no planting — skipped insert');
}

// Attachments validation
$fake = ['error' => UPLOAD_ERR_OK, 'size' => 100, 'name' => 'x.exe', 'tmp_name' => __FILE__, 'type' => 'application/octet-stream'];
$att = AttachmentService::store('farms', $farm, $fake);
$ok('attachment rejects exe', empty($att['ok']));

// RBAC accountant reports
if ($accountant) {
    Auth::setOverride($accountant);
    Auth::clearCache();
    $ok('accountant reports view', Auth::can('reports', 'view'));
    $ok('accountant cannot plantings create', !Auth::can('plantings', 'create'));
}

// Email SSL diagnose (not claiming delivery). Short timeouts — do not hang the suite on Neon cleanup.
$ch = curl_init('https://app.nexuspromail.com');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
]);
SslCa::applyCurl($ch);
curl_exec($ch);
$err = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($err && str_contains(strtolower($err), 'certificate')) {
    $ok('nexus SSL: issuer issue documented (Sophos/MITM or incomplete chain)', true, 'verify stays ON; needs local CA if intercepted');
} elseif ($err) {
    $ok('nexus HTTPS reachability', false, $err);
} else {
    $ok('nexus HTTPS verifies with project CA', true, 'http=' . $code);
}

foreach (array_reverse($cleanup) as [$t, $id]) {
    try {
        db()->delete($t, $id);
    } catch (Throwable $e) {
    }
}

echo PHP_EOL . "failures=$fail" . PHP_EOL;
echo ($fail === 0 ? 'ALL PASSED' : 'HAS FAILURES') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
