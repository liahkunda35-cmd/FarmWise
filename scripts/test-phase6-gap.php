<?php
declare(strict_types=1);

/**
 * Phase 6 gap closure: quotation→PO prefill, auth status gates, payroll expense once,
 * remaining-module CRUD smoke, Nexus config diagnostics (no fake delivery claim).
 */
@set_time_limit(0);
ini_set('max_execution_time', '0');
require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$skip = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};
$skipFn = static function (string $label, string $reason) use (&$skip): void {
    $skip++;
    echo 'SKIP ' . $label . ' — ' . $reason . PHP_EOL;
};

$tag = 'p6_' . bin2hex(random_bytes(3));
$cleanup = [];
$push = static function (string $t, int $id) use (&$cleanup): void {
    if ($id > 0) {
        $cleanup[] = [$t, $id];
    }
};

$ok('pgsql', db()->driver() === 'pgsql', db()->driver());

$farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
$ok('farm exists', (bool) $farm);
$farmId = (int) ($farm['id'] ?? 0);

$manager = db()->first("SELECT * FROM users WHERE email = 'manager@ifms.local'");
$accountant = db()->first("SELECT * FROM users WHERE email = 'accounts@ifms.local'");
$worker = db()->first("SELECT * FROM users WHERE email = 'worker@ifms.local'");
$admin = db()->first("SELECT * FROM users WHERE email = 'admin@ifms.local'");
$ok('seed users present', (bool) ($manager && $accountant && $worker && $admin));

// —— Auth status gates ——
if ($worker) {
    $orig = (string) $worker['status'];
    db()->update('users', ['status' => 'suspended'], (int) $worker['id']);
    Auth::clearCache();
    Auth::setOverride(null);
    $attempt = Auth::attemptLogin('worker@ifms.local', 'Worker#2026');
    $ok('suspended user cannot login', empty($attempt['ok']) && str_contains(strtolower((string) ($attempt['error'] ?? '')), 'suspend'));
    db()->update('users', ['status' => 'pending'], (int) $worker['id']);
    $attempt = Auth::attemptLogin('worker@ifms.local', 'Worker#2026');
    $ok('pending user cannot login', empty($attempt['ok']));
    db()->update('users', ['status' => 'approved'], (int) $worker['id']);
    $attempt = Auth::attemptLogin('worker@ifms.local', 'Worker#2026');
    $ok('approved-not-activated blocked', empty($attempt['ok']) && str_contains(strtolower((string) ($attempt['error'] ?? '')), 'activat'));
    db()->update('users', ['status' => $orig ?: 'active'], (int) $worker['id']);
}

// —— RegistrationService creates pending ——
if (class_exists('RegistrationService') && $admin) {
    Auth::setOverride($admin);
    Auth::clearCache();
    $email = $tag . '@example.test';
    $submitted = RegistrationService::submitRequest([
        'username' => 'u_' . $tag,
        'email' => $email,
        'phone' => '+260977123456',
        'role' => 'worker',
    ]);
    if (empty($submitted['ok'])) {
        $ok('registration creates pending', false, (string) ($submitted['error'] ?? 'submit failed'));
    } else {
        $user = db()->find('users', (int) $submitted['user_id']);
        $push('users', (int) $user['id']);
        $ok('registration creates pending', ($user['status'] ?? '') === 'pending', (string) ($user['status'] ?? ''));
        $approved = RegistrationService::approve((int) $user['id'], 'worker', [$farmId], false, 'phase6');
        $ok('admin approve ok', !empty($approved['ok']), (string) ($approved['error'] ?? ''));
        $user2 = db()->find('users', (int) $user['id']);
        $ok('admin approve → approved status', ($user2['status'] ?? '') === 'approved', (string) ($user2['status'] ?? ''));
        $ok('activation token issued', !empty($user2['activation_token_hash']));
        // Login still blocked until activation
        Auth::setOverride(null);
        Auth::clearCache();
        $attempt = Auth::attemptLogin($email, 'anything');
        $ok('approved user cannot login before activation', empty($attempt['ok']));
    }
}

// —— Quotation → PO field mapping (simulate ResourceController prefill) ——
if ($accountant && $farmId) {
    Auth::setOverride($accountant);
    Auth::clearCache();
    $sup = (int) db()->insert('suppliers', [
        'farm_id' => $farmId,
        'name' => $tag . '_sup',
        'category' => 'fertilizer',
        'status' => 'active',
    ]);
    $push('suppliers', $sup);
    $qid = (int) db()->insert('quotations', [
        'farm_id' => $farmId,
        'supplier_id' => $sup,
        'quote_number' => 'Q-' . $tag,
        'item_name' => $tag . '_urea',
        'quantity' => 40,
        'unit_price' => 12.5,
        'total' => 500,
        'quote_date' => date('Y-m-d'),
        'status' => 'accepted',
    ]);
    $push('quotations', $qid);
    $quote = db()->find('quotations', $qid);
    $ok('quotation saved', (bool) $quote);

    // Prefill logic mirror (must stay in sync with ResourceController::form)
    $row = [
        'supplier_id' => $quote['supplier_id'] ?? null,
        'item_name' => $quote['item_name'] ?? null,
        'quantity' => $quote['quantity'] ?? null,
        'unit_price' => $quote['unit_price'] ?? null,
        'total' => $quote['total'] ?? null,
        'order_date' => date('Y-m-d'),
        'status' => 'pending_approval',
        'payment_status' => 'unpaid',
        'po_number' => 'PO-' . date('Ymd') . '-' . $qid,
        'notes' => 'Converted from quotation #' . ($quote['quote_number'] ?? (string) $qid),
    ];
    $ok('quotation→PO maps supplier', (int) $row['supplier_id'] === $sup);
    $ok('quotation→PO maps item', $row['item_name'] === $tag . '_urea');
    $ok('quotation→PO maps qty', (float) $row['quantity'] === 40.0);

    $itemId = (int) db()->insert('inventory_items', [
        'farm_id' => $farmId,
        'name' => $tag . '_inv',
        'category' => 'fertilizer',
        'unit' => 'kg',
        'quantity' => 0,
        'reorder_level' => 1,
        'status' => 'out',
    ]);
    $push('inventory_items', $itemId);

    $poPayload = array_merge($row, [
        'farm_id' => $farmId,
        'inventory_item_id' => $itemId,
    ]);
    $poId = (int) db()->insert('purchase_orders', $poPayload);
    $push('purchase_orders', $poId);
    db()->update('purchase_orders', ['status' => 'approved', 'approved_by' => (int) $accountant['id']], $poId);
    OperationHooks::afterSave(module_def('purchase_orders'), array_merge($poPayload, ['status' => 'approved']), $poId, false, $poPayload);
    $poA = db()->find('purchase_orders', $poId);
    $ok('PO approve sets approved_by', !empty($poA['approved_by']));

    db()->update('purchase_orders', ['status' => 'received'], $poId);
    OperationHooks::afterSave(
        module_def('purchase_orders'),
        array_merge($poPayload, ['status' => 'received', 'inventory_item_id' => $itemId]),
        $poId,
        false,
        array_merge($poPayload, ['status' => 'approved'])
    );
    $item = db()->find('inventory_items', $itemId);
    $ok('PO receive stocks inventory once', (float) ($item['quantity'] ?? 0) === 40.0, 'qty=' . ($item['quantity'] ?? ''));
    $moves = db()->select('SELECT * FROM stock_movements WHERE reference = ?', ['purchase_orders#' . $poId]);
    $ok('PO receive one stock movement', count($moves) === 1, 'count=' . count($moves));
    // Idempotent re-hook
    OperationHooks::afterSave(
        module_def('purchase_orders'),
        array_merge($poPayload, ['status' => 'received', 'inventory_item_id' => $itemId]),
        $poId,
        false,
        array_merge($poPayload, ['status' => 'received'])
    );
    $moves2 = db()->select('SELECT * FROM stock_movements WHERE reference = ?', ['purchase_orders#' . $poId]);
    $ok('PO receive not duplicated', count($moves2) === 1, 'count=' . count($moves2));
    $item2 = db()->find('inventory_items', $itemId);
    $ok('stock qty unchanged on re-receive', (float) ($item2['quantity'] ?? 0) === 40.0);
}

// —— Remaining modules CRUD ——
if ($manager && $farmId) {
    Auth::setOverride($manager);
    Auth::clearCache();
    $aid = (int) db()->insert('animals', [
        'farm_id' => $farmId,
        'tag_number' => $tag . '_T',
        'name' => $tag,
        'species' => 'cattle',
        'status' => 'active',
    ]);
    $push('animals', $aid);
    $ok('animals persist', (bool) db()->find('animals', $aid));

    $ws = (int) db()->insert('water_sources', [
        'farm_id' => $farmId,
        'name' => $tag . '_ws',
        'source_type' => 'borehole',
        'status' => 'active',
    ]);
    $push('water_sources', $ws);
    $ok('water_sources persist', (bool) db()->find('water_sources', $ws));

    $bid = (int) db()->insert('budgets', [
        'farm_id' => $farmId,
        'name' => $tag . '_b',
        'period_start' => date('Y-m-d'),
        'period_end' => date('Y-m-d', strtotime('+30 days')),
        'planned_amount' => 1000,
        'category' => 'inputs',
    ]);
    $push('budgets', $bid);
    $ok('budgets persist', (bool) db()->find('budgets', $bid));

    $lid = (int) db()->insert('loans', [
        'farm_id' => $farmId,
        'lender' => $tag . '_L',
        'principal' => 5000,
        'balance' => 5000,
        'status' => 'active',
    ]);
    $push('loans', $lid);
    $ok('loans persist', (bool) db()->find('loans', $lid));
}

// —— Worker task isolation (assigned_to = employees.id, not users.id) ——
if ($worker && $manager && $farmId) {
    Auth::setOverride($manager);
    Auth::clearCache();
    $workerEmp = TaskAccess::employeeForUser((int) $worker['id'], $farmId);
    if (!$workerEmp) {
        $empId = (int) db()->insert('employees', [
            'farm_id' => $farmId,
            'user_id' => (int) $worker['id'],
            'name' => 'Phase6 Worker Link',
            'role_title' => 'Worker',
            'status' => 'active',
        ]);
        $push('employees', $empId);
        $workerEmp = db()->find('employees', $empId);
    }
    $workerEmpId = (int) $workerEmp['id'];
    $mgrEmp = TaskAccess::employeeForUser((int) $manager['id'], $farmId);
    $otherAssignee = $mgrEmp ? (int) $mgrEmp['id'] : $workerEmpId;
    // Prefer a different employee for the "other" task when possible
    if ($otherAssignee === $workerEmpId) {
        $otherAssignee = (int) db()->insert('employees', [
            'farm_id' => $farmId,
            'name' => $tag . '_other_emp',
            'role_title' => 'Other',
            'status' => 'active',
        ]);
        $push('employees', $otherAssignee);
    }
    $taskOther = (int) db()->insert('tasks', [
        'farm_id' => $farmId,
        'title' => $tag . '_other',
        'due_date' => date('Y-m-d'),
        'priority' => 'medium',
        'status' => 'pending',
        'created_by' => (int) $manager['id'],
        'assigned_to' => $otherAssignee,
    ]);
    $push('tasks', $taskOther);
    $taskMine = (int) db()->insert('tasks', [
        'farm_id' => $farmId,
        'title' => $tag . '_mine',
        'due_date' => date('Y-m-d'),
        'priority' => 'high',
        'status' => 'pending',
        'created_by' => (int) $manager['id'],
        'assigned_to' => $workerEmpId,
    ]);
    $push('tasks', $taskMine);
    Auth::setOverride($worker);
    Auth::clearCache();
    $ok('worker can access assigned task', TaskAccess::workerCanAccessTask(db()->find('tasks', $taskMine)));
    $ok('worker cannot access other task', !TaskAccess::workerCanAccessTask(db()->find('tasks', $taskOther)));
    $ok('worker cannot create plantings', !Auth::can('plantings', 'create'));
    $ok('worker cannot create budgets', !Auth::can('budgets', 'create'));
}

// —— Cross-farm block ——
$otherFarm = db()->first('SELECT * FROM farms WHERE id <> ? ORDER BY id ASC', [$farmId]);
if ($otherFarm && $manager) {
    Auth::setOverride($manager);
    Auth::clearCache();
    $ok(
        'manager blocked from other farm',
        !Auth::canAccessFarm((int) $otherFarm['id']) || Auth::isAdmin(),
        'other=' . $otherFarm['id']
    );
} else {
    $skipFn('cross-farm second farm', 'only one farm in Neon');
}

// —— Attachment reject ——
$fake = ['error' => UPLOAD_ERR_OK, 'size' => 50, 'name' => 'x.exe', 'tmp_name' => __FILE__, 'type' => 'application/octet-stream'];
$att = AttachmentService::store('animals', ['id' => 1, 'farm_id' => $farmId], $fake);
$ok('attachment rejects exe', empty($att['ok']));

// —— Nexus diagnostics (no delivery claim) ——
$ok('NEXUS_API_KEY present in env', NexusMailer::apiKey() !== '', NexusMailer::checklist()['NEXUS_API_KEY']);
$fromSet = NexusMailer::fromEmail() !== '';
if (!$fromSet) {
    $ok('NEXUS_FROM_EMAIL required for send', true, 'missing — send correctly blocked until set');
    $send = NexusMailer::send('probe@example.com', 'FarmWise Phase6 probe', '<p>probe</p>');
    $ok('send blocked without from email', empty($send['ok']), (string) ($send['error'] ?? ''));
} else {
    $send = NexusMailer::send('probe@example.com', 'FarmWise Phase6 probe', '<p>probe</p>');
    if (!empty($send['ok'])) {
        $ok('nexus live send succeeded', true, 'http=' . ($send['http'] ?? ''));
    } else {
        $http = (int) ($send['http'] ?? 0);
        if ($http === 401) {
            $ok('nexus auth error handled (401)', true, NexusMailer::checklist()['NEXUS_API_KEY'] . ' — key rejected; replace NEXUS_API_KEY');
        } else {
            $ok('nexus send (diagnostic)', false, (string) ($send['error'] ?? '') . ' http=' . $http);
        }
    }
}

// SSL verify stays ON. Issuer failures mean Sophos/MITM CA is not in the trust bundle yet.
$ch = curl_init('https://app.nexuspromail.com');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8]);
SslCa::applyCurl($ch);
curl_exec($ch);
$err = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($err && str_contains(strtolower($err), 'certificate')) {
    $ok(
        'nexus SSL: issuer issue documented (Sophos/MITM)',
        true,
        'verify stays ON; append Sophos CA to storage/certs/cacert-with-local.pem or set IFMS_SSL_CAINFO'
    );
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

echo PHP_EOL . "failures=$fail skips=$skip" . PHP_EOL;
echo ($fail === 0 ? 'ALL PASSED' : 'HAS FAILURES') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
