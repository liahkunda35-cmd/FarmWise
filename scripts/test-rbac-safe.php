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
