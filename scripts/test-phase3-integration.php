<?php
declare(strict_types=1);

/**
 * Phase 3: role matrix, end-to-end business workflows, reports, notifications.
 * Additive tagged fixtures; cleans up where FKs allow. Runs against configured DB (Neon).
 */
require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$skip = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};
$skipped = static function (string $label, string $why) use (&$skip): void {
    $skip++;
    echo 'SKIP ' . $label . ' — ' . $why . PHP_EOL;
};

$tag = 'p3_' . bin2hex(random_bytes(3));
$cleanup = [];
$push = static function (string $table, int $id) use (&$cleanup): void {
    if ($id > 0) {
        $cleanup[] = [$table, $id];
    }
};

$driver = db()->driver();
$ok('connected via pgsql (Neon)', $driver === 'pgsql', 'driver=' . $driver);
$host = (string) (getenv('PGHOST') ?: '');
$ok('env points at Neon', str_contains(strtolower($host), 'neon.tech') || str_contains(strtolower((string) getenv('DATABASE_URL')), 'neon.tech'));

$farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
$ok('farm exists', (bool) $farm);
if (!$farm) {
    exit(1);
}
$farmId = (int) $farm['id'];

$byRole = [];
foreach (['admin', 'owner', 'manager', 'agronomist', 'worker', 'accountant'] as $role) {
    $u = db()->first("SELECT * FROM users WHERE role = ? AND status = 'active' ORDER BY id ASC", [$role]);
    $byRole[$role] = $u;
    $ok("role user: $role", (bool) $u, $u ? ('id=' . $u['id']) : 'missing — create via seed/admin');
}

$as = static function (?array $user) use ($farmId): void {
    if (!$user) {
        return;
    }
    Auth::setOverride($user);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['active_farm_id'] = $farmId;
    $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
    Auth::clearCache();
};

// --- 2. Six-role permission matrix (server-side) ---
$matrix = [
    'admin' => ['tasks.create' => true, 'backups.view' => true, 'transactions.view' => true, 'integrations.view' => true],
    'owner' => ['tasks.create' => true, 'backups.view' => false, 'transactions.view' => true, 'integrations.view' => false, 'users.edit' => true],
    'manager' => ['tasks.create' => true, 'stock_movements.create' => true, 'transactions.view' => true, 'payments.create' => false, 'users.view' => false],
    'agronomist' => ['pest_scouting.create' => true, 'tasks.view' => true, 'tasks.create' => true, 'sales_orders.create' => false, 'animals.view' => false],
    'worker' => ['tasks.edit' => true, 'tasks.create' => false, 'stock_movements.create' => false, 'transactions.view' => false, 'plantings.create' => false],
    'accountant' => ['payments.create' => true, 'transactions.create' => true, 'tasks.create' => false, 'plantings.create' => false, 'dispatches.create' => true],
];
foreach ($matrix as $role => $checks) {
    if (!$byRole[$role]) {
        foreach ($checks as $k => $_) {
            $skipped("RBAC $role.$k", 'no user');
        }
        continue;
    }
    $as($byRole[$role]);
    foreach ($checks as $key => $expect) {
        [$mod, $act] = explode('.', $key, 2);
        $got = Auth::can($mod, $act);
        $ok("RBAC $role can $key", $got === $expect, $got ? 'allowed' : 'denied');
    }
}

// Ensure membership for ops users
foreach (['manager', 'owner', 'worker', 'agronomist', 'accountant'] as $role) {
    $u = $byRole[$role] ?? null;
    if (!$u) {
        continue;
    }
    $exists = db()->first('SELECT id FROM user_farms WHERE user_id = ? AND farm_id = ?', [$u['id'], $farmId]);
    if (!$exists) {
        $push('user_farms', (int) db()->insert('user_farms', [
            'user_id' => (int) $u['id'],
            'farm_id' => $farmId,
            'is_primary' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]));
    }
}

$manager = $byRole['manager'];
$worker = $byRole['worker'];
$agronomist = $byRole['agronomist'];
$accountant = $byRole['accountant'];
$owner = $byRole['owner'];
if (!$manager) {
    echo "FATAL: manager required for workflow tests\n";
    exit(1);
}
$as($manager);

// Worker employee link
$empId = 0;
if ($worker) {
    $emp = db()->first('SELECT * FROM employees WHERE user_id = ? AND farm_id = ?', [$worker['id'], $farmId]);
    if (!$emp) {
        $empId = (int) db()->insert('employees', [
            'farm_id' => $farmId,
            'user_id' => (int) $worker['id'],
            'name' => $worker['name'] ?: 'P3 Worker',
            'role_title' => 'Field worker',
            'status' => 'active',
            'daily_rate' => 120,
        ]);
        $push('employees', $empId);
    } else {
        $empId = (int) $emp['id'];
    }
    $ok('employee linked to worker login', $empId > 0 && (int) (db()->find('employees', $empId)['user_id'] ?? 0) === (int) $worker['id']);
} else {
    $skipped('employee linked to worker login', 'no worker user');
}

// --- 3a. Field → planting → crop activity ---
$fieldId = (int) db()->insert('fields', [
    'farm_id' => $farmId,
    'name' => $tag . '_field',
    'size_hectares' => 2.5,
    'status' => 'active',
]);
$push('fields', $fieldId);
$ok('CRUD field create', $fieldId > 0);

$varietyId = (int) db()->insert('crop_varieties', [
    'farm_id' => $farmId,
    'name' => $tag . '_maize',
    'variety' => 'SC627',
    'category' => 'cereal',
    'growing_days' => 120,
    'status' => 'active',
]);
$push('crop_varieties', $varietyId);

$plantingId = (int) db()->insert('plantings', [
    'farm_id' => $farmId,
    'field_id' => $fieldId,
    'crop_variety_id' => $varietyId,
    'planting_date' => date('Y-m-d'),
    'status' => 'growing',
    'growth_stage' => 'vegetative',
    'season' => '2026',
]);
$push('plantings', $plantingId);
$ok('crop planning planting saved', (bool) db()->find('plantings', $plantingId));

$growthId = (int) db()->insert('crop_growth', [
    'farm_id' => $farmId,
    'planting_id' => $plantingId,
    'record_date' => date('Y-m-d'),
    'growth_stage' => 'tasseling',
    'health_status' => 'healthy',
]);
$push('crop_growth', $growthId);
OperationHooks::afterSave(module_def('crop_growth'), db()->find('crop_growth', $growthId), $growthId, true, null);
$planting = db()->find('plantings', $plantingId);
$ok('crop activity syncs planting stage', ($planting['growth_stage'] ?? '') === 'tasseling');

// --- 3b. Pest → notify → task → worker ---
$scoutId = (int) db()->insert('pest_scouting', [
    'farm_id' => $farmId,
    'field_id' => $fieldId,
    'planting_id' => $plantingId,
    'scout_date' => date('Y-m-d'),
    'severity' => 'high',
    'notes' => $tag . ' armyworm',
    'status' => 'reported',
]);
$push('pest_scouting', $scoutId);
OperationHooks::afterSave(module_def('pest_scouting'), db()->find('pest_scouting', $scoutId), $scoutId, true, null);
$draftTask = db()->first(
    "SELECT id FROM tasks WHERE related_module = 'pest_scouting' AND related_id = ? AND title LIKE 'Follow up:%'",
    [$scoutId]
);
$ok('high severity scouting creates one draft task', (bool) $draftTask);
if ($draftTask) {
    $push('tasks', (int) $draftTask['id']);
    WorkflowService::ensureScoutTask(db()->find('pest_scouting', $scoutId), $scoutId);
    $draftCount = (int) db()->value(
        "SELECT COUNT(*) FROM tasks WHERE related_module = 'pest_scouting' AND related_id = ? AND title LIKE 'Follow up:%'",
        [$scoutId]
    );
    $ok('scouting follow-up is not duplicated', $draftCount === 1);
}
$scoutNotif = db()->first(
    "SELECT id FROM notifications WHERE farm_id = ? AND category = 'agronomy' AND message LIKE ? ORDER BY id DESC",
    [$farmId, '%' . $tag . '%']
);
$ok('scouting notifies managers/owners', (bool) $scoutNotif);

$taskId = 0;
if ($empId && $draftTask) {
    $taskId = (int) $draftTask['id'];
    $beforeAssign = db()->find('tasks', $taskId);
    db()->update('tasks', [
        'title' => $tag . ' spray field',
        'description' => 'From scouting',
        'assigned_to' => $empId,
        'field_id' => $fieldId,
        'due_date' => date('Y-m-d', strtotime('+2 days')),
        'priority' => 'high',
        'status' => 'pending',
    ], $taskId);
    OperationHooks::afterSave(module_def('tasks'), db()->find('tasks', $taskId), $taskId, false, $beforeAssign);
    $assigned = db()->find('tasks', $taskId);
    $followUps = (int) db()->value(
        "SELECT COUNT(*) FROM tasks WHERE related_module = 'pest_scouting' AND related_id = ?",
        [$scoutId]
    );
    $ok('task assignment persisted', (int) ($assigned['assigned_to'] ?? 0) === (int) $empId);
    $ok('assignment reuses the scouting follow-up', $followUps === 1);

    if ($worker) {
        $as($worker);
        $taskRow = db()->find('tasks', $taskId);
        $ok('worker TaskAccess after assign', class_exists('TaskAccess') && TaskAccess::workerCanAccessTask($taskRow));
        db()->update('tasks', ['status' => 'completed'], $taskId);
        OperationHooks::afterSave(module_def('tasks'), db()->find('tasks', $taskId), $taskId, false, $taskRow);
        $doneNotif = db()->first(
            "SELECT id FROM notifications WHERE title LIKE ? AND category = 'tasks' ORDER BY id DESC",
            ['%' . $tag . '%']
        );
        $ok('completion notification saved', (bool) $doneNotif);
        $as($manager);
    }
} else {
    $skipped('task assignment/worker complete', 'no worker employee');
}

// --- 3c. Stock receipt → usage ---
$itemId = (int) db()->insert('inventory_items', [
    'farm_id' => $farmId,
    'name' => $tag . '_chem',
    'sku' => $tag,
    'category' => 'chemical',
    'unit' => 'L',
    'quantity' => 0,
    'reorder_level' => 5,
    'status' => 'out',
]);
$push('inventory_items', $itemId);
$inPayload = [
    'farm_id' => $farmId,
    'inventory_item_id' => $itemId,
    'movement_type' => 'in',
    'quantity' => 40,
    'movement_date' => date('Y-m-d'),
    'reference' => $tag . '_recv',
    'notes' => 'p3 receive',
    'created_by' => (int) $manager['id'],
];
$moveId = (int) db()->insert('stock_movements', $inPayload);
$push('stock_movements', $moveId);
OperationHooks::afterSave(module_def('stock_movements'), $inPayload, $moveId, true, null);
$ok('stock receipt → qty 40', (float) db()->find('inventory_items', $itemId)['quantity'] === 40.0);

$usage = [
    'farm_id' => $farmId,
    'planting_id' => $plantingId,
    'product_name' => $tag . ' spray',
    'inventory_item_id' => $itemId,
    'quantity' => 5,
    'type' => 'foliar',
    'application_date' => date('Y-m-d'),
    'unit' => 'L',
];
$ok('usage validate ok', OperationHooks::validateInventoryUsage('fertilizer_applications', $usage) === null);
$appId = (int) db()->insert('fertilizer_applications', $usage);
$push('fertilizer_applications', $appId);
OperationHooks::afterSave(module_def('fertilizer_applications'), $usage, $appId, true, null);
$ok('stock usage → qty 35 once', (float) db()->find('inventory_items', $itemId)['quantity'] === 35.0);

// --- 3d. Harvest → storage → SO → dispatch → invoice → payment → finance ---
$harvestId = (int) db()->insert('harvests', [
    'farm_id' => $farmId,
    'planting_id' => $plantingId,
    'field_id' => $fieldId,
    'harvest_date' => date('Y-m-d'),
    'quantity' => 800,
    'losses' => 50,
    'unit' => 'kg',
    'grade' => 'A',
    'status' => 'completed',
    'quality_notes' => $tag,
]);
$push('harvests', $harvestId);
OperationHooks::afterSave(module_def('harvests'), db()->find('harvests', $harvestId), $harvestId, true, null);
$lot = db()->first('SELECT * FROM storage_lots WHERE harvest_id = ?', [$harvestId]);
$ok('harvest → storage lot', (bool) $lot && (float) $lot['quantity'] === 750.0);
$lotId = (int) ($lot['id'] ?? 0);
$push('storage_lots', $lotId);

$custId = (int) (db()->first('SELECT id FROM customers WHERE farm_id = ?', [$farmId])['id'] ?? 0);
if (!$custId) {
    $custId = (int) db()->insert('customers', [
        'farm_id' => $farmId,
        'name' => $tag . ' buyer',
        'phone' => '0977000111',
        'status' => 'active',
    ]);
    $push('customers', $custId);
}

$soPayload = [
    'farm_id' => $farmId,
    'customer_id' => $custId,
    'order_number' => 'SO-' . $tag,
    'order_date' => date('Y-m-d'),
    'crop_name' => $tag . '_maize',
    'quantity' => 200,
    'unit_price' => 4.5,
    'total_amount' => 900,
    'storage_lot_id' => $lotId,
    'status' => 'confirmed',
];
$ok('SO qty within available', OperationHooks::validateSalesOrder($soPayload) === null);
$soId = (int) db()->insert('sales_orders', $soPayload);
$push('sales_orders', $soId);
OperationHooks::afterSave(module_def('sales_orders'), $soPayload, $soId, true, null);
$inv = db()->first('SELECT * FROM invoices WHERE sales_order_id = ?', [$soId]);
$ok('auto invoice from SO', (bool) $inv && (float) $inv['amount'] === 900.0);
$invId = (int) ($inv['id'] ?? 0);
$push('invoices', $invId);
$ok('SO does not deduct storage', (float) db()->find('storage_lots', $lotId)['quantity'] === 750.0);

$disp = [
    'farm_id' => $farmId,
    'storage_lot_id' => $lotId,
    'sales_order_id' => $soId,
    'customer_id' => $custId,
    'dispatch_date' => date('Y-m-d'),
    'quantity' => 200,
    'reference' => $tag . '_d1',
    'status' => 'dispatched',
];
$dispId = (int) db()->insert('dispatches', $disp);
$push('dispatches', $dispId);
OperationHooks::afterSave(module_def('dispatches'), $disp, $dispId, true, null);
$ok('dispatch deducts once → 550', (float) db()->find('storage_lots', $lotId)['quantity'] === 550.0);

if ($accountant) {
    $as($accountant);
}
$pay = [
    'farm_id' => $farmId,
    'invoice_id' => $invId,
    'customer_id' => $custId,
    'payment_date' => date('Y-m-d'),
    'amount' => 900,
    'method' => 'mobile_money',
    'reference' => $tag . '_pay',
];
$ok('payment validate', OperationHooks::validatePayment($pay) === null);
$payId = (int) db()->insert('payments', $pay);
$push('payments', $payId);
OperationHooks::afterSave(module_def('payments'), $pay, $payId, true, null);
$ok('invoice marked paid', (db()->find('invoices', $invId)['status'] ?? '') === 'paid');
$txn = db()->first('SELECT * FROM transactions WHERE reference = ?', ['payments#' . $payId]);
$ok('finance income from payment once', (bool) $txn && (float) $txn['amount'] === 900.0);
$push('transactions', (int) ($txn['id'] ?? 0));
OperationHooks::afterSave(module_def('payments'), $pay, $payId, true, null);
$ok('no double income', count(db()->select('SELECT id FROM transactions WHERE reference = ?', ['payments#' . $payId])) === 1);
$as($manager);

// --- 3e. Equipment maintenance + cost ---
$eqId = (int) db()->insert('equipment', [
    'farm_id' => $farmId,
    'name' => $tag . '_tractor',
    'category' => 'tractor',
    'status' => 'operational',
    'operating_hours' => 100,
    'next_service' => date('Y-m-d', strtotime('+7 days')),
]);
$push('equipment', $eqId);
$logPayload = [
    'farm_id' => $farmId,
    'equipment_id' => $eqId,
    'log_type' => 'maintenance',
    'log_date' => date('Y-m-d'),
    'cost' => 350,
    'description' => $tag . ' service',
    'hours' => 2,
];
$logId = (int) db()->insert('equipment_logs', $logPayload);
$push('equipment_logs', $logId);
OperationHooks::afterSave(module_def('equipment_logs'), $logPayload, $logId, true, null);
$eqTxn = db()->first('SELECT * FROM transactions WHERE reference = ?', ['equipment_logs#' . $logId]);
$ok('equipment cost → expense txn', (bool) $eqTxn && (float) $eqTxn['amount'] === 350.0);
$push('transactions', (int) ($eqTxn['id'] ?? 0));

// Hours roll-up only for log_type hours
$hoursLog = [
    'farm_id' => $farmId,
    'equipment_id' => $eqId,
    'log_type' => 'hours',
    'log_date' => date('Y-m-d'),
    'hours' => 3,
    'cost' => 0,
];
$hLogId = (int) db()->insert('equipment_logs', $hoursLog);
$push('equipment_logs', $hLogId);
OperationHooks::afterSave(module_def('equipment_logs'), $hoursLog, $hLogId, true, null);
$ok('equipment hours roll-up', (float) db()->find('equipment', $eqId)['operating_hours'] === 103.0);

// --- 4. Dashboard / reports from real data ---
$as($manager);
$taskCount = (int) db()->value('SELECT COUNT(*) FROM tasks WHERE farm_id = ? AND status != ?', [$farmId, 'cancelled']);
$ok('dashboard task count queryable', $taskCount >= 0);

if (class_exists('ReportService') && Auth::can('reports', 'view')) {
    $rep = ReportService::build('sales', []);
    $ok('sales report builds from DB', is_array($rep) && !empty($rep['headers']));
    $fin = ReportService::build('financial', []);
    $ok('financial report builds', is_array($fin));
} else {
    $skipped('reports', 'ReportService or permission unavailable');
}

// Admin monitoring without needing farm write
if ($byRole['admin']) {
    $as($byRole['admin']);
    $ok('admin wildcard modules', Auth::can('settings', 'view') && Auth::can('backups', 'view') && Auth::can('audit_logs', 'view'));
    // Admin canAccessFarm all
    $ok('admin canAccessFarm', Auth::canAccessFarm($farmId));
}

// Cross-farm isolation
$otherFarmId = (int) db()->insert('farms', [
    'name' => $tag . '_other',
    'location' => 'test',
    'status' => 'active',
]);
$push('farms', $otherFarmId);
$as($manager);
$ok('manager blocked from other farm', !Auth::canAccessFarm($otherFarmId));
$xfItem = (int) db()->insert('inventory_items', [
    'farm_id' => $otherFarmId,
    'name' => $tag . '_xf',
    'sku' => $tag . 'x',
    'category' => 'other',
    'unit' => 'kg',
    'quantity' => 10,
    'reorder_level' => 1,
    'status' => 'in_stock',
]);
$push('inventory_items', $xfItem);
$ok(
    'cross-farm usage blocked',
    is_string(OperationHooks::validateInventoryUsage('fertilizer_applications', [
        'farm_id' => $farmId,
        'inventory_item_id' => $xfItem,
        'quantity' => 1,
    ]))
);

// Persistence after "sign out / in" simulation
$as(null);
Auth::clearCache();
if (isset($_SESSION['user_id'])) {
    unset($_SESSION['user_id'], $_SESSION['active_farm_id']);
}
$as($manager);
$ok('record still in Neon after session reset', (bool) db()->find('plantings', $plantingId));
$ok('task still in Neon', $taskId === 0 || (bool) db()->find('tasks', $taskId));

// Notification link fix sanity
$ok('inventory notify link uses inventory_items', !str_contains(
    file_get_contents(dirname(__DIR__) . '/backend/services/OperationHooks.php'),
    "module/inventory/view/"
));

// Weather: optional if API key
$ow = getenv('OPENWEATHER_API_KEY') ?: '';
if ($ow !== '' && class_exists('WeatherService')) {
    try {
        $wx = WeatherService::fetchForFarm($farm);
        $ok('weather fetch returns data or structured empty', is_array($wx));
    } catch (Throwable $e) {
        $ok('weather fetch', false, substr($e->getMessage(), 0, 80));
    }
} else {
    $skipped('weather API', 'OPENWEATHER_API_KEY unset or WeatherService missing');
}

// Module CRUD smoke: create+delete soil record
$soilId = (int) db()->insert('soil_records', [
    'farm_id' => $farmId,
    'field_id' => $fieldId,
    'sample_date' => date('Y-m-d'),
    'ph_level' => 6.2,
    'condition_notes' => $tag,
]);
$push('soil_records', $soilId);
$ok('soil_records CRUD', (bool) db()->find('soil_records', $soilId));

// Cleanup
foreach (array_reverse($cleanup) as [$table, $id]) {
    if (!$id) {
        continue;
    }
    try {
        db()->delete($table, $id);
    } catch (Throwable $e) {
        // leave constrained orphans tagged
    }
}

echo PHP_EOL . "failures=$fail skips=$skip" . PHP_EOL;
echo ($fail === 0 ? 'ALL PASSED' : 'HAS FAILURES') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
