<?php
/**
 * Disposable checks for signup username + phone rules (no DB writes for phone/username unit cases).
 * Role / duplicate checks need a booted DB and are exercised separately when available.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    if (!$ok) {
        $fail++;
        fwrite(STDERR, "FAIL: {$label}\n");
    } else {
        fwrite(STDOUT, "OK: {$label}\n");
    }
};

// Username
$check('liahkunda35 valid', RegistrationService::usernameError('liahkunda35') === null);
$check('liah_kunda valid', RegistrationService::usernameError('liah_kunda') === null);
$check('liah.kunda valid', RegistrationService::usernameError('liah.kunda') === null);
$check('liah-kunda valid', RegistrationService::usernameError('liah-kunda') === null);
$check('trim spaces around valid', RegistrationService::usernameError('  liahkunda35  ') === null);
$check('li too short', RegistrationService::usernameError('li') !== null);
$check('space rejected', str_contains((string) RegistrationService::usernameError('liah kunda'), 'spaces'));
$check('@ rejected', RegistrationService::usernameError('liah@kunda') !== null);
$check('normalize lowercases', RegistrationService::normalizeUsername('Liah_Kunda') === 'liah_kunda');
$check('normalize keeps spaces for reject', RegistrationService::normalizeUsername('liah kunda') === 'liah kunda');

// Phone
$check('local airtel', valid_phone('0971234567'));
$check('intl +260 airtel', valid_phone('+260971234567'));
$check('intl 260 airtel', valid_phone('260971234567'));
$check('local mtn', valid_phone('0961234567'));
$check('local zamtel', valid_phone('0751234567'));
$check('spaces ok', valid_phone('097 123 4567'));
$check('hyphens ok', valid_phone('097-123-4567'));
$check('too long', !valid_phone('09798358555555'));
$check('letters rejected', !valid_phone('097ABC12345'));
$check('letters not stripped to valid', !valid_phone('09712A34567'));
$check('too short', !valid_phone('12345'));
$check('landline rejected', !valid_phone('+260211234567'));
$check('norm E.164', normalize_phone('0971234567') === '+260971234567');

// Role rejection via submitRequest (invalid role) — needs DB
$roleResult = RegistrationService::submitRequest([
    'username' => 'validuser99',
    'email' => 'unique-role-test-' . bin2hex(random_bytes(4)) . '@example.com',
    'phone' => '0971234567',
    'role' => 'superadmin',
]);
$check('invalid role rejected', empty($roleResult['ok']) && str_contains((string) ($roleResult['error'] ?? ''), 'account type'));

echo 'failures=' . $fail . PHP_EOL;
exit($fail > 0 ? 1 : 0);
