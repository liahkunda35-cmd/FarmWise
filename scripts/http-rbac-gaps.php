<?php
declare(strict_types=1);

/**
 * HTTP integration for the remaining RBAC gaps.
 * Starts a temporary PHP server on 127.0.0.1 against a disposable SQLite file.
 * Does not use Neon and does not set IFMS_TEST_CAPTURE on the server, so
 * POST bodies are read from the real request.
 *
 * Run: C:\xampp\php\php.exe scripts\http-rbac-gaps.php
 */

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$port = 8097;
$tempDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'farmms-rbac-http';
if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "Cannot create temp dir\n");
    exit(1);
}
$dbPath = $tempDir . DIRECTORY_SEPARATOR . 'http-' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($dbPath);

putenv('IFMS_DB_DRIVER=sqlite');
putenv('IFMS_SQLITE_PATH=' . $dbPath);
putenv('IFMS_TEST_CAPTURE=1');
$_ENV['IFMS_DB_DRIVER'] = 'sqlite';
$_ENV['IFMS_SQLITE_PATH'] = $dbPath;
$_ENV['IFMS_TEST_CAPTURE'] = '1';
$_SERVER['IFMS_DB_DRIVER'] = 'sqlite';
$_SERVER['IFMS_SQLITE_PATH'] = $dbPath;
$_SERVER['IFMS_TEST_CAPTURE'] = '1';
foreach (['DATABASE_URL', 'DATABASE_URL_POOLED', 'DATABASE_URL_UNPOOLED', 'IFMS_DATABASE_URL'] as $k) {
    putenv($k . '=');
    $_ENV[$k] = '';
    $_SERVER[$k] = '';
}

require $root . '/backend/bootstrap.php';

$failed = 0;
$passed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "OK  {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

$check('http fixture driver is sqlite', db()->driver() === 'sqlite', db()->driver());
if ($failed > 0) {
    echo "Aborting — fixture database is not disposable sqlite.\n";
    exit(1);
}

$now = date('Y-m-d H:i:s');
$day = date('Y-m-d');
$hash = password_hash('Test#Rbac26', PASSWORD_DEFAULT);
$farmA = (int) db()->insert('farms', [
    'name' => 'HTTP Farm A',
    'location' => 'Lusaka',
    'total_area' => 10,
    'status' => 'active',
    'join_code' => 'HTTPAAAA',
    'created_at' => $now,
]);
$farmB = (int) db()->insert('farms', [
    'name' => 'HTTP Farm B',
    'location' => 'Kafue',
    'total_area' => 8,
    'status' => 'active',
    'join_code' => 'HTTPBBBB',
    'created_at' => $now,
]);
$mkUser = static function (string $name, string $role, ?int $farmId, string $status = 'active') use ($hash, $now): array {
    $email = strtolower($role) . '.' . bin2hex(random_bytes(3)) . '@http.test';
    $id = (int) db()->insert('users', [
        'name' => $name,
        'username' => strtolower($role) . '_http_' . bin2hex(random_bytes(2)),
        'email' => $email,
        'password' => $hash,
        'role' => $role,
        'farm_id' => $farmId,
        'status' => $status,
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
    return ['id' => $id, 'email' => $email, 'role' => $role];
};

$users = [
    'admin' => $mkUser('Admin Http', 'admin', null),
    'owner' => $mkUser('Owner Http', 'owner', $farmA),
    'manager' => $mkUser('Manager Http', 'manager', $farmA),
    'agronomist' => $mkUser('Agro Http', 'agronomist', $farmA),
    'worker' => $mkUser('Worker Http', 'worker', $farmA),
    'accountant' => $mkUser('Acct Http', 'accountant', $farmA),
];
$worker2 = $mkUser('Worker Two', 'worker', $farmA);
$blank = $mkUser('Blank Role', 'worker', $farmA);
$invalid = $mkUser('Invalid Role', 'worker', $farmA);
$suspended = $mkUser('Suspended Worker', 'worker', $farmA, 'suspended');
db()->update('users', ['role' => ''], $blank['id']);
db()->update('users', ['role' => 'Farm Owner'], $invalid['id']);
db()->update('farms', ['owner_id' => $users['owner']['id']], $farmA);

$empWorker = (int) db()->insert('employees', [
    'farm_id' => $farmA,
    'user_id' => $users['worker']['id'],
    'name' => 'HTTP Worker Emp',
    'status' => 'active',
    'created_at' => $now,
]);
$empOther = (int) db()->insert('employees', [
    'farm_id' => $farmA,
    'user_id' => $worker2['id'],
    'name' => 'HTTP Other Emp',
    'status' => 'active',
    'created_at' => $now,
]);
$employeeB = (int) db()->insert('employees', [
    'farm_id' => $farmB,
    'name' => 'HTTP Foreign Emp',
    'status' => 'active',
    'created_at' => $now,
]);
$attOther = (int) db()->insert('attendance', [
    'farm_id' => $farmA,
    'employee_id' => $empOther,
    'work_date' => $day,
    'status' => 'present',
    'created_at' => $now,
]);
$attForeign = (int) db()->insert('attendance', [
    'farm_id' => $farmB,
    'employee_id' => $employeeB,
    'work_date' => $day,
    'status' => 'present',
    'created_at' => $now,
]);
$txnA = (int) db()->insert('transactions', [
    'farm_id' => $farmA,
    'txn_date' => $day,
    'type' => 'income',
    'category' => 'sales',
    'description' => 'HTTP-INCOME',
    'amount' => 25,
    'created_at' => $now,
]);
$txnB = (int) db()->insert('transactions', [
    'farm_id' => $farmB,
    'txn_date' => $day,
    'type' => 'income',
    'category' => 'sales',
    'description' => 'HTTP-FOREIGN',
    'amount' => 80,
    'created_at' => $now,
]);

db()->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
Database::close();

$env = getenv();
if (!is_array($env)) {
    $env = [];
}
foreach ($_SERVER as $key => $value) {
    if (is_string($key) && is_string($value) && !array_key_exists($key, $env)) {
        $env[$key] = $value;
    }
}
$env['IFMS_DB_DRIVER'] = 'sqlite';
$env['IFMS_SQLITE_PATH'] = $dbPath;
$env['IFMS_TEST_CAPTURE'] = '0';
foreach (['DATABASE_URL', 'DATABASE_URL_POOLED', 'DATABASE_URL_UNPOOLED', 'IFMS_DATABASE_URL', 'GEMINI_API_KEY', 'OPENWEATHER_API_KEY'] as $k) {
    $env[$k] = '';
}

$cmd = '"' . $php . '" -S 127.0.0.1:' . $port . ' router.php';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $descriptors, $pipes, $root, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start disposable HTTP server\n");
    exit(1);
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$base = 'http://127.0.0.1:' . $port;
$ready = false;
$pingCtx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
for ($i = 0; $i < 30; $i++) {
    $ping = @file_get_contents($base . '/', false, $pingCtx);
    $status = (string) ($http_response_header[0] ?? '');
    if (is_string($ping) && $status !== '') {
        $ready = true;
        break;
    }
    usleep(200000);
}
$check('disposable HTTP server started', $ready, 'no response from ' . $base);

$http = static function (string $method, string $path, ?array $json = null, ?string $token = null) use ($base): array {
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    return ['code' => $code, 'json' => is_array($decoded) ? $decoded : [], 'err' => $err, 'raw' => is_string($body) ? $body : ''];
};

$tokens = [];
if ($ready) {
    foreach ($users as $role => $user) {
        $loginBody = ['email' => $user['email'], 'password' => 'Test#Rbac26'];
        if ($role !== 'admin') {
            $loginBody['farm_code'] = 'HTTPAAAA';
        }
        $res = $http('POST', '/api/login', $loginBody);
        $token = (string) ($res['json']['data']['token'] ?? '');
        $tokens[$role] = $token;
        $claims = $token !== '' ? Jwt::decode($token) : null;
        $check('HTTP POST /api/login ' . $role, $res['code'] === 200 && ($res['json']['data']['user']['role'] ?? '') === $role, 'got ' . $res['code'] . ' ' . $res['err']);
        $check('HTTP login token role matches ' . $role, is_array($claims) && ($claims['role'] ?? '') === $role);
    }
    $check('HTTP POST /api/login blank role', $http('POST', '/api/login', ['email' => $blank['email'], 'password' => 'Test#Rbac26'])['code'] === 403);
    $check('HTTP POST /api/login invalid role', $http('POST', '/api/login', ['email' => $invalid['email'], 'password' => 'Test#Rbac26'])['code'] === 403);
    $check('HTTP POST /api/login unknown email', $http('POST', '/api/login', ['email' => 'nobody@http.test', 'password' => 'Test#Rbac26'])['code'] === 401);
    $check('HTTP POST /api/login suspended', $http('POST', '/api/login', ['email' => $suspended['email'], 'password' => 'Test#Rbac26'])['code'] === 401);

    $workerToken = $tokens['worker'] ?? '';
    db()->update('users', ['role' => 'accountant'], $users['worker']['id']);
    $me = $http('GET', '/api/me', null, $workerToken);
    $check('HTTP token cannot override a later database role', ($me['json']['data']['user']['role'] ?? '') === 'accountant', 'got ' . (string) ($me['json']['data']['user']['role'] ?? $me['code']));
    db()->update('users', ['role' => 'worker'], $users['worker']['id']);
    $forged = Jwt::encode(['sub' => $users['worker']['id'], 'role' => 'owner', 'farm_id' => $farmA]);
    $forgedMe = $http('GET', '/api/me', null, $forged);
    $check('HTTP forged owner claim still returns the database role', ($forgedMe['json']['data']['user']['role'] ?? '') === 'worker', 'got ' . (string) ($forgedMe['json']['data']['user']['role'] ?? $forgedMe['code']));

    $workerToken = (string) ($http('POST', '/api/login', ['email' => $users['worker']['email'], 'password' => 'Test#Rbac26', 'farm_code' => 'HTTPAAAA'])['json']['data']['token'] ?? '');
    $worker2Token = (string) ($http('POST', '/api/login', ['email' => $worker2['email'], 'password' => 'Test#Rbac26', 'farm_code' => 'HTTPAAAA'])['json']['data']['token'] ?? '');
    $ownerToken = $tokens['owner'] ?? '';

    $otherCreate = $http('POST', '/api/module/attendance', [
        'employee_id' => $empOther,
        'work_date' => $day,
        'status' => 'present',
        'notes' => 'http forged',
    ], $workerToken);
    $foreignCreate = $http('POST', '/api/module/attendance', [
        'employee_id' => $employeeB,
        'farm_id' => $farmB,
        'work_date' => $day,
        'status' => 'present',
    ], $workerToken);
    $invalidDate = $http('POST', '/api/module/attendance', [
        'employee_id' => $empWorker,
        'status' => 'present',
    ], $workerToken);
    $ownCreate = $http('POST', '/api/module/attendance', [
        'employee_id' => $empWorker,
        'work_date' => $day,
        'status' => 'present',
        'notes' => 'http own day',
    ], $workerToken);
    $secondForge = $http('POST', '/api/module/attendance', [
        'employee_id' => $empWorker,
        'work_date' => $day,
        'status' => 'absent',
        'notes' => 'http second forged',
    ], $worker2Token);
    $check('HTTP worker cannot submit another employee', $otherCreate['code'] === 403, 'got ' . $otherCreate['code']);
    $check('HTTP worker cannot submit another farm employee', $foreignCreate['code'] === 403, 'got ' . $foreignCreate['code']);
    $check('HTTP attendance missing date is rejected', $invalidDate['code'] === 422, 'got ' . $invalidDate['code']);
    $check('HTTP worker can create own attendance', $ownCreate['code'] === 201, 'got ' . $ownCreate['code'] . ' ' . ($ownCreate['json']['error'] ?? ''));
    $check('HTTP second worker cannot submit the first employee', $secondForge['code'] === 403, 'got ' . $secondForge['code']);
    $ownId = (int) ($ownCreate['json']['data']['item']['id'] ?? 0);
    $ownUpdate = $http('POST', '/api/module/attendance/' . $ownId, [
        'employee_id' => $empWorker,
        'work_date' => $day,
        'status' => 'leave',
        'notes' => 'should not stick',
    ], $workerToken);
    $check('HTTP worker cannot edit attendance', $ownUpdate['code'] === 403, 'got ' . $ownUpdate['code']);
    $getForeign = $http('GET', '/api/module/attendance/' . $attForeign, null, $workerToken);
    $postForeign = $http('POST', '/api/module/attendance/' . $attForeign, [
        'employee_id' => $empWorker,
        'work_date' => $day,
        'status' => 'absent',
    ], $workerToken);
    $check('HTTP GET other-farm attendance is 404', $getForeign['code'] === 404, 'got ' . $getForeign['code']);
    $check('HTTP worker POST other-farm attendance is 403', $postForeign['code'] === 403, 'got ' . $postForeign['code']);
    $ownerForeign = $http('POST', '/api/module/attendance/' . $attForeign, [
        'employee_id' => $employeeB,
        'work_date' => $day,
        'status' => 'absent',
    ], $ownerToken);
    $check('HTTP owner POST other-farm attendance is 404', $ownerForeign['code'] === 404, 'got ' . $ownerForeign['code']);
    $sameFarmOther = $http('GET', '/api/module/attendance/' . $attOther, null, $workerToken);
    $check('HTTP GET same-farm other attendance is 403', $sameFarmOther['code'] === 403, 'got ' . $sameFarmOther['code']);

    $ownerUpdate = $http('POST', '/api/module/transactions/' . $txnA, [
        'farm_id' => $farmB,
        'txn_date' => $day,
        'type' => 'income',
        'category' => 'sales',
        'description' => 'HTTP-OWNER-UPDATED',
        'amount' => 42.5,
    ], $ownerToken);
    $ownerCross = $http('POST', '/api/module/transactions/' . $txnB, [
        'txn_date' => $day,
        'type' => 'income',
        'category' => 'sales',
        'description' => 'SHOULD-NOT-STICK',
        'amount' => 1,
    ], $ownerToken);
    $ownerPut = $http('PUT', '/api/module/transactions/' . $txnA, [
        'description' => 'PUT-SHOULD-NOT-STICK',
    ], $ownerToken);
    $ownerPatch = $http('PATCH', '/api/module/transactions/' . $txnA, null, $ownerToken);
    $ownerDelete = $http('DELETE', '/api/module/transactions/' . $txnA, null, $ownerToken);
    $check('HTTP owner POST update of own transaction', $ownerUpdate['code'] === 200, 'got ' . $ownerUpdate['code'] . ' ' . ($ownerUpdate['json']['error'] ?? ''));
    $check('HTTP owner cross-farm POST is 404', $ownerCross['code'] === 404, 'got ' . $ownerCross['code']);
    $check('HTTP owner PUT is 405', $ownerPut['code'] === 405, 'got ' . $ownerPut['code']);
    $check('HTTP owner PATCH is 405', $ownerPatch['code'] === 405, 'got ' . $ownerPatch['code']);
    $check('HTTP owner DELETE is 405', $ownerDelete['code'] === 405, 'got ' . $ownerDelete['code']);
    $workerTxn = $http('GET', '/api/module/transactions/' . $txnB, null, $workerToken);
    $check('HTTP worker GET transactions is 403 before the row', $workerTxn['code'] === 403, 'got ' . $workerTxn['code']);

    $check('HTTP forged attendance did not create the other employee note', (int) db()->value('SELECT COUNT(*) FROM attendance WHERE notes = ?', ['http forged']) === 0);
    $check('HTTP own attendance was stored', (string) db()->value('SELECT notes FROM attendance WHERE id = ?', [$ownId]) === 'http own day');
    $check('HTTP worker edit did not change own attendance', (string) db()->value('SELECT notes FROM attendance WHERE id = ?', [$ownId]) === 'http own day');
    $check('HTTP other-farm attendance status unchanged', (string) db()->value('SELECT status FROM attendance WHERE id = ?', [$attForeign]) === 'present');
    $check('HTTP owner update changed the owned transaction', (string) db()->value('SELECT description FROM transactions WHERE id = ?', [$txnA]) === 'HTTP-OWNER-UPDATED');
    $check('HTTP owner update kept farm A', (int) db()->value('SELECT farm_id FROM transactions WHERE id = ?', [$txnA]) === $farmA);
    $check('HTTP cross-farm transaction was not changed', (string) db()->value('SELECT description FROM transactions WHERE id = ?', [$txnB]) === 'HTTP-FOREIGN');
    $check('HTTP PUT did not change the transaction', (string) db()->value('SELECT description FROM transactions WHERE id = ?', [$txnA]) === 'HTTP-OWNER-UPDATED');
}

foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_terminate($proc);
proc_close($proc);
Database::close();
@unlink($dbPath);
@unlink($dbPath . '-wal');
@unlink($dbPath . '-shm');

echo "passed={$passed} failed={$failed}\n";
echo $failed > 0 ? "HAS FAILURES\n" : "ALL PASSED\n";
echo "Disposable SQLite HTTP only. Live Neon was not used.\n";
exit($failed > 0 ? 1 : 0);
