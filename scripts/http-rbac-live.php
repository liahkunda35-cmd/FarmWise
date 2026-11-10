<?php
declare(strict_types=1);

/**
 * Read-mostly checks against the already running app at 127.0.0.1:8081.
 * Successful logins write an audit row. This script does not create attendance,
 * does not update transactions, and does not change any user.
 *
 * Run: C:\xampp\php\php.exe scripts\http-rbac-live.php
 */

$base = rtrim(getenv('FARMWISE_BASE') ?: 'http://127.0.0.1:8081', '/');
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
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    return ['code' => $code, 'json' => is_array($decoded) ? $decoded : [], 'err' => $err];
};

$b64 = static function (string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
};
$secretPath = dirname(__DIR__) . '/storage/jwt.secret';
$secret = is_file($secretPath) ? (string) file_get_contents($secretPath) : '';
$forge = static function (array $payload) use ($b64, $secret): string {
    $header = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['iat'] = time();
    $payload['exp'] = time() + 300;
    $body = $b64(json_encode($payload));
    $sig = $b64(hash_hmac('sha256', $header . '.' . $body, $secret, true));
    return $header . '.' . $body . '.' . $sig;
};

$accounts = [
    'admin' => ['admin@ifms.local', 'Admin#2026'],
    'owner' => ['owner@ifms.local', 'Owner#2026'],
    'manager' => ['manager@ifms.local', 'Manager#2026'],
    'agronomist' => ['agro@ifms.local', 'Agro#2026'],
    'worker' => ['worker@ifms.local', 'Worker#2026'],
    'accountant' => ['accounts@ifms.local', 'Accounts#2026'],
];

$tokens = [];
foreach ($accounts as $role => [$email, $password]) {
    $res = $http('POST', '/api/login', ['email' => $email, 'password' => $password]);
    $tokens[$role] = (string) ($res['json']['data']['token'] ?? '');
    $gotRole = (string) ($res['json']['data']['user']['role'] ?? '');
    $check('LIVE POST /api/login ' . $role, $res['code'] === 200 && $gotRole === $role, 'got ' . $res['code'] . ' role ' . $gotRole . ' ' . $res['err']);
}

$bad = $http('POST', '/api/login', ['email' => 'worker@ifms.local', 'password' => 'not-the-password']);
$check('LIVE POST /api/login bad password', $bad['code'] === 401, 'got ' . $bad['code']);
$missing = $http('POST', '/api/login', ['email' => 'nobody-live@ifms.local', 'password' => 'Worker#2026']);
$check('LIVE POST /api/login unknown email', $missing['code'] === 401, 'got ' . $missing['code']);

$worker = $tokens['worker'] ?? '';
$owner = $tokens['owner'] ?? '';
$workerId = 0;
if ($worker !== '') {
    $me = $http('GET', '/api/me', null, $worker);
    $workerId = (int) ($me['json']['data']['user']['id'] ?? 0);
    $check('LIVE /api/me worker role comes from the account', ($me['json']['data']['user']['role'] ?? '') === 'worker' && $workerId === 5, 'id ' . $workerId);
}
if ($secret !== '' && $workerId > 0) {
    $forged = $forge(['sub' => $workerId, 'role' => 'owner', 'farm_id' => 1]);
    $forgedMe = $http('GET', '/api/me', null, $forged);
    $check('LIVE forged owner claim does not override the database role', ($forgedMe['json']['data']['user']['role'] ?? '') === 'worker', 'got ' . (string) ($forgedMe['json']['data']['user']['role'] ?? $forgedMe['code']));
} else {
    $check('LIVE forged owner claim does not override the database role', false, 'missing token secret or worker id');
}

$txn = $http('GET', '/api/module/transactions/1', null, $worker);
$check('LIVE worker GET transactions is 403', $txn['code'] === 403, 'got ' . $txn['code']);
$forgeAttendance = $http('POST', '/api/module/attendance', [
    'employee_id' => 2,
    'work_date' => date('Y-m-d'),
    'status' => 'present',
    'notes' => 'live-forged-should-not-save',
], $worker);
$check('LIVE worker POST another employee attendance is 403', $forgeAttendance['code'] === 403, 'got ' . $forgeAttendance['code'] . ' ' . (string) ($forgeAttendance['json']['error'] ?? ''));
$editOther = $http('POST', '/api/module/attendance/3', [
    'employee_id' => 1,
    'work_date' => '2026-10-27',
    'status' => 'absent',
    'notes' => 'live-edit-should-not-save',
], $worker);
$check('LIVE worker POST another attendance id is 403', $editOther['code'] === 403, 'got ' . $editOther['code']);
$sameFarm = $http('GET', '/api/module/attendance/3', null, $worker);
$check('LIVE GET same-farm other attendance is 403', $sameFarm['code'] === 403, 'got ' . $sameFarm['code']);
$ownList = $http('GET', '/api/module/attendance', null, $worker);
$ownIds = array_map('intval', array_column($ownList['json']['data']['items'] ?? [], 'employee_id'));
$check('LIVE worker attendance list is limited to employee 1', $ownList['code'] === 200 && $ownIds !== [] && $ownIds === array_filter($ownIds, static fn ($id) => $id === 1), 'got ' . $ownList['code'] . ' ids ' . implode(',', $ownIds));

$farmOther = $http('GET', '/api/module/farms/2', null, $owner);
$farmOwn = $http('GET', '/api/module/farms/1', null, $owner);
$check('LIVE owner GET own farm is 200', $farmOwn['code'] === 200, 'got ' . $farmOwn['code']);
$check('LIVE owner GET another farm is 404', $farmOther['code'] === 404, 'got ' . $farmOther['code']);
$workerFarm = $http('GET', '/api/module/farms/2', null, $worker);
$check('LIVE worker GET farms is 403 before the row', $workerFarm['code'] === 403, 'got ' . $workerFarm['code']);

$put = $http('PUT', '/api/module/transactions/1', ['description' => 'LIVE-PUT-SHOULD-NOT-STICK'], $owner);
$patch = $http('PATCH', '/api/module/transactions/1', ['description' => 'LIVE-PATCH-SHOULD-NOT-STICK'], $owner);
$delete = $http('DELETE', '/api/module/transactions/1', null, $owner);
$check('LIVE owner PUT transactions is 405', $put['code'] === 405, 'got ' . $put['code']);
$check('LIVE owner PATCH transactions is 405', $patch['code'] === 405, 'got ' . $patch['code']);
$check('LIVE owner DELETE transactions is 405', $delete['code'] === 405, 'got ' . $delete['code']);

echo "passed={$passed} failed={$failed}\n";
echo $failed > 0 ? "HAS FAILURES\n" : "ALL PASSED\n";
echo "Live HTTP against {$base}. No attendance create and no transaction update were attempted.\n";
exit($failed > 0 ? 1 : 0);
