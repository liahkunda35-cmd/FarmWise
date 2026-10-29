<?php
declare(strict_types=1);

/**
 * Priority 1 checks: task assignment, farm scope, error redaction, private URLs.
 * Farm and task rows created here are rolled back.
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
$skipped = static function (string $label, string $detail) use (&$skip): void {
    $skip++;
    echo 'SKIP ' . $label . ' — ' . $detail . PHP_EOL;
};

$probe = 'postgresql://user:PWPROBE99@db.example/farm';
$safe = user_safe_message('SQLSTATE connection failed ' . $probe);
$ok('browser message hides database text', !str_contains($safe, 'PWPROBE99') && !str_contains($safe, 'SQLSTATE') && !str_contains($safe, 'postgresql://'));
$redacted = redact_for_log($probe . ' password=PWPROBE99');
$ok('log redaction removes credentials', str_contains($redacted, '[redacted]') && !str_contains($redacted, 'PWPROBE99'));

$ok('payment delete stays blocked', ReversalService::validateDelete('payments', ['id' => 1]) !== null);
$ok('stock movement delete stays blocked', ReversalService::validateDelete('stock_movements', ['id' => 1]) !== null);

$farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
$workerUser = db()->first("SELECT * FROM users WHERE role = 'worker' AND status = 'active' ORDER BY id ASC");
$manager = db()->first("SELECT * FROM users WHERE role = 'manager' AND status = 'active' ORDER BY id ASC");
$admin = db()->first("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");
$ok('seed farm present', (bool) $farm);
$ok('worker user present', (bool) $workerUser);
$ok('manager user present', (bool) $manager);
$ok('admin user present', (bool) $admin);

if ($farm && $manager && $admin) {
    Auth::setOverride($manager);
    unset($_SESSION['active_farm_id']);
    $managerNoFarm = $manager;
    $managerNoFarm['farm_id'] = null;
    Auth::setOverride($managerNoFarm);
    $only = Auth::accessibleFarms();
    if (count($only) === 1) {
        [$sql] = active_farm_constraint('farm_id');
        $ok('single-farm manager still has that farm', str_contains($sql, 'farm_id = ?'));
    } else {
        [$sql] = active_farm_constraint('farm_id');
        $ok('manager with no selected farm matches nothing', $sql === '1=0', $sql);
    }

    $stranger = $manager;
    $stranger['id'] = 900000001;
    $stranger['role'] = 'manager';
    $stranger['farm_id'] = null;
    $stranger['status'] = 'active';
    Auth::setOverride($stranger);
    unset($_SESSION['active_farm_id']);
    [$noneSql] = active_farm_constraint('farm_id');
    $ok('user with no farm membership matches nothing', $noneSql === '1=0', $noneSql);

    Auth::setOverride($admin);
    unset($_SESSION['active_farm_id']);
    $adminOpen = $admin;
    $adminOpen['farm_id'] = null;
    Auth::setOverride($adminOpen);
    [$adminSql] = active_farm_constraint('farm_id');
    $ok('admin with no selected farm keeps cross-farm access', $adminSql === '1=1', $adminSql);
}

$rolledBack = false;
if ($farm && $workerUser) {
    $farmId = (int) $farm['id'];
    db()->beginTransaction();
    try {
        $marker = 'p1sec_' . bin2hex(random_bytes(3));
        $otherFarmId = (int) db()->insert('farms', [
            'name' => $marker,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $emp = db()->first('SELECT * FROM employees WHERE user_id = ? AND farm_id = ? AND status = ?', [$workerUser['id'], $farmId, 'active']);
        if (!$emp) {
            $empId = (int) db()->insert('employees', [
                'farm_id' => $farmId,
                'user_id' => (int) $workerUser['id'],
                'name' => 'P1 worker',
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $emp = db()->find('employees', $empId);
        }
        $otherEmpId = (int) db()->insert('employees', [
            'farm_id' => $farmId,
            'name' => $marker . ' other',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $mineId = (int) db()->insert('tasks', [
            'farm_id' => $farmId,
            'title' => $marker . ' mine',
            'assigned_to' => (int) $emp['id'],
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $otherTaskId = (int) db()->insert('tasks', [
            'farm_id' => $farmId,
            'title' => $marker . ' other',
            'assigned_to' => $otherEmpId,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $foreignTaskId = (int) db()->insert('tasks', [
            'farm_id' => $otherFarmId,
            'title' => $marker . ' foreign',
            'assigned_to' => (int) $emp['id'],
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Auth::setOverride($workerUser);
        $_SESSION['active_farm_id'] = $farmId;
        $_SESSION['user_id'] = (int) $workerUser['id'];
        $_SESSION['session_version'] = (int) ($workerUser['session_version'] ?? 1);
        Auth::clearCache();
        Auth::setOverride($workerUser);

        $mine = db()->find('tasks', $mineId);
        $other = db()->find('tasks', $otherTaskId);
        $foreign = db()->find('tasks', $foreignTaskId);
        $ok('worker can open own task', $mine && TaskAccess::workerCanAccessTask($mine) && Auth::canAccessRecord($mine));
        $ok('worker cannot open another worker task on the same farm', $other && !TaskAccess::workerCanAccessTask($other));
        $ok('worker cannot open a task on another farm', $foreign && !Auth::canAccessRecord($foreign));
        [$listSql, $listParams] = TaskAccess::listConstraint();
        $ok('worker task list is limited to their employee id', $listSql === ' AND assigned_to = ?' && ($listParams[0] ?? null) === (int) $emp['id']);

        Auth::setOverride($manager);
        $_SESSION['active_farm_id'] = $farmId;
        Auth::clearCache();
        Auth::setOverride($manager);
        $ok('manager can open another worker task', $other && TaskAccess::workerCanAccessTask($other) && Auth::canAccessRecord($other));
        $ok('manager cannot open the other farm task', $foreign && !Auth::canAccessRecord($foreign));

        db()->rollBack();
        $rolledBack = !db()->first('SELECT id FROM farms WHERE name = ?', [$marker]);
        $ok('fixture farm rolled back', $rolledBack);
        $ok('fixture tasks rolled back', !db()->first('SELECT id FROM tasks WHERE title = ?', [$marker . ' mine']));
    } catch (Throwable $e) {
        if (db()->pdo()->inTransaction()) {
            db()->rollBack();
        }
        $ok('isolated fixture', false, redact_for_log($e->getMessage()));
    }
} else {
    $skipped('task fixtures', 'worker or farm missing');
}

Auth::setOverride(null);

$base = getenv('FARMWISE_BASE') ?: 'http://127.0.0.1:8081';
$fetch = static function (string $path) use ($base): array {
    $url = rtrim($base, '/') . $path;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status > 0) {
            return [$status, is_string($body) ? $body : ''];
        }
    }
    $curl = 'curl.exe';
    $codeFile = tempnam(sys_get_temp_dir(), 'fw');
    $bodyFile = tempnam(sys_get_temp_dir(), 'fw');
    if ($codeFile && $bodyFile) {
        $cmd = $curl . ' -s -o ' . escapeshellarg($bodyFile) . ' -w "%{http_code}" ' . escapeshellarg($url);
        $code = shell_exec($cmd);
        $status = (int) trim((string) $code);
        $body = is_file($bodyFile) ? (string) file_get_contents($bodyFile) : '';
        @unlink($codeFile);
        @unlink($bodyFile);
        if ($status > 0) {
            return [$status, $body];
        }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, (string) $body];
};

$privatePaths = [
    '/.env',
    '/.env.local',
    '/.env.example',
    '/storage/jwt.secret',
    '/storage/backups/',
    '/app/bootstrap.php',
    '/config/config.php',
    '/config/permissions.php',
    '/backend/bootstrap.php',
    '/backend/config/permissions.php',
    '/frontend/pages/landing.php',
    '/frontend/bridge.php',
];
$serverUp = false;
try {
    [$status] = $fetch('/.env');
    $serverUp = $status > 0;
} catch (Throwable $e) {
    $serverUp = false;
}
if (!$serverUp) {
    $skipped('private URL probe', 'no HTTP server at ' . $base);
} else {
    foreach ($privatePaths as $path) {
        [$status, $body] = $fetch($path);
        $leaks = str_contains($body, 'DATABASE_URL=')
            || str_contains($body, 'PGPASSWORD')
            || str_contains($body, 'GEMINI_API_KEY=')
            || str_contains($body, 'NEXUS_API_KEY=')
            || str_contains($body, 'BEGIN ')
            || str_contains($body, '<?php');
        $ok('private ' . $path, $status === 403 && !$leaks, 'http=' . $status . ' bytes=' . strlen($body));
    }
    [$assetStatus, $assetBody] = $fetch('/assets/css/app.css');
    $ok('public css still served', $assetStatus === 200 && strlen($assetBody) > 100, 'http=' . $assetStatus);
}

echo PHP_EOL . 'failures=' . $fail . ' skips=' . $skip . PHP_EOL;
if ($fail > 0) {
    echo "HAS FAILURES\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
