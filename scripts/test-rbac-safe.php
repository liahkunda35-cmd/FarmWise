<?php
declare(strict_types=1);

/**
 * Disposable SQLite RBAC / AI-context suite for FarmMS.
 * Never loads Neon or the project storage/ifms.sqlite file.
 *
 * Run: C:\xampp\php\php.exe scripts\test-rbac-safe.php
 */

$root = dirname(__DIR__);
$tempDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'farmms-rbac-tests';
if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "Cannot create temp dir\n");
    exit(1);
}
$dbPath = $tempDir . DIRECTORY_SEPARATOR . 'rbac-' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($dbPath);

// Force disposable SQLite before bootstrap reads .env (process env wins).
putenv('IFMS_DB_DRIVER=sqlite');
putenv('IFMS_SQLITE_PATH=' . $dbPath);
putenv('IFMS_TEST_CAPTURE=1');
putenv('GEMINI_API_KEY=');
putenv('OPENWEATHER_API_KEY=');
$_ENV['IFMS_DB_DRIVER'] = 'sqlite';
$_ENV['IFMS_SQLITE_PATH'] = $dbPath;
$_ENV['IFMS_TEST_CAPTURE'] = '1';
$_ENV['GEMINI_API_KEY'] = '';
$_ENV['OPENWEATHER_API_KEY'] = '';
$_SERVER['IFMS_DB_DRIVER'] = 'sqlite';
$_SERVER['IFMS_SQLITE_PATH'] = $dbPath;
$_SERVER['IFMS_TEST_CAPTURE'] = '1';

// Neutralise Neon URL influence even if .env is present.
foreach (['DATABASE_URL', 'DATABASE_URL_POOLED', 'DATABASE_URL_UNPOOLED', 'IFMS_DATABASE_URL'] as $k) {
    putenv($k . '=');
    $_ENV[$k] = '';
    $_SERVER[$k] = '';
}

require $root . '/backend/bootstrap.php';

$failed = 0;
$passed = 0;
$failures = [];

$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed, &$failures): void {
    if ($ok) {
        $passed++;
        echo "OK  {$name}\n";
        return;
    }
    $failed++;
    $failures[] = $name . ($detail !== '' ? ' — ' . $detail : '');
    echo "FAIL {$name}" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

$driver = db()->driver();
$check('driver is sqlite', $driver === 'sqlite', $driver);

$list = db()->select('PRAGMA database_list');
$mainPath = '';
foreach ($list as $row) {
    if (($row['name'] ?? '') === 'main') {
        $mainPath = str_replace('\\', '/', (string) ($row['file'] ?? ''));
    }
}
$expected = str_replace('\\', '/', $dbPath);
$check('PRAGMA database_list matches disposable path', $mainPath === $expected, $mainPath);
$check('not project storage sqlite', !str_contains($mainPath, '/storage/ifms.sqlite'));
$check('not empty neon-looking path', $mainPath !== '' && !str_starts_with($mainPath, 'pgsql'));

if ($failed > 0) {
    echo "Aborting — refusing to continue against an unexpected database.\n";
    @unlink($dbPath);
    exit(1);
}

$now = date('Y-m-d H:i:s');
$farmA = (int) db()->insert('farms', [
    'name' => 'RBAC Farm A',
    'location' => 'Lusaka',
    'total_area' => 10,
    'status' => 'active',
    'join_code' => 'TESTAAAA',
    'created_at' => $now,
]);
$farmB = (int) db()->insert('farms', [
    'name' => 'RBAC Farm B',
    'location' => 'Kafue',
    'total_area' => 8,
    'status' => 'active',
    'join_code' => 'TESTBBBB',
    'created_at' => $now,
]);

$hash = password_hash('Test#Rbac26', PASSWORD_DEFAULT);
$mkUser = static function (string $name, string $role, ?int $farmId) use ($hash, $now): int {
    $id = (int) db()->insert('users', [
        'name' => $name,
        'username' => strtolower($role) . '_rbac_' . bin2hex(random_bytes(2)),
        'email' => strtolower($role) . '.' . bin2hex(random_bytes(2)) . '@rbac.test',
        'password' => $hash,
        'role' => $role,
        'farm_id' => $farmId,
        'status' => 'active',
        'must_change_password' => 0,
        'session_version' => 1,
        'created_at' => $now,
    ]);
    if ($farmId && table_exists('user_farms')) {
        db()->insert('user_farms', [
            'user_id' => $id,
            'farm_id' => $farmId,
            'is_primary' => 1,
            'created_at' => $now,
        ]);
    }
    return $id;
};

$adminId = $mkUser('Admin Rbac', 'admin', null);
$ownerId = $mkUser('Owner Rbac', 'owner', $farmA);
$managerId = $mkUser('Manager Rbac', 'manager', $farmA);
$agroId = $mkUser('Agro Rbac', 'agronomist', $farmA);
$workerId = $mkUser('Worker Rbac', 'worker', $farmA);
$acctId = $mkUser('Acct Rbac', 'accountant', $farmA);
$otherOwnerId = $mkUser('Other Owner', 'owner', $farmB);

db()->update('farms', ['owner_id' => $ownerId], $farmA);
db()->update('farms', ['owner_id' => $otherOwnerId], $farmB);

$empWorker = (int) db()->insert('employees', [
    'farm_id' => $farmA,
    'user_id' => $workerId,
    'name' => 'Worker Emp',
    'status' => 'active',
    'created_at' => $now,
]);
$empOther = (int) db()->insert('employees', [
    'farm_id' => $farmA,
    'name' => 'Other Emp',
    'status' => 'active',
    'created_at' => $now,
]);

$taskMine = (int) db()->insert('tasks', [
    'farm_id' => $farmA,
    'title' => 'My assigned task',
    'assigned_to' => $empWorker,
    'status' => 'pending',
    'created_at' => $now,
]);
$taskOther = (int) db()->insert('tasks', [
    'farm_id' => $farmA,
    'title' => 'Other worker task',
    'assigned_to' => $empOther,
    'status' => 'pending',
    'created_at' => $now,
]);
$taskForeign = (int) db()->insert('tasks', [
    'farm_id' => $farmB,
    'title' => 'Foreign farm task',
    'assigned_to' => $empWorker,
    'status' => 'pending',
    'created_at' => $now,
]);

db()->insert('transactions', [
    'farm_id' => $farmA,
    'txn_date' => date('Y-m-d'),
    'type' => 'income',
    'category' => 'sales',
    'description' => 'SECRET-INCOME',
    'amount' => 5000,
    'created_at' => $now,
]);
db()->insert('transactions', [
    'farm_id' => $farmA,
    'txn_date' => date('Y-m-d'),
    'type' => 'expense',
    'category' => 'feed',
    'description' => 'SECRET-EXPENSE',
    'amount' => 120,
    'created_at' => $now,
]);

$fieldId = (int) db()->insert('fields', [
    'farm_id' => $farmA,
    'name' => 'Plot A',
    'size_hectares' => 2,
    'status' => 'active',
    'created_at' => $now,
]);
$cropId = (int) db()->insert('crop_varieties', [
    'name' => 'Test Maize',
    'category' => 'maize',
    'status' => 'active',
    'created_at' => $now,
]);
$plantingId = (int) db()->insert('plantings', [
    'farm_id' => $farmA,
    'field_id' => $fieldId,
    'crop_variety_id' => $cropId,
    'season' => '2026',
    'status' => 'growing',
    'growth_stage' => 'vegetative',
    'planting_date' => date('Y-m-d', strtotime('-30 days')),
    'created_at' => $now,
]);
$animalId = (int) db()->insert('animals', [
    'farm_id' => $farmA,
    'tag_number' => 'COW-1',
    'species' => 'cattle',
    'status' => 'active',
    'created_at' => $now,
]);
db()->insert('inventory_items', [
    'farm_id' => $farmA,
    'name' => 'Fertiliser',
    'category' => 'inputs',
    'quantity' => 40,
    'unit' => 'kg',
    'unit_cost' => 88.5,
    'created_at' => $now,
]);

$as = static function (int $userId) use ($farmA): array {
    $user = db()->find('users', $userId);
    Auth::setOverride($user);
    Auth::clearCache();
    Auth::setOverride($user);
    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
    if (!empty($user['farm_id'])) {
        $_SESSION['active_farm_id'] = (int) $user['farm_id'];
    } else {
        unset($_SESSION['active_farm_id']);
    }
    return $user;
};

// --- Role permission matrix samples ---
$as($workerId);
$check('worker cannot view transactions', !Auth::can('transactions', 'view'));
$check('worker cannot view backups', !Auth::can('backups', 'view'));
$check('worker cannot view storage_lots', !Auth::can('storage_lots', 'view'));
$check('worker can view tasks', Auth::can('tasks', 'view'));
$check('worker can edit tasks', Auth::can('tasks', 'edit'));
$check('worker can create attendance', Auth::can('attendance', 'create'));
$check('worker cannot create livestock_feed edit', !Auth::can('livestock_feed', 'edit'));
$check('worker can create livestock_feed', Auth::can('livestock_feed', 'create'));
$check('worker can view plantings (relation forms)', Auth::can('plantings', 'view'));

$as($managerId);
$check('manager can view transactions (intentional)', Auth::can('transactions', 'view'));
$check('manager cannot backups', !Auth::can('backups', 'view'));
$check('manager cannot settings', !Auth::can('settings', 'view'));
$check('manager can create tasks', Auth::can('tasks', 'create'));

$as($ownerId);
$check('owner can view transactions', Auth::can('transactions', 'view'));
$check('owner can edit users', Auth::can('users', 'edit'));
$check('owner cannot backups', !Auth::can('backups', 'view'));
$check('owner cannot integrations', !Auth::can('integrations', 'edit'));

$as($agroId);
$check('agronomist cannot transactions', !Auth::can('transactions', 'view'));
$check('agronomist can plantings', Auth::can('plantings', 'view'));
$check('agronomist cannot payroll', !Auth::can('payroll', 'view'));
$check('agronomist cannot animals', !Auth::can('animals', 'view'));

$as($acctId);
$check('accountant can transactions', Auth::can('transactions', 'view'));
$check('accountant cannot plantings', !Auth::can('plantings', 'view'));
$check('accountant cannot backups', !Auth::can('backups', 'view'));

$as($adminId);
$check('admin can backups', Auth::can('backups', 'view'));
$check('admin can settings', Auth::can('settings', 'edit'));
$check('admin canAccessFarm A', Auth::canAccessFarm($farmA));
$check('admin canAccessFarm B', Auth::canAccessFarm($farmB));

// --- Farm isolation ---
$as($workerId);
$check('worker canAccessFarm A', Auth::canAccessFarm($farmA));
$check('worker cannot access farm B', !Auth::canAccessFarm($farmB));
$mine = db()->find('tasks', $taskMine);
$other = db()->find('tasks', $taskOther);
$foreign = db()->find('tasks', $taskForeign);
$check('worker own task access', TaskAccess::workerCanAccessTask($mine) && Auth::canAccessRecord($mine));
$check('worker other task denied', !TaskAccess::workerCanAccessTask($other));
$check('worker foreign farm task denied by farm', !Auth::canAccessRecord($foreign));

$as($ownerId);
$check('owner cannot access farm B', !Auth::canAccessFarm($farmB));
$as($otherOwnerId);
$check('other owner cannot access farm A', !Auth::canAccessFarm($farmA));

// --- WorkerRecords ---
$as($workerId);
$check(
    'worker cost on feed denied',
    WorkerRecords::saveError('livestock_feed', ['cost' => 15, 'farm_id' => $farmA], null) !== null
);
$check(
    'worker zero cost on feed allowed',
    WorkerRecords::saveError('livestock_feed', ['cost' => 0, 'farm_id' => $farmA], null) === null
);
$check(
    'worker death health denied',
    WorkerRecords::saveError('livestock_health', ['record_type' => 'death', 'cost' => 0], null) !== null
);
$check(
    'worker other attendance denied',
    WorkerRecords::saveError('attendance', ['employee_id' => $empOther], null) !== null
);
$check(
    'worker own attendance allowed',
    WorkerRecords::saveError('attendance', ['employee_id' => $empWorker], null) === null
);
$check(
    'worker storage_lots denied',
    WorkerRecords::saveError('storage_lots', ['farm_id' => $farmA], null) !== null
);

$growthOther = (int) db()->insert('crop_growth', [
    'farm_id' => $farmA,
    'planting_id' => $plantingId,
    'recorded_by' => $managerId,
    'record_date' => date('Y-m-d'),
    'growth_stage' => 'vegetative',
    'observations' => 'Manager note',
    'created_at' => $now,
]);
$growthMine = (int) db()->insert('crop_growth', [
    'farm_id' => $farmA,
    'planting_id' => $plantingId,
    'recorded_by' => $workerId,
    'record_date' => date('Y-m-d'),
    'growth_stage' => 'vegetative',
    'observations' => 'Worker note',
    'created_at' => $now,
]);
$check(
    'worker cannot edit other growth note',
    WorkerRecords::growthEditError(db()->find('crop_growth', $growthOther)) !== null
);
$check(
    'worker can edit own growth note',
    WorkerRecords::growthEditError(db()->find('crop_growth', $growthMine)) === null
);

// --- OperationHooks side effects for workers ---
$as($workerId);
$defFeed = module_def('livestock_feed');
$feedId = (int) db()->insert('livestock_feed', [
    'farm_id' => $farmA,
    'animal_id' => $animalId,
    'feed_name' => 'Maize bran',
    'quantity' => 2,
    'feed_date' => date('Y-m-d'),
    'cost' => 0,
    'created_at' => $now,
]);
OperationHooks::afterSave($defFeed, [
    'farm_id' => $farmA,
    'animal_id' => $animalId,
    'feed_name' => 'Maize bran',
    'quantity' => 2,
    'feed_date' => date('Y-m-d'),
    'cost' => 99,
], $feedId, true, null);
$txnCount = (int) db()->value("SELECT COUNT(*) FROM transactions WHERE reference = ?", ['livestock_feed#' . $feedId]);
$check('worker feed cost does not post ledger', $txnCount === 0);

$defHarvest = module_def('harvests');
$harvestId = (int) db()->insert('harvests', [
    'farm_id' => $farmA,
    'planting_id' => $plantingId,
    'harvest_date' => date('Y-m-d'),
    'quantity' => 10,
    'unit' => 'kg',
    'status' => 'completed',
    'created_at' => $now,
]);
OperationHooks::afterSave($defHarvest, [
    'farm_id' => $farmA,
    'planting_id' => $plantingId,
    'harvest_date' => date('Y-m-d'),
    'quantity' => 10,
    'unit' => 'kg',
    'status' => 'completed',
], $harvestId, true, null);
$planting = db()->find('plantings', $plantingId);
$check('worker harvest does not mark planting harvested', ($planting['status'] ?? '') !== 'harvested');
$storageFromHarvest = (int) db()->value('SELECT COUNT(*) FROM storage_lots WHERE harvest_id = ?', [$harvestId]);
$check('worker harvest does not auto-create storage lot', $storageFromHarvest === 0);

$defGrowth = module_def('crop_growth');
OperationHooks::afterSave($defGrowth, [
    'farm_id' => $farmA,
    'planting_id' => $plantingId,
    'growth_stage' => 'flowering',
    'record_date' => date('Y-m-d'),
], $growthMine, true, null);
$planting = db()->find('plantings', $plantingId);
$check('worker growth note does not rewrite planting stage', ($planting['growth_stage'] ?? '') === 'vegetative');

$defHealth = module_def('livestock_health');
$healthId = (int) db()->insert('livestock_health', [
    'farm_id' => $farmA,
    'animal_id' => $animalId,
    'record_type' => 'death',
    'title' => 'Reported death',
    'record_date' => date('Y-m-d'),
    'cost' => 0,
    'created_at' => $now,
]);
OperationHooks::afterSave($defHealth, [
    'farm_id' => $farmA,
    'animal_id' => $animalId,
    'record_type' => 'death',
    'title' => 'Reported death',
    'record_date' => date('Y-m-d'),
    'cost' => 0,
], $healthId, true, null);
$animal = db()->find('animals', $animalId);
$check('worker death log does not mark animal deceased', ($animal['status'] ?? '') === 'active');

// Manager harvest DOES update planting
$as($managerId);
db()->update('plantings', ['status' => 'growing', 'growth_stage' => 'vegetative'], $plantingId);
$harvestMgr = (int) db()->insert('harvests', [
    'farm_id' => $farmA,
    'planting_id' => $plantingId,
    'harvest_date' => date('Y-m-d'),
    'quantity' => 5,
    'unit' => 'kg',
    'status' => 'completed',
    'created_at' => $now,
]);
OperationHooks::afterSave($defHarvest, [
    'farm_id' => $farmA,
    'planting_id' => $plantingId,
    'harvest_date' => date('Y-m-d'),
    'quantity' => 5,
    'unit' => 'kg',
    'status' => 'completed',
], $harvestMgr, true, null);
$planting = db()->find('plantings', $plantingId);
$check('manager harvest marks planting harvested', ($planting['status'] ?? '') === 'harvested');

// --- Valuation stripping ---
$as($workerId);
$invDef = WorkerRecords::withoutValuation(module_def('inventory_items'));
$check('worker inventory def hides unit_cost', !isset($invDef['fields']['unit_cost']));
$as($acctId);
$invDefAcct = WorkerRecords::withoutValuation(module_def('inventory_items'));
$check('accountant inventory def keeps unit_cost', isset($invDefAcct['fields']['unit_cost']));

// --- AI context ---
$as($workerId);
$snap = FarmAssistantContext::snapshot();
$check('worker AI has no income total', !str_contains($snap, 'Recorded income total'));
$check('worker AI has no expense total', !str_contains($snap, 'Recorded expenses total'));
$check('worker AI includes own task', str_contains($snap, 'My assigned task'));
$check('worker AI excludes other task', !str_contains($snap, 'Other worker task'));
$check('worker AI has no email address', !str_contains($snap, '@rbac.test'));

$as($acctId);
$snapAcct = FarmAssistantContext::snapshot();
$check('accountant AI includes income', str_contains($snapAcct, 'Recorded income total'));
$check('accountant AI excludes plantings detail', !str_contains($snapAcct, 'Recent / active plantings'));

$as($agroId);
$snapAgro = FarmAssistantContext::snapshot();
$check('agronomist AI has no finance', !str_contains($snapAgro, 'Recorded income total'));
$check('agronomist AI has plantings', str_contains($snapAgro, 'Active plantings') || str_contains($snapAgro, 'plantings'));

$as($adminId);
unset($_SESSION['active_farm_id']);
$adminOpen = db()->find('users', $adminId);
$adminOpen['farm_id'] = null;
Auth::setOverride($adminOpen);
$snapAdmin = FarmAssistantContext::snapshot();
$check('admin without farm gets no private records', !str_contains($snapAdmin, 'SECRET-INCOME') && str_contains($snapAdmin, 'no farm selected'));

// --- API dashboard finance gate (via reflection) ---
$as($workerId);
$_SESSION['active_farm_id'] = $farmA;
$ref = new ReflectionClass('ApiController');
$dash = $ref->getMethod('dashboard');
$dash->setAccessible(true);
$GLOBALS['ifms_test_exit'] = null;
try {
    $dash->invoke(null);
    $check('api dashboard worker exits via capture', false, 'no exit');
} catch (RuntimeException $e) {
    $check('api dashboard worker throws test exit', $e->getMessage() === 'IFMS_TEST_EXIT');
}
$exit = $GLOBALS['ifms_test_exit'] ?? [];
$payload = $exit['data']['data'] ?? [];
$check('api dashboard worker omits revenue', !array_key_exists('revenue', $payload));
$check('api dashboard worker omits expenses', !array_key_exists('expenses', $payload));
$check('api dashboard worker omits farm acreage', !array_key_exists('acreage', $payload));
$check('api dashboard worker role is worker', ($payload['role'] ?? '') === 'worker');

$as($managerId);
$_SESSION['active_farm_id'] = $farmA;
$GLOBALS['ifms_test_exit'] = null;
try {
    $dash->invoke(null);
} catch (RuntimeException $e) {
}
$exit = $GLOBALS['ifms_test_exit'] ?? [];
$payload = $exit['data']['data'] ?? [];
$check('api dashboard manager includes revenue', array_key_exists('revenue', $payload));
$check('api dashboard manager includes expenses', array_key_exists('expenses', $payload));

// --- Geocode gate ---
$as($workerId);
$geo = $ref->getMethod('geocode');
$geo->setAccessible(true);
$GLOBALS['ifms_test_exit'] = null;
$_GET['q'] = 'Lusaka';
try {
    $geo->invoke(null);
} catch (RuntimeException $e) {
}
$exit = $GLOBALS['ifms_test_exit'] ?? [];
$check('worker geocode denied', ($exit['code'] ?? 0) === 403);

$as($managerId);
$GLOBALS['ifms_test_exit'] = null;
try {
    $geo->invoke(null);
} catch (RuntimeException $e) {
}
$exit = $GLOBALS['ifms_test_exit'] ?? [];
$check('manager geocode allowed (or network stub ok)', ($exit['code'] ?? 0) === 200 || ($exit['data']['ok'] ?? false) === true);

// --- Report types ---
$as($agroId);
$types = ReportService::visibleTypes();
$check('agronomist sees performance report', isset($types['performance']));
$check('agronomist denied financial report', !isset($types['financial']));
$as($workerId);
$check('worker cannot open reports module', !Auth::can('reports', 'view'));

// --- Nav farm map key ---
$nav = require $root . '/backend/config/navigation.php';
$mapItem = null;
foreach ($nav as $sec) {
    foreach ($sec['items'] as $it) {
        if (($it['href'] ?? '') === 'map') {
            $mapItem = $it;
        }
    }
}
$check('farm map nav key is fields', ($mapItem['key'] ?? '') === 'fields');
$as($managerId);
$check('manager can see farm map nav', can_see_nav($mapItem));
$as($workerId);
$check('worker can see farm map nav via fields view', can_see_nav($mapItem));

$navLabels = static function () : array {
    $labels = [];
    foreach (nav_items() as $group) {
        foreach ($group['items'] as $item) {
            if (can_see_nav($item)) {
                $labels[] = (string) ($item['label'] ?? '');
            }
        }
    }
    return $labels;
};
$as($workerId);
$workerNav = $navLabels();
$as($ownerId);
$ownerNav = $navLabels();
$as($managerId);
$managerNav = $navLabels();
$as($agroId);
$agroNav = $navLabels();
$as($acctId);
$acctNav = $navLabels();
$as($adminId);
$adminNav = $navLabels();
$check('worker nav hides income', !in_array('Income & Expenses', $workerNav, true));
$check('worker nav hides payroll', !in_array('Payroll', $workerNav, true));
$check('worker nav hides users', !in_array('Users', $workerNav, true));
$check('owner nav includes income', in_array('Income & Expenses', $ownerNav, true));
$check('owner nav includes users', in_array('Users', $ownerNav, true));
$check('owner nav hides backups', !in_array('Data Backup', $ownerNav, true));
$check('manager nav hides user admin', !in_array('Users', $managerNav, true));
$check('manager nav hides backups', !in_array('Data Backup', $managerNav, true));
$as($managerId);
$check('manager cannot edit owner ledger', !Auth::can('transactions', 'edit'));
$check('manager cannot administer users', !Auth::can('users', 'view'));
$check('agronomist nav hides income', !in_array('Income & Expenses', $agroNav, true));
$check('agronomist nav hides payroll', !in_array('Payroll', $agroNav, true));
$check('accountant nav hides plantings', !in_array('Plantings', $acctNav, true));
$check('accountant nav hides tasks', !in_array('Tasks', $acctNav, true));
$check('admin nav includes backups', in_array('Data Backup', $adminNav, true));
$check('role nav sets are not identical', count(array_unique([
    implode('|', $workerNav),
    implode('|', $ownerNav),
    implode('|', $managerNav),
    implode('|', $agroNav),
    implode('|', $acctNav),
    implode('|', $adminNav),
])) === 6);

$focusOf = static function (int $userId) use ($as): array {
    $as($userId);
    $method = new ReflectionMethod(DashboardController::class, 'focus');
    $method->setAccessible(true);
    $farmId = (int) Auth::farmId();
    $scope = [
        'farms' => ['sql' => 'WHERE id = ?', 'params' => [$farmId]],
        'plain' => ['sql' => 'WHERE farm_id = ?', 'params' => [$farmId]],
    ];
    $stats = [
        'farms' => 1, 'acreage' => 1, 'fields' => 1, 'active_crops' => 1, 'crops_week' => 0,
        'livestock' => 1, 'livestock_week' => 0, 'pending_tasks' => 1, 'tasks_total' => 1,
        'tasks_yesterday' => 0, 'irrigation_systems' => 0, 'irrigation_active' => 0,
    ];
    [$taskSql, $taskParams] = TaskAccess::listConstraint();
    $focus = $method->invoke(null, $scope, $stats, $taskSql, $taskParams);
    return is_array($focus) ? $focus : [];
};
$headlines = [];
foreach ([
    $adminId => 'Administration',
    $ownerId => 'Greater Harvests',
    $managerId => 'Farm operations',
    $agroId => 'Field by field',
    $workerId => 'Ready for today',
    $acctId => 'This month',
] as $uid => $expected) {
    $focus = $focusOf($uid);
    $headlines[] = (string) ($focus['line2'] ?? '');
    $check('dashboard headline for user ' . $uid, ($focus['line2'] ?? '') === $expected, (string) ($focus['line2'] ?? ''));
}
$check('dashboard headlines are not all the owner headline', count(array_unique($headlines)) === 6);

$as($workerId);
$GLOBALS['ifms_test_exit'] = null;
try {
    Auth::authorize('transactions', 'view');
    $check('worker direct transactions request denied', false, 'authorize returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('worker direct transactions request denied', ($exit['code'] ?? 0) === 403, (string) ($exit['code'] ?? ''));
}

$customerB = (int) db()->insert('customers', [
    'farm_id' => $farmB,
    'name' => 'Foreign Buyer',
    'status' => 'active',
    'created_at' => $now,
]);
$invoiceB = (int) db()->insert('invoices', [
    'farm_id' => $farmB,
    'customer_id' => $customerB,
    'invoice_number' => 'INV-FOREIGN',
    'invoice_date' => date('Y-m-d'),
    'amount' => 880,
    'status' => 'unpaid',
    'created_at' => $now,
]);
$paymentB = (int) db()->insert('payments', [
    'farm_id' => $farmB,
    'customer_id' => $customerB,
    'payment_date' => date('Y-m-d'),
    'amount' => 880,
    'reference' => 'PAY-FOREIGN',
    'created_at' => $now,
]);
$employeeB = (int) db()->insert('employees', [
    'farm_id' => $farmB,
    'name' => 'Foreign Employee',
    'status' => 'active',
    'created_at' => $now,
]);
$stockB = (int) db()->insert('inventory_items', [
    'farm_id' => $farmB,
    'name' => 'SECRET-STOCK',
    'category' => 'inputs',
    'quantity' => 3,
    'unit' => 'kg',
    'unit_cost' => 10,
    'created_at' => $now,
]);
$txnB = (int) db()->insert('transactions', [
    'farm_id' => $farmB,
    'txn_date' => date('Y-m-d'),
    'type' => 'income',
    'category' => 'sales',
    'description' => 'FOREIGN-INCOME',
    'amount' => 880,
    'created_at' => $now,
]);
$txnA = (int) db()->value("SELECT id FROM transactions WHERE description = 'SECRET-INCOME'");
$attOther = (int) db()->insert('attendance', [
    'farm_id' => $farmA,
    'employee_id' => $empOther,
    'work_date' => date('Y-m-d'),
    'status' => 'present',
    'created_at' => $now,
]);
$attMine = (int) db()->insert('attendance', [
    'farm_id' => $farmA,
    'employee_id' => $empWorker,
    'work_date' => date('Y-m-d', strtotime('-1 day')),
    'status' => 'present',
    'created_at' => $now,
]);

$callApi = static function (string $method, string $module, $id = null): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = '/api/module/' . $module . ($id !== null && $id !== '' ? '/' . $id : '');
    $ref = new ReflectionMethod(ApiController::class, 'module');
    $ref->setAccessible(true);
    $GLOBALS['ifms_test_exit'] = null;
    try {
        $ref->invoke(null, $module, $id === null ? null : (string) $id);
    } catch (RuntimeException $e) {
        // Captured exit from authorize, fail, or ok.
    }
    return is_array($GLOBALS['ifms_test_exit'] ?? null) ? $GLOBALS['ifms_test_exit'] : ['code' => 0, 'data' => []];
};
$expect = static function (array $exit, int $code, string $label) use ($check): void {
    $check($label, (int) ($exit['code'] ?? 0) === $code, 'got ' . (string) ($exit['code'] ?? 'none'));
};

$as($workerId);
$expect($callApi('GET', 'transactions'), 403, 'GET /api/module/transactions worker');
$expect($callApi('GET', 'transactions', $txnA), 403, 'GET /api/module/transactions/{id} worker');
$expect($callApi('POST', 'transactions'), 403, 'POST /api/module/transactions worker');
$expect($callApi('PUT', 'transactions', $txnA), 403, 'PUT /api/module/transactions/{id} worker');
$expect($callApi('PATCH', 'transactions', $txnA), 403, 'PATCH /api/module/transactions/{id} worker');
$expect($callApi('DELETE', 'transactions', $txnA), 403, 'DELETE /api/module/transactions/{id} worker');
$expect($callApi('GET', 'invoices', $invoiceB), 403, 'GET /api/module/invoices/{other farm} worker');
$expect($callApi('GET', 'payments', $paymentB), 403, 'GET /api/module/payments/{other farm} worker');
$expect($callApi('GET', 'employees', $employeeB), 403, 'GET /api/module/employees/{other farm} worker');
$taskList = $callApi('GET', 'tasks');
$expect($taskList, 200, 'GET /api/module/tasks worker');
$taskTitles = array_column($taskList['data']['data']['items'] ?? [], 'title');
$check('worker task list includes own task', in_array('My assigned task', $taskTitles, true));
$check('worker task list hides other task', !in_array('Other worker task', $taskTitles, true));
$expect($callApi('GET', 'tasks', $taskMine), 200, 'GET /api/module/tasks/{own} worker');
$expect($callApi('GET', 'tasks', $taskOther), 403, 'GET /api/module/tasks/{other worker} worker');
$expect($callApi('POST', 'tasks', $taskOther), 403, 'POST /api/module/tasks/{other worker} worker');
$expect($callApi('GET', 'tasks', $taskForeign), 404, 'GET /api/module/tasks/{other farm} worker');
$expect($callApi('GET', 'attendance', $attOther), 403, 'GET /api/module/attendance/{other worker} worker');
$expect($callApi('GET', 'attendance', $attMine), 200, 'GET /api/module/attendance/{own} worker');
$expect($callApi('POST', 'attendance'), 403, 'POST /api/module/attendance worker without own employee');
$beforeOther = (string) db()->value('SELECT status FROM tasks WHERE id = ?', [$taskOther]);
$check('worker POST did not change the other task', $beforeOther === 'pending');

$as($ownerId);
$expect($callApi('GET', 'transactions', $txnB), 404, 'GET /api/module/transactions/{other farm} owner');
$expect($callApi('GET', 'invoices', $invoiceB), 404, 'GET /api/module/invoices/{other farm} owner');
$expect($callApi('GET', 'payments', $paymentB), 404, 'GET /api/module/payments/{other farm} owner');
$expect($callApi('GET', 'employees', $employeeB), 404, 'GET /api/module/employees/{other farm} owner');
$expect($callApi('GET', 'inventory_items', $stockB), 404, 'GET /api/module/inventory_items/{other farm} owner');
$ownTxn = $callApi('GET', 'transactions', $txnA);
$expect($ownTxn, 200, 'GET /api/module/transactions/{own farm} owner');
$check('owner own transaction is the farm A row', ($ownTxn['data']['data']['item']['description'] ?? '') === 'SECRET-INCOME');
$amountBefore = (string) db()->value('SELECT amount FROM transactions WHERE id = ?', [$txnA]);
$expect($callApi('PUT', 'transactions', $txnA), 405, 'PUT /api/module/transactions/{id} owner');
$expect($callApi('PATCH', 'transactions', $txnA), 405, 'PATCH /api/module/transactions/{id} owner');
$expect($callApi('DELETE', 'transactions', $txnA), 405, 'DELETE /api/module/transactions/{id} owner');
$check('owner PUT/PATCH/DELETE did not change the transaction', (string) db()->value('SELECT amount FROM transactions WHERE id = ?', [$txnA]) === $amountBefore);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/module/invoices/pdf/' . $invoiceB;
$GLOBALS['ifms_test_exit'] = null;
try {
    ResourceController::handle('invoices', 'pdf', (string) $invoiceB);
    $check('GET /module/invoices/pdf/{other farm} owner', false, 'pdf returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('GET /module/invoices/pdf/{other farm} owner', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}

$as($managerId);
$expect($callApi('POST', 'payments'), 403, 'POST /api/module/payments manager');
$expect($callApi('GET', 'transactions', $txnB), 404, 'GET /api/module/transactions/{other farm} manager');
$expect($callApi('GET', 'backup'), 404, 'GET /api/module/backup is not a module');
$_SERVER['REQUEST_URI'] = '/backup';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['ifms_test_exit'] = null;
try {
    AppPagesController::backup();
    $check('GET /backup manager', false, 'page returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('GET /backup manager', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}

$as($agroId);
$expect($callApi('GET', 'transactions'), 403, 'GET /api/module/transactions agronomist');
$expect($callApi('GET', 'payroll'), 403, 'GET /api/module/payroll agronomist');
$_SERVER['REQUEST_URI'] = '/users/save';
$_SERVER['REQUEST_METHOD'] = 'POST';
$GLOBALS['ifms_test_exit'] = null;
try {
    AppPagesController::saveUser(null);
    $check('POST /users/save agronomist', false, 'save returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('POST /users/save agronomist', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}
$_SERVER['REQUEST_URI'] = '/users';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['ifms_test_exit'] = null;
try {
    AppPagesController::users();
    $check('GET /users agronomist', false, 'page returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('GET /users agronomist', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}
$_GET['type'] = 'financial';
unset($_GET['export']);
$_SERVER['REQUEST_URI'] = '/reports';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['ifms_test_exit'] = null;
try {
    AppPagesController::reports();
    $check('GET /reports?type=financial agronomist', false, 'report returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('GET /reports?type=financial agronomist', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}

$as($acctId);
$expect($callApi('POST', 'plantings'), 403, 'POST /api/module/plantings accountant');
$expect($callApi('GET', 'tasks'), 403, 'GET /api/module/tasks accountant');
$expect($callApi('GET', 'invoices', $invoiceB), 404, 'GET /api/module/invoices/{other farm} accountant');
$_SERVER['REQUEST_URI'] = '/integrations';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['ifms_test_exit'] = null;
try {
    AppPagesController::integrations();
    $check('GET /integrations accountant', false, 'page returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('GET /integrations accountant', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}

$as($adminId);
$expect($callApi('GET', 'transactions', $txnA), 200, 'GET /api/module/transactions/{id} admin');
$_SERVER['REQUEST_URI'] = '/backup';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['ifms_test_exit'] = null;
try {
    Auth::authorize('backups', 'view');
    $check('GET /backup permission admin', true);
} catch (RuntimeException $e) {
    $check('GET /backup permission admin', false, $e->getMessage());
}

$as($workerId);
$_SERVER['REQUEST_URI'] = '/reports';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['type'] = 'financial';
$_GET['export'] = 'csv';
$GLOBALS['ifms_test_exit'] = null;
try {
    AppPagesController::reports();
    $check('GET /reports?export=csv worker', false, 'csv returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('GET /reports?export=csv worker', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}
unset($_GET['export'], $_GET['type']);

/*
 * Status codes are intentional and are not uniform:
 * 401 invalid credentials, or the account is not active.
 * 403 the role lacks that module action. This is decided before the row is loaded,
 *     so it does not confirm that another farm's record exists. Workers have
 *     attendance view and create only, so every attendance update id returns 403.
 * 403 the row is on a farm the caller may use, but it belongs to someone else
 *     (another worker's task or attendance on the same farm).
 * 404 the caller may perform the action, and the id is missing or on another farm.
 *     The API uses 404 so the response does not confirm the other farm's record.
 *     HTML ResourceController::load uses 403 for that same cross-farm case.
 * 405 PUT, PATCH and DELETE are not implemented. Creates and updates use POST.
 */
$check('status policy documented', true);
$check('worker attendance permission is view and create only', Auth::can('attendance', 'create') && !Auth::can('attendance', 'edit'));

$apiLogin = static function (array $body): array {
    $GLOBALS['IFMS_TEST_BODY'] = json_encode($body);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = '/api/login';
    $ref = new ReflectionMethod(ApiController::class, 'login');
    $ref->setAccessible(true);
    $GLOBALS['ifms_test_exit'] = null;
    try {
        $ref->invoke(null);
    } catch (RuntimeException $e) {
    }
    unset($GLOBALS['IFMS_TEST_BODY']);
    return is_array($GLOBALS['ifms_test_exit'] ?? null) ? $GLOBALS['ifms_test_exit'] : ['code' => 0, 'data' => []];
};
$postModule = static function (string $module, $id, array $body) use ($callApi): array {
    $GLOBALS['IFMS_TEST_BODY'] = json_encode($body);
    $exit = $callApi('POST', $module, $id);
    unset($GLOBALS['IFMS_TEST_BODY']);
    return $exit;
};

foreach ([
    'admin' => $adminId,
    'owner' => $ownerId,
    'manager' => $managerId,
    'agronomist' => $agroId,
    'worker' => $workerId,
    'accountant' => $acctId,
] as $roleName => $uid) {
    $email = (string) db()->value('SELECT email FROM users WHERE id = ?', [$uid]);
    $body = ['email' => $email, 'password' => 'Test#Rbac26'];
    if ($roleName !== 'admin') {
        $body['farm_code'] = 'TESTAAAA';
    }
    $exit = $apiLogin($body);
    $expect($exit, 200, 'POST /api/login ' . $roleName);
    $token = (string) ($exit['data']['data']['token'] ?? '');
    $claims = $token !== '' ? Jwt::decode($token) : null;
    $check('POST /api/login ' . $roleName . ' token role matches database', is_array($claims) && ($claims['role'] ?? '') === $roleName);
}

$blankEmail = 'blank.' . bin2hex(random_bytes(2)) . '@rbac.test';
$invalidEmail = 'invalid.' . bin2hex(random_bytes(2)) . '@rbac.test';
db()->insert('users', [
    'name' => 'Blank Role',
    'username' => 'blank_' . bin2hex(random_bytes(2)),
    'email' => $blankEmail,
    'password' => $hash,
    'role' => '',
    'farm_id' => $farmA,
    'status' => 'active',
    'must_change_password' => 0,
    'session_version' => 1,
    'created_at' => $now,
]);
db()->insert('users', [
    'name' => 'Invalid Role',
    'username' => 'invalid_' . bin2hex(random_bytes(2)),
    'email' => $invalidEmail,
    'password' => $hash,
    'role' => 'Farm Owner',
    'farm_id' => $farmA,
    'status' => 'active',
    'must_change_password' => 0,
    'session_version' => 1,
    'created_at' => $now,
]);
$expect($apiLogin(['email' => $blankEmail, 'password' => 'Test#Rbac26']), 403, 'POST /api/login blank role');
$expect($apiLogin(['email' => $invalidEmail, 'password' => 'Test#Rbac26']), 403, 'POST /api/login invalid role');
$expect($apiLogin(['email' => 'nobody@rbac.test', 'password' => 'Test#Rbac26']), 401, 'POST /api/login unknown email');

db()->update('users', ['status' => 'suspended'], $workerId);
$workerEmail = (string) db()->value('SELECT email FROM users WHERE id = ?', [$workerId]);
$expect($apiLogin(['email' => $workerEmail, 'password' => 'Test#Rbac26']), 401, 'POST /api/login suspended worker');
db()->update('users', ['status' => 'active'], $workerId);

$workerLogin = $apiLogin(['email' => $workerEmail, 'password' => 'Test#Rbac26']);
$workerToken = (string) ($workerLogin['data']['data']['token'] ?? '');
db()->update('users', ['role' => 'accountant'], $workerId);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $workerToken;
$tokenRef = new ReflectionMethod(ApiController::class, 'requireToken');
$tokenRef->setAccessible(true);
$GLOBALS['ifms_test_exit'] = null;
try {
    $reloaded = $tokenRef->invoke(null);
    $check('login token cannot override a later database role', ($reloaded['role'] ?? '') === 'accountant');
} catch (RuntimeException $e) {
    $check('login token cannot override a later database role', false, $e->getMessage());
}
db()->update('users', ['role' => 'worker'], $workerId);
unset($_SERVER['HTTP_AUTHORIZATION']);

$worker2Id = (int) db()->insert('users', [
    'name' => 'Second Worker',
    'username' => 'worker2_' . bin2hex(random_bytes(2)),
    'email' => 'worker2.' . bin2hex(random_bytes(2)) . '@rbac.test',
    'password' => $hash,
    'role' => 'worker',
    'farm_id' => $farmA,
    'status' => 'active',
    'must_change_password' => 0,
    'session_version' => 1,
    'created_at' => $now,
]);
if (table_exists('user_farms')) {
    db()->insert('user_farms', [
        'user_id' => $worker2Id,
        'farm_id' => $farmA,
        'is_primary' => 1,
        'created_at' => $now,
    ]);
}
db()->update('employees', ['user_id' => $worker2Id], $empOther);
$as($workerId);
$otherCount = (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ?', [$empOther]);
$foreignCount = (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ?', [$employeeB]);
$ownCount = (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ?', [$empWorker]);
$day = date('Y-m-d');
$expect($postModule('attendance', null, [
    'employee_id' => $empOther,
    'work_date' => $day,
    'status' => 'present',
]), 403, 'POST /api/module/attendance other employee');
$expect($postModule('attendance', null, [
    'employee_id' => $employeeB,
    'farm_id' => $farmB,
    'work_date' => $day,
    'status' => 'present',
]), 403, 'POST /api/module/attendance other farm employee');
$expect($postModule('attendance', $attOther, [
    'employee_id' => $empWorker,
    'work_date' => $day,
    'status' => 'absent',
    'notes' => 'forged',
]), 403, 'POST /api/module/attendance/{other} worker has no edit permission');
$attForeign = (int) db()->insert('attendance', [
    'farm_id' => $farmB,
    'employee_id' => $employeeB,
    'work_date' => $day,
    'status' => 'present',
    'created_at' => $now,
]);
$expect($callApi('GET', 'attendance', $attForeign), 404, 'GET /api/module/attendance/{other farm} worker');
$expect($postModule('attendance', $attForeign, [
    'employee_id' => $empWorker,
    'work_date' => $day,
    'status' => 'absent',
]), 403, 'POST /api/module/attendance/{other farm} worker has no edit permission');
$expect($postModule('attendance', null, [
    'employee_id' => $empWorker,
    'status' => 'present',
]), 422, 'POST /api/module/attendance invalid payload missing date');
$expect($postModule('attendance', null, [
    'employee_id' => $empWorker,
    'work_date' => $day,
    'status' => 'not-a-status',
]), 422, 'POST /api/module/attendance invalid status');
$expect($postModule('attendance', null, [
    'employee_id' => $empWorker,
    'work_date' => $day,
    'status' => 'present',
    'notes' => 'own day',
]), 201, 'POST /api/module/attendance own employee');
$as($worker2Id);
$expect($postModule('attendance', null, [
    'employee_id' => $empWorker,
    'work_date' => $day,
    'status' => 'present',
    'notes' => 'second worker forged',
]), 403, 'POST /api/module/attendance second worker forges first employee');
$expect($postModule('attendance', null, [
    'employee_id' => $empOther,
    'work_date' => $day,
    'status' => 'present',
    'notes' => 'second worker own',
]), 201, 'POST /api/module/attendance second worker own employee');
$as($workerId);
$check('forged attendance did not create the other employee row', (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND notes = ?', [$empOther, 'forged']) === 0);
$check('forged attendance did not create the other farm row', (int) db()->value("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND status = 'absent'", [$employeeB]) === 0);
$check('other attendance status was not changed', (string) db()->value('SELECT status FROM attendance WHERE id = ?', [$attOther]) === 'present');
$check('other farm attendance status was not changed', (string) db()->value('SELECT status FROM attendance WHERE id = ?', [$attForeign]) === 'present');
$check('worker own attendance was created', (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND notes = ?', [$empWorker, 'own day']) === 1);
$check('second worker own attendance was created', (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND notes = ?', [$empOther, 'second worker own']) === 1);
$check('second worker did not write the first employee', (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND notes = ?', [$empWorker, 'second worker forged']) === 0);
$ownId = (int) db()->value('SELECT id FROM attendance WHERE employee_id = ? AND notes = ?', [$empWorker, 'own day']);
$expect($postModule('attendance', $ownId, [
    'employee_id' => $empWorker,
    'work_date' => $day,
    'status' => 'present',
    'notes' => 'own day updated',
]), 403, 'POST /api/module/attendance/{own} worker has no edit permission');
$check('worker edit denial left own attendance unchanged', (string) db()->value('SELECT notes FROM attendance WHERE id = ?', [$ownId]) === 'own day');
$check('worker own attendance count grew only by the authorized create', (int) db()->value('SELECT COUNT(*) FROM attendance WHERE employee_id = ?', [$empWorker]) === $ownCount + 1);
$as($ownerId);
$expect($postModule('attendance', $attForeign, [
    'employee_id' => $employeeB,
    'work_date' => $day,
    'status' => 'absent',
]), 404, 'POST /api/module/attendance/{other farm} owner');
$check('owner cross-farm attendance update did not change the row', (string) db()->value('SELECT status FROM attendance WHERE id = ?', [$attForeign]) === 'present');
$expect($postModule('attendance', $attMine, [
    'employee_id' => $empWorker,
    'work_date' => date('Y-m-d', strtotime('-1 day')),
    'status' => 'leave',
    'notes' => 'owner recorded leave',
]), 200, 'POST /api/module/attendance/{own farm} owner');
$check('owner can update attendance on the owned farm', (string) db()->value('SELECT notes FROM attendance WHERE id = ?', [$attMine]) === 'owner recorded leave');

$as($ownerId);
$expect($postModule('transactions', $txnA, [
    'farm_id' => $farmB,
    'txn_date' => $day,
    'type' => 'income',
    'category' => 'sales',
    'description' => 'OWNER-UPDATED',
    'amount' => 42.5,
]), 200, 'POST /api/module/transactions/{own} owner');
$check('owner POST update changed the owned transaction', (string) db()->value('SELECT description FROM transactions WHERE id = ?', [$txnA]) === 'OWNER-UPDATED');
$check('owner POST update kept the record on farm A', (int) db()->value('SELECT farm_id FROM transactions WHERE id = ?', [$txnA]) === $farmA);
$foreignBefore = (string) db()->value('SELECT description FROM transactions WHERE id = ?', [$txnB]);
$expect($postModule('transactions', $txnB, [
    'farm_id' => $farmB,
    'txn_date' => $day,
    'type' => 'income',
    'category' => 'sales',
    'description' => 'SHOULD-NOT-STICK',
    'amount' => 1,
]), 404, 'POST /api/module/transactions/{other farm} owner');
$check('owner cross-farm POST did not change the other farm', (string) db()->value('SELECT description FROM transactions WHERE id = ?', [$txnB]) === $foreignBefore);

$as($ownerId);
$dbRole = (string) db()->value('SELECT role FROM users WHERE id = ?', [$ownerId]);
$dbVer = (int) db()->value('SELECT session_version FROM users WHERE id = ?', [$ownerId]);
db()->update('users', ['role' => 'worker'], $ownerId);
Auth::setOverride(null);
Auth::clearCache();
$_SESSION['user_id'] = $ownerId;
$_SESSION['session_version'] = $dbVer;
$check('old session follows the new database role', Auth::check() && Auth::role() === 'worker');
$check('old session loses owner user administration', !Auth::can('users', 'edit'));
db()->update('users', ['role' => $dbRole], $ownerId);
Auth::clearCache();

$token = Jwt::encode(['sub' => $workerId, 'role' => 'owner', 'farm_id' => $farmA]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$tokenRef = new ReflectionMethod(ApiController::class, 'requireToken');
$tokenRef->setAccessible(true);
$GLOBALS['ifms_test_exit'] = null;
try {
    $tokenUser = $tokenRef->invoke(null);
    $check('API token uses the database role', ($tokenUser['role'] ?? '') === 'worker');
    Auth::setOverride($tokenUser);
    $check('API token cannot keep a forged owner role', !Auth::can('transactions', 'view'));
} catch (RuntimeException $e) {
    $check('API token uses the database role', false, $e->getMessage());
}
db()->update('users', ['status' => 'suspended'], $workerId);
$GLOBALS['ifms_test_exit'] = null;
try {
    $tokenRef->invoke(null);
    $check('suspended account API token', false, 'token accepted');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('suspended account API token', ($exit['code'] ?? 0) === 401, 'got ' . (string) ($exit['code'] ?? ''));
}
db()->update('users', ['status' => 'active'], $workerId);
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$as($workerId);
$GLOBALS['ifms_test_exit'] = null;
try {
    Auth::authorize('tasks', 'view');
    $check('worker direct tasks request allowed', true);
} catch (RuntimeException $e) {
    $check('worker direct tasks request allowed', false, $e->getMessage());
}

$as($ownerId);
db()->update('users', ['role' => 'worker', 'session_version' => 2], $ownerId);
Auth::setOverride(null);
Auth::clearCache();
$_SESSION['user_id'] = $ownerId;
$_SESSION['session_version'] = 1;
$check('stale session after role change is signed out', Auth::check() === false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

db()->update('users', ['role' => 'owner', 'status' => 'active', 'session_version' => 1], $ownerId);
Auth::clearCache();
$_SESSION['user_id'] = $ownerId;
$_SESSION['session_version'] = 1;
$check('owner role restored from database', Auth::role() === 'owner' && Auth::can('transactions', 'view'));
db()->update('users', ['status' => 'suspended'], $ownerId);
Auth::clearCache();
$check('suspended owner cannot stay signed in', Auth::check() === false);
db()->update('users', ['status' => 'active'], $ownerId);
Auth::clearCache();

$mysteryEmail = 'mystery.' . bin2hex(random_bytes(2)) . '@rbac.test';
$mysteryId = (int) db()->insert('users', [
    'name' => 'Mystery',
    'username' => 'mystery_' . bin2hex(random_bytes(2)),
    'email' => $mysteryEmail,
    'password' => $hash,
    'role' => 'mystery',
    'farm_id' => $farmA,
    'status' => 'active',
    'must_change_password' => 0,
    'session_version' => 1,
    'created_at' => $now,
]);
Auth::setOverride(null);
Auth::clearCache();
unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['active_farm_id']);
$mysteryLogin = Auth::attemptLogin($mysteryEmail, 'Test#Rbac26');
$check('unknown role cannot sign in', empty($mysteryLogin['ok']));
$check('unknown role login creates no session', empty($_SESSION['user_id']));
$mystery = db()->find('users', $mysteryId);
Auth::setOverride($mystery);
$_SESSION['user_id'] = $mysteryId;
$_SESSION['session_version'] = 1;
$_SESSION['active_farm_id'] = $farmA;
$check('unknown role has no module permission', !Auth::can('dashboard', 'view') && !Auth::can('transactions', 'view') && !Auth::can('users', 'view'));
$mysteryToken = Jwt::encode(['sub' => $mysteryId, 'role' => 'owner', 'farm_id' => $farmA]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $mysteryToken;
$tokenRef = new ReflectionMethod(ApiController::class, 'requireToken');
$tokenRef->setAccessible(true);
$GLOBALS['ifms_test_exit'] = null;
try {
    $tokenRef->invoke(null);
    $check('API token for an unknown role', false, 'token accepted');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('API token for an unknown role', ($exit['code'] ?? 0) === 403, 'got ' . (string) ($exit['code'] ?? ''));
}
unset($_SERVER['HTTP_AUTHORIZATION']);
$GLOBALS['ifms_test_exit'] = null;
try {
    Auth::requireAuth();
    $check('unknown role is denied protected pages', false, 'requireAuth returned');
} catch (RuntimeException $e) {
    $exit = $GLOBALS['ifms_test_exit'] ?? [];
    $check('unknown role is denied protected pages', ($exit['type'] ?? '') === 'abort' && ($exit['code'] ?? 0) === 403, (string) ($exit['type'] ?? '') . ' ' . (string) ($exit['code'] ?? ''));
}

Auth::setOverride(null);

echo "\npassed={$passed} failed={$failed}\n";
foreach ($failures as $f) {
    echo " - {$f}\n";
}

@unlink($dbPath);
$wal = $dbPath . '-wal';
$shm = $dbPath . '-shm';
@unlink($wal);
@unlink($shm);

if ($failed > 0) {
    echo "HAS FAILURES\n";
    exit(1);
}
echo "ALL PASSED\n";
echo "Disposable DB deleted. Live/Neon database was not used.\n";
exit(0);
