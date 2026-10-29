<?php
declare(strict_types=1);

/**
 * Phase 2: inventory, harvest→storage, sales/dispatch, finance consistency.
 * Additive — creates tagged test rows and cleans them up where possible.
 */
require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$tag = 'p2_' . bin2hex(random_bytes(3));
$cleanup = [];

$farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
$ok('farm exists', (bool) $farm);
if (!$farm) {
    exit(1);
}
$farmId = (int) $farm['id'];

$otherFarm = db()->first('SELECT * FROM farms WHERE id <> ? ORDER BY id ASC', [$farmId]);
$manager = db()->first("SELECT * FROM users WHERE role = 'manager' AND status = 'active' ORDER BY id ASC");
$ok('manager user', (bool) $manager);
if (!$manager) {
    exit(1);
}

Auth::setOverride($manager);
$_SESSION['user_id'] = (int) $manager['id'];
$_SESSION['active_farm_id'] = $farmId;
$_SESSION['session_version'] = (int) ($manager['session_version'] ?? 1);
Auth::clearCache();

// --- 1. Stock received increases balance ---
$itemId = (int) db()->insert('inventory_items', [
    'farm_id' => $farmId,
    'name' => $tag . '_fert',
    'sku' => $tag,
    'category' => 'fertilizer',
    'unit' => 'kg',
    'quantity' => 0,
    'reorder_level' => 10,
    'status' => 'out',
]);
$cleanup[] = ['inventory_items', $itemId];

$defMove = module_def('stock_movements');
$inPayload = [
    'farm_id' => $farmId,
    'inventory_item_id' => $itemId,
    'movement_type' => 'in',
    'quantity' => 100,
    'movement_date' => date('Y-m-d'),
    'reference' => $tag . '_in1',
    'notes' => 'phase2 test receive',
    'created_by' => (int) $manager['id'],
];
$ok('validate stock in (no block)', OperationHooks::validateStockOut($inPayload) === null);
$moveInId = (int) db()->insert('stock_movements', $inPayload);
$cleanup[] = ['stock_movements', $moveInId];
OperationHooks::afterSave($defMove, $inPayload, $moveInId, true, null);
$item = db()->find('inventory_items', $itemId);
$ok('1 stock received → balance 100', (float) $item['quantity'] === 100.0, 'qty=' . $item['quantity']);

// --- 2. Valid usage reduces exactly once ---
$usagePayload = [
    'farm_id' => $farmId,
    'inventory_item_id' => $itemId,
    'quantity' => 25,
    'application_date' => date('Y-m-d'),
    'product_name' => $tag . ' fert',
    'type' => 'fertilizer',
    'planting_id' => null,
];
// Need a planting for fertilizer FK? Check schema
$planting = db()->first('SELECT id FROM plantings WHERE farm_id = ? ORDER BY id ASC', [$farmId]);
if (!$planting) {
    $field = db()->first('SELECT id FROM fields WHERE farm_id = ? ORDER BY id ASC', [$farmId]);
    $crop = db()->first('SELECT id FROM crop_varieties WHERE farm_id = ? ORDER BY id ASC', [$farmId]);
    if (!$crop) {
        $cropId = (int) db()->insert('crop_varieties', [
            'farm_id' => $farmId,
            'name' => $tag . ' maize',
            'variety' => 'test',
            'crop_type' => 'cereal',
        ]);
        $cleanup[] = ['crop_varieties', $cropId];
    } else {
        $cropId = (int) $crop['id'];
    }
    if (!$field) {
        $fieldId = (int) db()->insert('fields', [
            'farm_id' => $farmId,
            'name' => $tag . ' field',
            'size_ha' => 1,
            'status' => 'active',
        ]);
        $cleanup[] = ['fields', $fieldId];
    } else {
        $fieldId = (int) $field['id'];
    }
    $plantingId = (int) db()->insert('plantings', [
        'farm_id' => $farmId,
        'field_id' => $fieldId,
        'crop_variety_id' => $cropId,
        'planting_date' => date('Y-m-d'),
        'status' => 'growing',
        'growth_stage' => 'vegetative',
    ]);
    $cleanup[] = ['plantings', $plantingId];
} else {
    $plantingId = (int) $planting['id'];
}
$usagePayload['planting_id'] = $plantingId;

$ok('2a usage validate ok', OperationHooks::validateInventoryUsage('fertilizer_applications', $usagePayload) === null);
$appId = (int) db()->insert('fertilizer_applications', array_merge($usagePayload, [
    'field_id' => null,
    'unit' => 'kg',
    'method' => 'broadcast',
    'cost' => 0,
]));
$cleanup[] = ['fertilizer_applications', $appId];
$defFert = module_def('fertilizer_applications');
OperationHooks::afterSave($defFert, $usagePayload, $appId, true, null);
$item = db()->find('inventory_items', $itemId);
$ok('2 valid usage → balance 75', (float) $item['quantity'] === 75.0, 'qty=' . $item['quantity']);
$moves = db()->select('SELECT id FROM stock_movements WHERE reference = ?', ['fertilizer_applications#' . $appId]);
$ok('2 usage movement once', count($moves) === 1);

// Re-run afterSave (retry hooks) — no second deduction
OperationHooks::afterSave($defFert, $usagePayload, $appId, true, null);
$item = db()->find('inventory_items', $itemId);
$ok('4 retry afterSave no duplicate deduct', (float) $item['quantity'] === 75.0, 'qty=' . $item['quantity']);
$moves = db()->select('SELECT id FROM stock_movements WHERE reference = ?', ['fertilizer_applications#' . $appId]);
$ok('4 still one movement', count($moves) === 1);

// Duplicate reference blocked
$dupMsg = OperationHooks::validateStockOut([
    'farm_id' => $farmId,
    'inventory_item_id' => $itemId,
    'movement_type' => 'out',
    'quantity' => 1,
    'reference' => $tag . '_in1',
]);
$ok('4 duplicate reference rejected', is_string($dupMsg) && str_contains($dupMsg, 'already exists'));

// --- 3. Insufficient stock rejected ---
$big = [
    'farm_id' => $farmId,
    'inventory_item_id' => $itemId,
    'quantity' => 9999,
    'application_date' => date('Y-m-d'),
];
$msg = OperationHooks::validateInventoryUsage('fertilizer_applications', $big);
$ok('3 insufficient usage rejected', is_string($msg) && str_contains(strtolower($msg), 'insufficient'));
$msgOut = OperationHooks::validateStockOut([
    'farm_id' => $farmId,
    'inventory_item_id' => $itemId,
    'movement_type' => 'out',
    'quantity' => 9999,
]);
$ok('3 insufficient stock-out rejected', is_string($msgOut) && str_contains(strtolower($msgOut), 'insufficient'));

// --- 5. Harvest → storage without duplicate ---
$harvestId = (int) db()->insert('harvests', [
    'farm_id' => $farmId,
    'planting_id' => $plantingId,
    'harvest_date' => date('Y-m-d'),
    'quantity' => 500,
    'losses' => 20,
    'unit' => 'kg',
    'grade' => 'A',
    'status' => 'completed',
    'quality_notes' => $tag,
]);
$cleanup[] = ['harvests', $harvestId];
$defHarvest = module_def('harvests');
$hPayload = db()->find('harvests', $harvestId);
OperationHooks::afterSave($defHarvest, $hPayload, $harvestId, true, null);
$lots = db()->select('SELECT * FROM storage_lots WHERE harvest_id = ?', [$harvestId]);
$ok('5 harvest created one storage lot', count($lots) === 1, 'count=' . count($lots));
$lotId = (int) $lots[0]['id'];
$cleanup[] = ['storage_lots', $lotId];
$ok('5 lot qty = harvest - losses (480)', (float) $lots[0]['quantity'] === 480.0, 'qty=' . $lots[0]['quantity']);

// Second afterSave (status transition path) must not create another lot
OperationHooks::afterSave($defHarvest, array_merge($hPayload, ['status' => 'stored']), $harvestId, false, $hPayload);
$lots2 = db()->select('SELECT id FROM storage_lots WHERE harvest_id = ?', [$harvestId]);
$ok('5 no duplicate lot on re-store', count($lots2) === 1);
$ok('5 validateStorageLot blocks dup harvest', is_string(OperationHooks::validateStorageLot([
    'farm_id' => $farmId,
    'harvest_id' => $harvestId,
    'quantity' => 10,
    'spoilage' => 0,
])));

// --- 6. Storage losses reduce available ---
db()->update('storage_lots', ['spoilage' => 30], $lotId);
$lot = db()->find('storage_lots', $lotId);
$ok('6 available after spoilage = 450', OperationHooks::lotAvailable($lot) === 450.0, 'avail=' . OperationHooks::lotAvailable($lot));

// --- 7. Sales cannot exceed available ---
$soMsg = OperationHooks::validateSalesOrder([
    'farm_id' => $farmId,
    'storage_lot_id' => $lotId,
    'quantity' => 9999,
    'status' => 'pending',
]);
$ok('7 oversell order rejected', is_string($soMsg) && str_contains(strtolower($soMsg), 'exceed'));

$customer = db()->first('SELECT id FROM customers WHERE farm_id = ? ORDER BY id ASC', [$farmId]);
if (!$customer) {
    $custId = (int) db()->insert('customers', [
        'farm_id' => $farmId,
        'name' => $tag . ' buyer',
        'phone' => '0977123456',
        'status' => 'active',
    ]);
    $cleanup[] = ['customers', $custId];
} else {
    $custId = (int) $customer['id'];
}

$soPayload = [
    'farm_id' => $farmId,
    'customer_id' => $custId,
    'order_number' => 'SO-' . $tag,
    'order_date' => date('Y-m-d'),
    'crop_name' => $tag . ' produce',
    'quantity' => 100,
    'unit_price' => 5,
    'total_amount' => 500,
    'storage_lot_id' => $lotId,
    'status' => 'confirmed',
];
$ok('7 valid SO qty ok', OperationHooks::validateSalesOrder($soPayload) === null);
$soId = (int) db()->insert('sales_orders', $soPayload);
$cleanup[] = ['sales_orders', $soId];
$defSo = module_def('sales_orders');
OperationHooks::afterSave($defSo, $soPayload, $soId, true, null);
$lotAfterSo = db()->find('storage_lots', $lotId);
$ok('7 SO does not deduct storage', (float) $lotAfterSo['quantity'] === 480.0 && (float) $lotAfterSo['spoilage'] === 30.0);
$inv = db()->first('SELECT * FROM invoices WHERE sales_order_id = ?', [$soId]);
$ok('9 invoice created for SO', (bool) $inv);
if ($inv) {
    $cleanup[] = ['invoices', (int) $inv['id']];
}

// --- 8. Dispatch updates stock exactly once ---
$dispPayload = [
    'farm_id' => $farmId,
    'storage_lot_id' => $lotId,
    'sales_order_id' => $soId,
    'customer_id' => $custId,
    'dispatch_date' => date('Y-m-d'),
    'quantity' => 100,
    'destination' => 'market',
    'reference' => $tag . '_disp1',
    'status' => 'dispatched',
];
$ok('8 dispatch validate ok', OperationHooks::validateDispatch($dispPayload) === null);
$dispId = (int) db()->insert('dispatches', $dispPayload);
$cleanup[] = ['dispatches', $dispId];
OperationHooks::afterSave(module_def('dispatches'), $dispPayload, $dispId, true, null);
$lot = db()->find('storage_lots', $lotId);
$ok('8 dispatch qty reduced to 380', (float) $lot['quantity'] === 380.0, 'qty=' . $lot['quantity']);
$ok('8 available = 350', OperationHooks::lotAvailable($lot) === 350.0);

// Second dispatch over available rejected
$over = OperationHooks::validateDispatch([
    'farm_id' => $farmId,
    'storage_lot_id' => $lotId,
    'quantity' => 9999,
    'reference' => $tag . '_disp2',
]);
$ok('7/8 oversell dispatch rejected', is_string($over));

// Duplicate dispatch reference
$ok('8 dup dispatch ref rejected', is_string(OperationHooks::validateDispatch([
    'farm_id' => $farmId,
    'storage_lot_id' => $lotId,
    'quantity' => 1,
    'reference' => $tag . '_disp1',
])));

// Cancelled SO cannot dispatch
db()->update('sales_orders', ['status' => 'cancelled'], $soId);
$ok('10 cancelled SO blocks dispatch', is_string(OperationHooks::validateDispatch([
    'farm_id' => $farmId,
    'storage_lot_id' => $lotId,
    'sales_order_id' => $soId,
    'quantity' => 1,
    'reference' => $tag . '_disp_x',
])));
db()->update('sales_orders', ['status' => 'confirmed'], $soId);

// --- 9. Payments / finance consistency ---
if ($inv) {
    $payPayload = [
        'farm_id' => $farmId,
        'invoice_id' => (int) $inv['id'],
        'customer_id' => $custId,
        'payment_date' => date('Y-m-d'),
        'amount' => 200,
        'method' => 'cash',
        'reference' => $tag . '_pay1',
    ];
    $ok('9 payment within balance', OperationHooks::validatePayment($payPayload) === null);
    $payId = (int) db()->insert('payments', $payPayload);
    $cleanup[] = ['payments', $payId];
    OperationHooks::afterSave(module_def('payments'), $payPayload, $payId, true, null);
    $inv2 = db()->find('invoices', $inv['id']);
    $ok('9 invoice partial after payment', ($inv2['status'] ?? '') === 'partial', 'status=' . ($inv2['status'] ?? ''));
    $txn = db()->first('SELECT * FROM transactions WHERE reference = ?', ['payments#' . $payId]);
    $ok('9 income txn once', (bool) $txn && (float) $txn['amount'] === 200.0);
    $cleanup[] = ['transactions', (int) ($txn['id'] ?? 0)];
    OperationHooks::afterSave(module_def('payments'), $payPayload, $payId, true, null);
    $txns = db()->select('SELECT id FROM transactions WHERE reference = ?', ['payments#' . $payId]);
    $ok('9 no double income on retry', count($txns) === 1);
    $ok('9 overpay rejected', is_string(OperationHooks::validatePayment([
        'farm_id' => $farmId,
        'invoice_id' => (int) $inv['id'],
        'amount' => 99999,
    ])));
}

// --- 11. Cross-farm leakage ---
$otherId = $otherFarm ? (int) $otherFarm['id'] : 0;
$tempOtherFarm = false;
if ($otherId <= 0) {
    $otherId = (int) db()->insert('farms', [
        'name' => $tag . '_other_farm',
        'location' => 'test',
        'status' => 'active',
        'owner_id' => null,
    ]);
    $tempOtherFarm = true;
    $cleanup[] = ['farms', $otherId];
}
$xfItemId = (int) db()->insert('inventory_items', [
    'farm_id' => $otherId,
    'name' => $tag . '_xf_item',
    'sku' => $tag . '_xf',
    'category' => 'fertilizer',
    'unit' => 'kg',
    'quantity' => 50,
    'reorder_level' => 5,
    'status' => 'in_stock',
]);
$cleanup[] = ['inventory_items', $xfItemId];
$xmsg = OperationHooks::validateInventoryUsage('fertilizer_applications', [
    'farm_id' => $farmId,
    'inventory_item_id' => $xfItemId,
    'quantity' => 1,
]);
$ok('11 cross-farm inventory usage blocked', is_string($xmsg) && str_contains(strtolower($xmsg), 'farm'), (string) $xmsg);

$whOther = db()->first("SELECT id FROM warehouses WHERE farm_id = ?", [$otherId]);
if (!$whOther) {
    $whOtherId = (int) db()->insert('warehouses', [
        'farm_id' => $otherId,
        'name' => $tag . ' wh',
        'type' => 'warehouse',
        'status' => 'active',
    ]);
    $cleanup[] = ['warehouses', $whOtherId];
} else {
    $whOtherId = (int) $whOther['id'];
}
$xfLotId = (int) db()->insert('storage_lots', [
    'farm_id' => $otherId,
    'warehouse_id' => $whOtherId,
    'produce_name' => $tag . ' xf lot',
    'quantity' => 100,
    'spoilage' => 0,
    'stored_date' => date('Y-m-d'),
    'status' => 'stored',
]);
$cleanup[] = ['storage_lots', $xfLotId];
$ok('11 cross-farm dispatch blocked', is_string(OperationHooks::validateDispatch([
    'farm_id' => $farmId,
    'storage_lot_id' => $xfLotId,
    'quantity' => 1,
    'reference' => $tag . '_xf',
])));
$ok('11 cross-farm sales order blocked', is_string(OperationHooks::validateSalesOrder([
    'farm_id' => $farmId,
    'storage_lot_id' => $xfLotId,
    'quantity' => 1,
    'status' => 'pending',
])));
unset($tempOtherFarm);

// Permissions: worker cannot create stock movements
$worker = db()->first("SELECT * FROM users WHERE role = 'worker' AND status = 'active' ORDER BY id ASC");
if ($worker) {
    Auth::setOverride($worker);
    Auth::clearCache();
    $ok('11 worker cannot create stock_movements', !Auth::can('stock_movements', 'create'));
    $ok('11 worker cannot create sales_orders', !Auth::can('sales_orders', 'create'));
    Auth::setOverride($manager);
    Auth::clearCache();
}

// Accountant can see transactions
$acct = db()->first("SELECT * FROM users WHERE role = 'accountant' AND status = 'active' ORDER BY id ASC");
if ($acct) {
    Auth::setOverride($acct);
    Auth::clearCache();
    $ok('finance accountant can transactions', Auth::can('transactions', 'view'));
    Auth::setOverride($manager);
    Auth::clearCache();
} else {
    $ok('finance accountant can transactions', true, 'no accountant user — skipped');
}

// Cleanup (reverse order; ignore FK failures)
foreach (array_reverse($cleanup) as $pair) {
    [$table, $id] = $pair;
    if (!$id) {
        continue;
    }
    try {
        db()->delete($table, $id);
    } catch (Throwable $e) {
        // leave orphan tagged rows if constrained
    }
}

echo PHP_EOL . ($fail === 0 ? 'ALL PASSED' : "FAILURES: $fail") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
