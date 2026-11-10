<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';
set_time_limit(180);

$fail = 0;
$ok = static function (bool $pass, string $label, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'PASS' : 'FAIL') . '  ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

Schema::ensureEmployeeSchema(db());
$ok(in_array('email', db()->columns('employees'), true), 'employees.email column exists');
$ok(in_array('employment_type', db()->columns('employees'), true), 'employees.employment_type column exists');

$farm = db()->first('SELECT id FROM farms ORDER BY id ASC LIMIT 1');
$ok((bool) $farm, 'Farm available for employee test');
if (!$farm) {
    echo "FAILED — no farm\n";
    exit(1);
}

$tag = 't' . bin2hex(random_bytes(3));
$email = $tag . '@example.test';
$empId = db()->insert('employees', [
    'farm_id' => (int) $farm['id'],
    'name' => 'Test Worker ' . $tag,
    'role_title' => 'General Worker',
    'employment_type' => 'seasonal',
    'email' => $email,
    'phone' => '+260970000001',
    'hire_date' => date('Y-m-d'),
    'daily_rate' => 100,
    'status' => 'active',
]);
$ok($empId > 0, 'Employee row created', 'id=' . $empId);

$emp = db()->find('employees', $empId);
$result = EmployeeAccounts::createForEmployee($emp, ['email' => $email]);
$ok($result['ok'] ?? false, 'Login account created', $result['error'] ?? '');
$ok(!empty($result['temp_password']), 'Temporary password returned once');
$ok(PasswordPolicy::isStrong((string) ($result['temp_password'] ?? '')), 'Temp password meets policy');

if (!empty($result['user_id'])) {
    db()->update('employees', ['user_id' => $result['user_id']], $empId);
    $user = db()->find('users', (int) $result['user_id']);
    $ok((int) ($user['must_change_password'] ?? 0) === 1, 'must_change_password flag set');
    $ok(password_verify((string) $result['temp_password'], (string) $user['password']), 'Hash matches temp password');
    $ok(!str_contains((string) $user['password'], (string) $result['temp_password']), 'Plain temp password not stored');

    $reset = EmployeeAccounts::resetTemporaryPassword((int) $result['user_id']);
    $ok($reset['ok'] ?? false, 'Reset temporary password works');
    $user2 = db()->find('users', (int) $result['user_id']);
    $ok((int) ($user2['must_change_password'] ?? 0) === 1, 'Reset keeps must_change_password');
    $ok(password_verify((string) $reset['temp_password'], (string) $user2['password']), 'Reset hash matches new temp password');

    $report = EmployeeReports::buildIndividual(db()->find('employees', $empId));
    $pdf = EmployeeReports::pdfBytes($report);
    $ok(str_starts_with($pdf, '%PDF'), 'Individual employee PDF generated');

    // cleanup user + employee
    db()->delete('employees', $empId);
    db()->delete('users', (int) $result['user_id']);
} else {
    db()->delete('employees', $empId);
}

echo PHP_EOL . ($fail === 0 ? 'ALL CHECKS PASSED' : "FAILED {$fail} CHECK(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
