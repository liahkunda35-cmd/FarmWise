<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';
set_time_limit(0);

$fail = 0;
$ok = static function (bool $pass, string $label, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$tag = 'fwj' . bin2hex(random_bytes(3));
$password = 'Join#Farm26';
$phone = '0977' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$created = [];
$farmBefore = (int) db()->value('SELECT COUNT(*) FROM farms');
$farm = db()->first("SELECT id, name, join_code, location FROM farms WHERE status = 'active' AND join_code IS NOT NULL AND join_code <> '' ORDER BY id ASC");
$ok(is_array($farm), 'an existing farm with a code is available');
if (!$farm) {
    exit(1);
}

$bad = RegistrationService::submitRequest([
    'name' => 'Bad Code ' . $tag,
    'username' => $tag . '.bad',
    'email' => $tag . '.bad@example.test',
    'phone' => $phone,
    'role' => 'worker',
    'password' => $password,
    'password_confirm' => $password,
    'farm_code' => 'ZZZZ-NOPE',
]);
$ok(empty($bad['ok']) && ($bad['error'] ?? '') === 'Invalid Farm Code. Please check the code provided by your farm owner.', 'invalid farm code is rejected with the required message', (string) ($bad['error'] ?? ''));
$ok(!db()->first('SELECT id FROM users WHERE email = ?', [$tag . '.bad@example.test']), 'invalid code does not create a user');

$worker = RegistrationService::submitRequest([
    'name' => 'Worker ' . $tag,
    'username' => $tag . '.worker',
    'email' => $tag . '.worker@example.test',
    'phone' => $phone,
    'role' => 'worker',
    'password' => $password,
    'password_confirm' => $password,
    'farm_code' => (string) $farm['join_code'],
]);
$ok(!empty($worker['ok']) && ($worker['status'] ?? '') === 'active', 'worker with a valid code is active immediately', (string) ($worker['error'] ?? $worker['status'] ?? ''));
$workerRow = db()->first('SELECT id, farm_id, status, role FROM users WHERE email = ?', [$tag . '.worker@example.test']);
if ($workerRow) {
    $created[] = (int) $workerRow['id'];
}
$ok($workerRow && (int) $workerRow['farm_id'] === (int) $farm['id'] && $workerRow['status'] === 'active', 'worker is linked to the existing farm');
$ok((int) db()->value('SELECT COUNT(*) FROM farms') === $farmBefore, 'worker registration does not create another farm');

$login = Auth::authenticate($tag . '.worker@example.test', $password);
$ok(!empty($login['ok']) && (int) ($login['farm_id'] ?? 0) === (int) $farm['id'], 'worker signs in without a farm code', (string) ($login['error'] ?? ''));
if (!empty($login['user'])) {
    Auth::setOverride($login['user']);
    $ok(Auth::can('farms', 'view') && !Auth::can('farms', 'edit'), 'worker can view the farm and cannot edit it');
    Auth::setOverride(null);
}

$owner = RegistrationService::submitRequest([
    'name' => 'Owner ' . $tag,
    'username' => $tag . '.owner',
    'email' => $tag . '.owner@example.test',
    'phone' => '0967' . substr($phone, -6),
    'role' => 'owner',
    'password' => $password,
    'password_confirm' => $password,
]);
$ok(!empty($owner['ok']) && ($owner['status'] ?? '') === 'pending', 'owner waits for administrator approval', (string) ($owner['error'] ?? $owner['status'] ?? ''));
$ownerRow = db()->first('SELECT id, farm_id, status FROM users WHERE email = ?', [$tag . '.owner@example.test']);
if ($ownerRow) {
    $created[] = (int) $ownerRow['id'];
}
$ok($ownerRow && $ownerRow['farm_id'] === null && $ownerRow['status'] === 'pending', 'owner has no farm until the profile is created');

$admin = db()->first("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");
if ($admin && $ownerRow) {
    Auth::setOverride($admin);
    $approved = RegistrationService::approve((int) $ownerRow['id'], 'owner', [], false, null);
    Auth::setOverride(null);
    $ownerAfter = db()->find('users', (int) $ownerRow['id']);
    $ok(!empty($approved['ok']) && ($ownerAfter['status'] ?? '') === 'active' && empty($ownerAfter['farm_id']), 'owner approval does not invent a farm', (string) ($approved['error'] ?? ''));
    $ownerLogin = Auth::authenticate($tag . '.owner@example.test', $password);
    $ok(!empty($ownerLogin['ok']), 'approved owner signs in with the registration password', (string) ($ownerLogin['error'] ?? ''));
}

$signup = file_get_contents(dirname(__DIR__) . '/frontend/pages/auth/auth.php');
$loginForm = is_string($signup) ? strstr($signup, "url('login')) ?>\" class=\"auth-shell-fields\"") : false;
$ok(is_string($signup) && substr_count($signup, 'name="farm_code"') === 1, 'registration page has one farm code field');
$ok(is_string($loginForm) && !str_contains($loginForm, 'farm_code'), 'login page has no farm code field');

foreach ($created as $id) {
    db()->query('DELETE FROM notifications WHERE user_id = ?', [$id]);
    db()->query("DELETE FROM notifications WHERE link = ?", ['users/pending/' . $id]);
    if (table_exists('user_farms')) {
        db()->query('DELETE FROM user_farms WHERE user_id = ?', [$id]);
    }
    db()->query('DELETE FROM login_attempts WHERE identity LIKE ?', ['%' . $tag . '%']);
    db()->delete('users', $id);
}

echo $fail === 0 ? "ALL PASSED\n" : ($fail . " FAILED\n");
exit($fail === 0 ? 0 : 1);
