<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';

$failures = [];
$ok = static function (bool $pass, string $label, string $detail = '') use (&$failures): void {
    echo ($pass ? 'PASS' : 'FAIL') . '  ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$pass) {
        $failures[] = $label;
    }
};

$bundle = DemoSignIn::forLoginPage();
$accounts = $bundle['accounts'];
$fills = $bundle['fills'];
$ok(count($accounts) === 5, 'five demo accounts available', (string) count($accounts));

$expected = [
    'admin@ifms.local' => 'Administrator',
    'owner@ifms.local' => 'Farm Owner',
    'manager@ifms.local' => 'Farm Manager',
    'agro@ifms.local' => 'Farm Supervisor',
    'worker@ifms.local' => 'Farm Worker',
];
foreach ($expected as $email => $label) {
    $row = null;
    foreach ($accounts as $a) {
        if (($a['email'] ?? '') === $email) {
            $row = $a;
            break;
        }
    }
    $ok($row !== null, $label . ' listed', $email);
    if ($row) {
        $ok(!empty($row['can_fill_password']), $label . ' can auto-fill password');
        $ok(isset($fills[$email]), $label . ' has fill token');
        $pass = base64_decode((string) ($fills[$email] ?? ''), true);
        $login = Auth::authenticate($row['identity'], (string) $pass);
        $ok(!empty($login['ok']), $label . ' authenticates via normal login', (string) ($login['error'] ?? ''));
        Auth::setOverride(null);
        Auth::clearCache();
        unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['active_farm_id']);
    }
}

$again = DemoSignIn::forLoginPage();
$ok(count($again['accounts']) === 5, 'ensure is idempotent (still five accounts)');
$countOwners = (int) db()->value("SELECT COUNT(*) FROM users WHERE lower(email) = 'owner@ifms.local'");
$ok($countOwners === 1, 'owner demo not duplicated');

if ($failures) {
    echo PHP_EOL . count($failures) . ' failure(s)' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'ALL PASSED' . PHP_EOL;
exit(0);
