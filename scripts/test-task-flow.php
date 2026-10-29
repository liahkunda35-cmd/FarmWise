<?php
declare(strict_types=1);

/**
 * End-to-end task workflow test (additive — does not wipe data).
 * Verifies: assign → worker visibility → status update → notifications → owner/admin see same row.
 */
require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$ok = static function (bool $cond, string $label) use (&$fail): void {
    echo ($cond ? 'OK  ' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$cond) {
        $fail++;
    }
};

$farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
$ok((bool) $farm, 'farm exists');
$farmId = (int) ($farm['id'] ?? 0);

$manager = db()->first("SELECT * FROM users WHERE role = 'manager' AND status = 'active' ORDER BY id ASC");
$owner = db()->first("SELECT * FROM users WHERE role = 'owner' AND status = 'active' ORDER BY id ASC");
$workerUser = db()->first("SELECT * FROM users WHERE role = 'worker' AND status = 'active' ORDER BY id ASC");
$admin = db()->first("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");
$ok((bool) $manager, 'manager user');
$ok((bool) $workerUser, 'worker user');
$ok((bool) $admin, 'admin user');

// Ensure worker has linked employee on this farm
$emp = db()->first('SELECT * FROM employees WHERE user_id = ? AND farm_id = ?', [$workerUser['id'] ?? 0, $farmId]);
if (!$emp && $workerUser && $farmId) {
    $empId = db()->insert('employees', [
        'farm_id' => $farmId,
        'user_id' => (int) $workerUser['id'],
        'name' => $workerUser['name'] ?: 'Test Worker',
        'role_title' => 'Field worker',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $emp = db()->find('employees', $empId);
}
$ok((bool) $emp, 'worker linked to employee record id=' . ($emp['id'] ?? '?'));

// Manager creates task
Auth::setOverride($manager);
Auth::setActiveFarm($farmId);
$payload = [
    'farm_id' => $farmId,
    'title' => 'Apply fertiliser to Field A (test ' . bin2hex(random_bytes(2)) . ')',
    'description' => 'Automated task workflow test',
    'assigned_to' => (int) $emp['id'],
    'due_date' => date('Y-m-d', strtotime('+3 days')),
    'priority' => 'high',
    'status' => 'pending',
    'created_by' => (int) $manager['id'],
];
$assigneeErr = TaskAccess::validateAssignee((int) $emp['id'], $farmId);
$ok($assigneeErr === null, 'assignee same farm');
$taskId = db()->insert('tasks', $payload);
OperationHooks::afterSave(
    ['table' => 'tasks', 'singular' => 'Task'],
    $payload,
    $taskId,
    true,
    null
);
$task = db()->find('tasks', $taskId);
$ok((bool) $task, 'task saved id=' . $taskId);

$notifWorker = (int) db()->value(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND category = 'tasks' AND link = ?",
    [(int) $workerUser['id'], 'module/tasks/view/' . $taskId]
);
$ok($notifWorker > 0, 'worker got assignment notification');

// Worker sees only assigned tasks
Auth::setOverride($workerUser);
Auth::setActiveFarm($farmId);
$ok(TaskAccess::workerCanAccessTask($task), 'worker can access assigned task');
$otherEmp = db()->first('SELECT id FROM employees WHERE farm_id = ? AND id <> ?', [$farmId, $emp['id']]);
if ($otherEmp) {
    $otherId = db()->insert('tasks', [
        'farm_id' => $farmId,
        'title' => 'Other worker task ' . bin2hex(random_bytes(2)),
        'assigned_to' => (int) $otherEmp['id'],
        'status' => 'pending',
        'priority' => 'normal',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $other = db()->find('tasks', $otherId);
    $ok(!TaskAccess::workerCanAccessTask($other), 'worker cannot access other assignee task');
}

// Worker completes task
$prev = $task;
$upd = array_merge($task, ['status' => 'completed']);
db()->update('tasks', ['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s')], $taskId);
OperationHooks::afterSave(['table' => 'tasks'], $upd, $taskId, false, $prev);
$task = db()->find('tasks', $taskId);
$ok(($task['status'] ?? '') === 'completed', 'task status completed');

$ownerId = $owner ? (int) $owner['id'] : 0;
if ($ownerId) {
    $nOwner = (int) db()->value(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND category = 'tasks' AND link = ?",
        [$ownerId, 'module/tasks/view/' . $taskId]
    );
    $ok($nOwner > 0, 'owner notified on completion');
}

// Same row visible to owner and admin (single source of truth)
Auth::setOverride($owner ?: $manager);
$ok((bool) db()->find('tasks', $taskId), 'owner/manager sees same task row');
Auth::setOverride($admin);
$ok((bool) db()->find('tasks', $taskId), 'admin can monitor task row');
$ok(Auth::can('tasks', 'view'), 'admin can view tasks module');

// RBAC: worker cannot view transactions
Auth::setOverride($workerUser);
$ok(!Auth::can('transactions', 'view'), 'worker blocked from finance');
$ok(!Auth::can('users', 'edit'), 'worker blocked from user admin');

// Owner cannot access integrations (platform admin)
Auth::setOverride($owner);
$ok(!Auth::can('integrations', 'view'), 'owner blocked from integrations');
$ok(Auth::can('tasks', 'create'), 'owner can assign tasks');

Auth::setOverride(null);
Auth::clearCache();

echo 'failures=' . $fail . PHP_EOL;
exit($fail > 0 ? 1 : 0);
