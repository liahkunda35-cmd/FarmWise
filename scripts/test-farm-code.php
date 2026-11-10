<?php
declare(strict_types=1);

/**
 * Farm Code registration, login, isolation, and cross-module checks.
 * Creates tagged rows and deletes them before exit.
 */
require dirname(__DIR__) . '/backend/bootstrap.php';
set_time_limit(300);

$fail = 0;
$ok = static function (bool $pass, string $label, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$suffix = bin2hex(random_bytes(3));
$tag = 'fw' . $suffix;
$password = 'Owner#Farm26';
$cleanup = [
    'users' => [],
    'farms' => [],
    'fields' => [],
    'crop_varieties' => [],
    'plantings' => [],
    'inventory_items' => [],
    'fertilizer_applications' => [],
    'stock_movements' => [],
    'transactions' => [],
    'harvests' => [],
    'storage_lots' => [],
    'warehouses' => [],
    'tasks' => [],
    'budgets' => [],
    'notifications' => [],
    'outbound_messages' => [],
];
$track = static function (string $table, int $id) use (&$cleanup): void {
    if ($id > 0) {
        $cleanup[$table][] = $id;
    }
};

$baseUser = static function (string $role, string $name) use ($tag, $password): array {
    return [
        'name' => $name,
        'username' => $role . '_' . $name,
        'email' => $role . '.' . $name . '@fwcode.test',
        'phone' => '+260977123456',
        'role' => $role,
        'password' => $password,
        'password_confirm' => $password,
    ];
};

try {
    $ownerInput = $baseUser('owner', $tag);
    $ownerInput['username'] = 'own_' . $tag;
    $ownerInput['email'] = 'owner.' . $tag . '@fwcode.test';
    $ownerInput['farm_name'] = 'Green Valley ' . $tag;
    $ownerInput['farm_location'] = 'Lusaka';
    $owner = RegistrationService::submitRequest($ownerInput);
    $ok(!empty($owner['ok']), 'owner registration', (string) ($owner['error'] ?? ''));
    $ownerUser = !empty($owner['user_id']) ? db()->find('users', (int) $owner['user_id']) : null;
    $ownerFarm = !empty($owner['farm_id']) ? db()->find('farms', (int) $owner['farm_id']) : null;
    if ($ownerUser) {
        $track('users', (int) $ownerUser['id']);
    }
    if ($ownerFarm) {
        $track('farms', (int) $ownerFarm['id']);
    }
    $code = (string) ($ownerFarm['join_code'] ?? '');
    $ok($ownerFarm && $code !== '' && str_starts_with($code, 'GV'), 'farm code generated', $code);
    $ok($ownerUser && (int) $ownerUser['farm_id'] === (int) ($ownerFarm['id'] ?? 0) && ($ownerUser['status'] ?? '') === 'active', 'owner linked and active');
    $ok($ownerUser && (int) db()->value('SELECT COUNT(*) FROM user_farms WHERE user_id = ? AND farm_id = ?', [(int) $ownerUser['id'], (int) $ownerFarm['id']]) === 1, 'owner membership row');
    $mail = db()->first('SELECT subject, body FROM outbound_messages WHERE recipient = ? ORDER BY id DESC LIMIT 1', [$ownerInput['email']]);
    if ($mail) {
        $track('outbound_messages', (int) db()->value('SELECT id FROM outbound_messages WHERE recipient = ? ORDER BY id DESC LIMIT 1', [$ownerInput['email']]));
    }
    $ok($mail && str_contains((string) $mail['subject'], $ownerInput['farm_name']) && str_contains((string) $mail['body'], 'Approved') && str_contains((string) $mail['body'], $code) && !str_contains((string) $mail['body'], $password), 'owner email names the farm and code');

    $roles = ['manager' => 'mgr', 'agronomist' => 'agro', 'worker' => 'wrk', 'accountant' => 'acc'];
    $staff = [];
    foreach ($roles as $role => $prefix) {
        $input = $baseUser($role, $prefix . $tag);
        $input['username'] = $prefix . '_' . $tag;
        $input['email'] = $prefix . '.' . $tag . '@fwcode.test';
        $input['farm_code'] = $code;
        $result = RegistrationService::submitRequest($input);
        $user = !empty($result['user_id']) ? db()->find('users', (int) $result['user_id']) : null;
        if ($user) {
            $track('users', (int) $user['id']);
            $staff[$role] = $user;
        }
        $ok(!empty($result['ok']) && $user && ($user['status'] ?? '') === 'pending' && (int) $user['farm_id'] === (int) $ownerFarm['id'] && ($user['role'] ?? '') === $role, $role . ' pending on the correct farm', (string) ($result['error'] ?? ($user['status'] ?? '')));
    }
    $workerMail = db()->first('SELECT subject, body FROM outbound_messages WHERE recipient = ? ORDER BY id DESC LIMIT 1', ['wrk.' . $tag . '@fwcode.test']);
    $ok($workerMail && str_contains((string) $workerMail['subject'], $ownerInput['farm_name']) && str_contains((string) $workerMail['body'], 'Pending Approval'), 'staff email shows pending approval');

    $beforeUsers = (int) db()->value('SELECT COUNT(*) FROM users');
    $bad = RegistrationService::submitRequest($baseUser('worker', 'bad' . $tag) + [
        'username' => 'bad_' . $tag,
        'email' => 'bad.' . $tag . '@fwcode.test',
        'farm_code' => 'ZZZZ-NOPE',
    ]);
    $ok(empty($bad['ok']) && str_contains((string) ($bad['error'] ?? ''), 'The Farm Code you entered is invalid'), 'invalid farm code rejected', (string) ($bad['error'] ?? ''));
    $ok((int) db()->value('SELECT COUNT(*) FROM users') === $beforeUsers && !db()->first('SELECT id FROM users WHERE email = ?', ['bad.' . $tag . '@fwcode.test']), 'invalid code created no user');

    Auth::setOverride(null);
    Auth::clearCache();
    $pendingLogin = Auth::attemptLogin('wrk.' . $tag . '@fwcode.test', $password, false, $code);
    $ok(empty($pendingLogin['ok']) && str_contains((string) ($pendingLogin['error'] ?? ''), 'awaiting'), 'pending user cannot sign in', (string) ($pendingLogin['error'] ?? ''));

    Auth::setOverride($ownerUser);
    $_SESSION['user_id'] = (int) $ownerUser['id'];
    $_SESSION['session_version'] = (int) ($ownerUser['session_version'] ?? 1);
    $approved = RegistrationService::approve((int) $staff['worker']['id'], 'owner', [(int) $ownerFarm['id']], false, 'should ignore escalation');
    $workerNow = db()->find('users', (int) $staff['worker']['id']);
    $ok(!empty($approved['ok']) && ($workerNow['status'] ?? '') === 'active' && ($workerNow['role'] ?? '') === 'worker', 'owner approval keeps requested worker role', (string) ($approved['error'] ?? ($workerNow['role'] ?? '')));

    Auth::setOverride(null);
    Auth::clearCache();
    unset($_SESSION['user_id'], $_SESSION['active_farm_id']);
    $goodLogin = Auth::attemptLogin('wrk.' . $tag . '@fwcode.test', $password, false, $code);
    $ok(!empty($goodLogin['ok']) && Auth::role() === 'worker' && (int) Auth::farmId() === (int) $ownerFarm['id'], 'worker login with farm code');
    $ok(!Auth::can('transactions', 'view') && Auth::can('tasks', 'view'), 'worker cannot open finance');

    $other = RegistrationService::submitRequest([
        'name' => 'Other Owner ' . $tag,
        'username' => 'own2_' . $tag,
        'email' => 'owner2.' . $tag . '@fwcode.test',
        'phone' => '+260977123456',
        'role' => 'owner',
        'password' => $password,
        'password_confirm' => $password,
        'farm_name' => 'River Camp ' . $tag,
        'farm_location' => 'Kafue',
    ]);
    $otherFarm = !empty($other['farm_id']) ? db()->find('farms', (int) $other['farm_id']) : null;
    if (!empty($other['user_id'])) {
        $track('users', (int) $other['user_id']);
    }
    if ($otherFarm) {
        $track('farms', (int) $otherFarm['id']);
    }
    $wrong = Auth::attemptLogin('wrk.' . $tag . '@fwcode.test', $password, false, (string) ($otherFarm['join_code'] ?? 'RC-NOPE'));
    $ok(empty($wrong['ok']) && str_contains((string) ($wrong['error'] ?? ''), 'does not match'), 'wrong farm code rejected', (string) ($wrong['error'] ?? ''));
    Auth::attemptLogin('wrk.' . $tag . '@fwcode.test', $password, false, $code);
    $ok(!Auth::canAccessFarm((int) ($otherFarm['id'] ?? 0)), 'worker cannot access the other farm');

    $adminId = (int) db()->insert('users', [
        'name' => 'Temp Admin ' . $tag,
        'username' => 'adm_' . $tag,
        'email' => 'admin.' . $tag . '@fwcode.test',
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
        'status' => 'active',
        'must_change_password' => 0,
        'session_version' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $track('users', $adminId);
    Auth::clearCache();
    unset($_SESSION['user_id'], $_SESSION['active_farm_id']);
    $adminLogin = Auth::attemptLogin('admin.' . $tag . '@fwcode.test', $password, false, '');
    $ok(!empty($adminLogin['ok']) && Auth::role() === 'admin', 'administrator signs in without a farm code');

    Auth::clearCache();
    unset($_SESSION['user_id'], $_SESSION['active_farm_id']);
    $ownerLogin = Auth::attemptLogin($ownerInput['email'], $password, false, $code);
    $ok(!empty($ownerLogin['ok']) && Auth::role() === 'owner', 'owner signs in with farm code');

    $reset = AccountMail::passwordReset($ownerUser, 'https://farmwise.local/reset-password?token=test');
    $resetRow = db()->first('SELECT id, body FROM outbound_messages WHERE recipient = ? AND subject LIKE ? ORDER BY id DESC LIMIT 1', [$ownerInput['email'], '%password%']);
    if ($resetRow) {
        $track('outbound_messages', (int) $resetRow['id']);
    }
    $ok(!empty($reset['ok']) || $resetRow, 'password reset still queues', (string) ($reset['error'] ?? ''));
    $ok($resetRow && str_contains((string) $resetRow['body'], 'reset-password') && !str_contains((string) $resetRow['body'], $password), 'reset email has a link and no password');

    $fieldId = (int) db()->insert('fields', [
        'farm_id' => (int) $ownerFarm['id'],
        'name' => 'North ' . $tag,
        'size_hectares' => 12.5,
        'status' => 'active',
    ]);
    $track('fields', $fieldId);
    OperationHooks::afterSave(module_def('fields'), ['farm_id' => (int) $ownerFarm['id'], 'size_hectares' => 12.5], $fieldId, true, null);
    $area = (float) db()->value('SELECT total_area FROM farms WHERE id = ?', [(int) $ownerFarm['id']]);
    $ok(abs($area - 12.5) < 0.001, 'field size updates farm area', (string) $area);

    $varietyId = (int) db()->insert('crop_varieties', [
        'farm_id' => (int) $ownerFarm['id'],
        'name' => 'Maize ' . $tag,
        'variety' => 'Test',
        'status' => 'active',
    ]);
    $track('crop_varieties', $varietyId);
    $plantingId = (int) db()->insert('plantings', [
        'farm_id' => (int) $ownerFarm['id'],
        'field_id' => $fieldId,
        'crop_variety_id' => $varietyId,
        'planting_date' => date('Y-m-d'),
        'status' => 'growing',
        'growth_stage' => 'vegetative',
    ]);
    $track('plantings', $plantingId);
    $itemId = (int) db()->insert('inventory_items', [
        'farm_id' => (int) $ownerFarm['id'],
        'name' => 'Urea ' . $tag,
        'category' => 'fertilizer',
        'unit' => 'kg',
        'quantity' => 20,
        'reorder_level' => 5,
        'status' => 'in_stock',
    ]);
    $track('inventory_items', $itemId);
    $fertId = (int) db()->insert('fertilizer_applications', [
        'farm_id' => (int) $ownerFarm['id'],
        'planting_id' => $plantingId,
        'field_id' => $fieldId,
        'product_name' => 'Urea ' . $tag,
        'quantity' => 4,
        'application_date' => date('Y-m-d'),
        'cost' => 80,
        'inventory_item_id' => $itemId,
    ]);
    $track('fertilizer_applications', $fertId);
    $payload = db()->find('fertilizer_applications', $fertId);
    OperationHooks::afterSave(module_def('fertilizer_applications'), $payload, $fertId, true, null);
    $qty = (float) db()->value('SELECT quantity FROM inventory_items WHERE id = ?', [$itemId]);
    $expense = db()->first('SELECT id, amount, type FROM transactions WHERE reference = ?', ['fertilizer_applications#' . $fertId]);
    if ($expense) {
        $track('transactions', (int) $expense['id']);
    }
    $movement = db()->first('SELECT id FROM stock_movements WHERE reference = ?', ['fertilizer_applications#' . $fertId]);
    if ($movement) {
        $track('stock_movements', (int) $movement['id']);
    }
    $ok(abs($qty - 16) < 0.001, 'fertilizer use reduces stock', (string) $qty);
    $ok($expense && ($expense['type'] ?? '') === 'expense' && (float) $expense['amount'] === 80.0, 'fertilizer cost posts an expense');

    $harvestId = (int) db()->insert('harvests', [
        'farm_id' => (int) $ownerFarm['id'],
        'planting_id' => $plantingId,
        'field_id' => $fieldId,
        'harvest_date' => date('Y-m-d'),
        'quantity' => 40,
        'unit' => 'kg',
        'status' => 'completed',
    ]);
    $track('harvests', $harvestId);
    $harvest = db()->find('harvests', $harvestId);
    OperationHooks::afterSave(module_def('harvests'), $harvest, $harvestId, true, null);
    $lot = db()->first('SELECT id, quantity, warehouse_id FROM storage_lots WHERE harvest_id = ?', [$harvestId]);
    $planting = db()->find('plantings', $plantingId);
    if ($lot) {
        $track('storage_lots', (int) $lot['id']);
        $track('warehouses', (int) $lot['warehouse_id']);
    }
    $ok($lot && (float) $lot['quantity'] === 40.0 && ($planting['status'] ?? '') === 'harvested', 'harvest updates storage and planting', (string) ($planting['status'] ?? ''));

    $taskId = (int) db()->insert('tasks', [
        'farm_id' => (int) $ownerFarm['id'],
        'title' => 'Weed ' . $tag,
        'status' => 'pending',
        'created_by' => (int) $ownerUser['id'],
        'due_date' => date('Y-m-d', strtotime('-2 days')),
    ]);
    $track('tasks', $taskId);
    db()->update('tasks', ['status' => 'completed'], $taskId);
    $task = db()->find('tasks', $taskId);
    OperationHooks::afterSave(module_def('tasks'), $task, $taskId, false, ['status' => 'pending', 'farm_id' => (int) $ownerFarm['id'], 'title' => $task['title']]);
    $taskNote = db()->first("SELECT id FROM notifications WHERE user_id = ? AND title = ?", [(int) $ownerUser['id'], 'Task completed: Weed ' . $tag]);
    if ($taskNote) {
        $track('notifications', (int) $taskNote['id']);
    }
    $ok((bool) $taskNote, 'completed task notifies the owner');

    $budgetId = (int) db()->insert('budgets', [
        'farm_id' => (int) $ownerFarm['id'],
        'name' => 'Inputs ' . $tag,
        'period_start' => date('Y-m-01'),
        'period_end' => date('Y-m-t'),
        'category' => 'input_costs',
        'planned_amount' => 10,
    ]);
    $track('budgets', $budgetId);
    Auth::setOverride($ownerUser);
    $_SESSION['user_id'] = (int) $ownerUser['id'];
    $_SESSION['active_farm_id'] = (int) $ownerFarm['id'];
    $_SESSION['session_version'] = (int) ($ownerUser['session_version'] ?? 1);
    NotificationService::syncAlerts(true);
    $budgetNote = db()->first("SELECT id FROM notifications WHERE user_id = ? AND title = ?", [(int) $ownerUser['id'], 'Budget exceeded: Inputs ' . $tag]);
    if ($budgetNote) {
        $track('notifications', (int) $budgetNote['id']);
    }
    $ok((bool) $budgetNote, 'budget overrun alerts the owner');

    $throttleId = 'nobody.' . $tag . '@fwcode.test';
    for ($i = 0; $i < 8; $i++) {
        Auth::authenticate($throttleId, 'nope', $code);
    }
    $blocked = Auth::authenticate($throttleId, 'nope', $code);
    $ok(empty($blocked['ok']) && str_contains((string) ($blocked['error'] ?? ''), 'Too many sign-in'), 'login attempts are rate limited', (string) ($blocked['error'] ?? ''));
    db()->query('DELETE FROM login_attempts WHERE identity = ?', [strtolower($throttleId)]);
} catch (Throwable $e) {
    $ok(false, 'unexpected error', $e->getMessage());
} finally {
    Auth::setOverride(null);
    Auth::clearCache();
    foreach (['stock_movements', 'fertilizer_applications', 'storage_lots', 'harvests', 'transactions', 'tasks', 'plantings', 'inventory_items', 'crop_varieties', 'fields', 'warehouses', 'budgets', 'notifications', 'outbound_messages'] as $table) {
        foreach ($cleanup[$table] as $id) {
            try {
                db()->delete($table, $id);
            } catch (Throwable $e) {
            }
        }
    }
    foreach ($cleanup['users'] as $id) {
        try {
            db()->query('DELETE FROM user_farms WHERE user_id = ?', [$id]);
            db()->query('DELETE FROM notifications WHERE user_id = ? OR link = ?', [$id, 'users/pending/' . $id]);
            db()->query('DELETE FROM outbound_messages WHERE user_id = ?', [$id]);
            db()->delete('users', $id);
        } catch (Throwable $e) {
        }
    }
    foreach ($cleanup['farms'] as $id) {
        try {
            db()->query('DELETE FROM user_farms WHERE farm_id = ?', [$id]);
            db()->query('DELETE FROM notifications WHERE farm_id = ?', [$id]);
            db()->delete('farms', $id);
        } catch (Throwable $e) {
        }
    }
}

echo ($fail === 0 ? 'ALL PASSED' : ($fail . ' FAILED')) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
