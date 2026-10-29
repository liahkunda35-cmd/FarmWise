<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

$suffix = bin2hex(random_bytes(3));
$email = "worker.{$suffix}@example.test";
$username = 'worker_' . $suffix;

echo "1) submit registration...\n";
$reg = RegistrationService::submitRequest([
    'username' => $username,
    'email' => $email,
    'phone' => '+260977000111',
    'role' => 'worker',
]);
echo '   ok=' . (!empty($reg['ok']) ? '1' : '0') . ' email_sent=' . (!empty($reg['email_result']['ok']) ? '1' : '0')
    . ' email_err=' . substr((string)($reg['email_result']['error'] ?? ''), 0, 120) . PHP_EOL;
if (empty($reg['ok'])) {
    fwrite(STDERR, ($reg['error'] ?? 'fail') . PHP_EOL);
    exit(1);
}
$userId = (int) $reg['user_id'];
$user = db()->find('users', $userId);
echo '   status=' . $user['status'] . ' role=' . $user['role'] . PHP_EOL;

$adminNotifs = (int) db()->value(
    "SELECT COUNT(*) FROM notifications WHERE category = 'registration' AND link = ?",
    ['users/pending/' . $userId]
);
echo "2) admin notifications=" . $adminNotifs . PHP_EOL;

$admin = db()->first("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");
if (!$admin) {
    fwrite(STDERR, "No admin user in database.\n");
    exit(1);
}
Auth::setOverride($admin);
$_SESSION['user_id'] = (int) $admin['id'];
$_SESSION['session_version'] = (int) ($admin['session_version'] ?? 1);

$farm = db()->first('SELECT id FROM farms ORDER BY id ASC');
$farmId = $farm ? (int) $farm['id'] : 0;
echo "3) approve as admin farm={$farmId}...\n";
$appr = RegistrationService::approve($userId, 'worker', $farmId ? [$farmId] : [], false, 'test approve');
echo '   ok=' . (!empty($appr['ok']) ? '1' : '0')
    . ' email_sent=' . (!empty($appr['email_result']['ok']) ? '1' : '0')
    . ' email_err=' . substr((string)($appr['email_result']['error'] ?? ''), 0, 140) . PHP_EOL;
if (empty($appr['ok'])) {
    fwrite(STDERR, ($appr['error'] ?? 'approve fail') . PHP_EOL);
    exit(1);
}
$token = (string) ($appr['activation_token'] ?? '');
$user = db()->find('users', $userId);
echo '   status=' . $user['status'] . ' farms=' . implode(',', RegistrationService::farmIdsForUser($userId)) . PHP_EOL;

echo "4) activate...\n";
$act = RegistrationService::activate($token, 'Worker#Test26', 'Worker#Test26');
echo '   ok=' . (!empty($act['ok']) ? '1' : '0') . ' err=' . ($act['error'] ?? '') . PHP_EOL;
$user = db()->find('users', $userId);
echo '   status=' . $user['status'] . PHP_EOL;

Auth::setOverride(null);
Auth::clearCache();
unset($_SESSION['user_id'], $_SESSION['session_version']);

echo "5) login as worker...\n";
$login = Auth::attemptLogin($email, 'Worker#Test26');
echo '   ok=' . (!empty($login['ok']) ? '1' : '0') . ' role=' . Auth::role() . PHP_EOL;
echo '   can_transactions=' . (Auth::can('transactions', 'view') ? '1' : '0') . PHP_EOL;
echo '   can_users_admin=' . (Auth::can('users', 'edit') ? '1' : '0') . PHP_EOL;
echo '   can_tasks=' . (Auth::can('tasks', 'view') ? '1' : '0') . PHP_EOL;
echo '   farm_access=' . (Auth::canAccessFarm($farmId) ? '1' : '0') . PHP_EOL;

$other = db()->first('SELECT id FROM farms WHERE id <> ? ORDER BY id ASC', [$farmId]);
if ($other) {
    echo '   other_farm_blocked=' . (Auth::canAccessFarm((int)$other['id']) ? '0' : '1') . PHP_EOL;
}

echo "6) pending/rejected login blocked...\n";
$pending = RegistrationService::submitRequest([
    'first_name' => 'Pend',
    'last_name' => 'Ing',
    'username' => 'pend_' . $suffix,
    'email' => "pend.{$suffix}@example.test",
    'phone' => '+260977000222',
    'role' => 'accountant',
]);
$pLogin = Auth::attemptLogin("pend.{$suffix}@example.test", 'anything');
echo '   pending_login_ok=' . (!empty($pLogin['ok']) ? '1' : '0') . ' msg=' . ($pLogin['error'] ?? '') . PHP_EOL;

echo "DONE\n";
