<?php
declare(strict_types=1);

/**
 * Additive E2E: manager creates task → worker sees it → complete → owner notified.
 * Does not wipe existing data.
 */
require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
$ok('farm exists', (bool) $farm);
if (!$farm) {
    exit(1);
}
$farmId = (int) $farm['id'];

$manager = db()->first("SELECT * FROM users WHERE role = 'manager' AND status = 'active' ORDER BY id ASC");
$owner = db()->first("SELECT * FROM users WHERE role = 'owner' AND status = 'active' ORDER BY id ASC");
$workerUser = db()->first("SELECT * FROM users WHERE role = 'worker' AND status = 'active' ORDER BY id ASC");
$admin = db()->first("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");

$ok('manager user', (bool) $manager);
$ok('worker user', (bool) $workerUser);
$ok('owner user', (bool) $owner);
$ok('admin user', (bool) $admin);

if (!$manager || !$workerUser) {
    exit(1);
}

// Ensure worker has linked employee on this farm
$emp = db()->first('SELECT * FROM employees WHERE user_id = ? AND farm_id = ?', [$workerUser['id'], $farmId]);
if (!$emp) {
    $empId = db()->insert('employees', [
        'farm_id' => $farmId,
        'user_id' => (int) $workerUser['id'],
        'name' => $workerUser['name'] ?: 'Test Worker',
        'role_title' => 'Field worker',
        'status' => 'active',
        'daily_rate' => 100,
    ]);
    $emp = db()->find('employees', $empId);
}
$ok('worker employee linked', (bool) $emp && (int) $emp['user_id'] === (int) $workerUser['id']);
$empId = (int) $emp['id'];

// Membership
foreach ([$manager, $owner, $workerUser] as $u) {
    if (!$u) {
        continue;
    }
    $exists = db()->first('SELECT id FROM user_farms WHERE user_id = ? AND farm_id = ?', [$u['id'], $farmId]);
    if (!$exists) {
        db()->insert('user_farms', [
            'user_id' => $u['id'],
            'farm_id' => $farmId,
            'is_primary' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

Auth::setOverride($manager);
$_SESSION['user_id'] = (int) $manager['id'];
$_SESSION['active_farm_id'] = $farmId;
$_SESSION['session_version'] = (int) ($manager['session_version'] ?? 1);
Auth::clearCache();

$ok('manager can create tasks', Auth::can('tasks', 'create'));
$ok('manager canAccessFarm', Auth::canAccessFarm($farmId));

$taskTitle = 'E2E fertiliser Field A ' . bin2hex(random_bytes(2));
$taskId = db()->insert('tasks', [
    'farm_id' => $farmId,
    'title' => $taskTitle,
    'description' => 'Apply fertiliser as assigned by manager.',
    'assigned_to' => $empId,
    'due_date' => date('Y-m-d', strtotime('+3 days')),
    'priority' => 'high',
    'status' => 'pending',
    'created_by' => (int) $manager['id'],
    'related_module' => 'plantings',
    'related_id' => null,
]);
OperationHooks::afterSave(
    ['table' => 'tasks', 'singular' => 'Task'],
    [
        'farm_id' => $farmId,
        'title' => $taskTitle,
        'assigned_to' => $empId,
        'due_date' => date('Y-m-d', strtotime('+3 days')),
        'status' => 'pending',
        'created_by' => (int) $manager['id'],
    ],
    $taskId,
    true,
    null
);

$task = db()->find('tasks', $taskId);
$ok('task saved', (bool) $task && (int) $task['assigned_to'] === $empId);

$workerNotifs = (int) db()->value(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND category = 'tasks' AND link = ?",
    [$workerUser['id'], 'module/tasks/view/' . $taskId]
);
$ok('worker notified on assign', $workerNotifs > 0, 'count=' . $workerNotifs);

// Worker visibility
Auth::setOverride($workerUser);
$_SESSION['user_id'] = (int) $workerUser['id'];
$_SESSION['active_farm_id'] = $farmId;
Auth::clearCache();
$ok('worker TaskAccess', TaskAccess::workerCanAccessTask($task));
$ok('worker cannot see finance', !Auth::can('transactions', 'view'));

$otherEmp = db()->first('SELECT id FROM employees WHERE farm_id = ? AND id <> ?', [$farmId, $empId]);
if ($otherEmp) {
    $otherTaskId = db()->insert('tasks', [
        'farm_id' => $farmId,
        'title' => 'Other worker task ' . bin2hex(random_bytes(2)),
        'assigned_to' => (int) $otherEmp['id'],
        'status' => 'pending',
        'priority' => 'normal',
    ]);
    $otherTask = db()->find('tasks', $otherTaskId);
    $ok('worker blocked from other assignment', !TaskAccess::workerCanAccessTask($otherTask));
}

// Complete as worker (simulate save hooks)
db()->update('tasks', ['status' => 'completed'], $taskId);
$updated = db()->find('tasks', $taskId);
OperationHooks::afterSave(
    ['table' => 'tasks', 'singular' => 'Task'],
    [
        'farm_id' => $farmId,
        'title' => $taskTitle,
        'assigned_to' => $empId,
        'status' => 'completed',
        'created_by' => (int) $manager['id'],
    ],
    $taskId,
    false,
    ['status' => 'pending', 'assigned_to' => $empId]
);
$ok('task completed', ($updated['status'] ?? '') === 'completed');

$mgrDone = (int) db()->value(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND category = 'tasks' AND title LIKE ?",
    [$manager['id'], 'Task completed:%']
);
$ok('manager notified on complete', $mgrDone > 0, 'count=' . $mgrDone);

if ($owner) {
    $ownDone = (int) db()->value(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND category = 'tasks' AND link = ?",
        [$owner['id'], 'module/tasks/view/' . $taskId]
    );
    $ok('owner has task notification', $ownDone > 0, 'count=' . $ownDone);
}

// Admin can access any farm task
if ($admin) {
    Auth::setOverride($admin);
    Auth::clearCache();
    $ok('admin canAccessRecord task', Auth::canAccessRecord($task));
    $ok('admin can view tasks', Auth::can('tasks', 'view'));
}

// Assignee must be same farm
$bad = TaskAccess::validateAssignee($empId, $farmId + 99999);
$ok('cross-farm assignee rejected', $bad !== null, (string) $bad);

// Owner no longer has platform * 
Auth::setOverride($owner);
Auth::clearCache();
$ok('owner cannot backups', !Auth::can('backups', 'view'));
$ok('owner can tasks', Auth::can('tasks', 'create'));
$ok('owner cannot integrations', !Auth::can('integrations', 'view'));

echo 'failures=' . $fail . PHP_EOL;
exit($fail > 0 ? 1 : 0);
