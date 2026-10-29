<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';

$cases = [
    // Valid Airtel
    ['0971234567', true],
    ['+260971234567', true],
    ['260971234567', true],
    ['0771234567', true],
    ['0571234567', true],
    // Valid MTN
    ['0961234567', true],
    ['+260761234567', true],
    ['0561234567', true],
    // Valid Zamtel
    ['0951234567', true],
    ['0751234567', true],
    ['+260551234567', true],
    // Invalid
    ['0100000000', false],
    ['097123456', false], // short
    ['09712345678', false], // long local
    ['+260211234567', false], // landline Lusaka
    ['abc', false],
    ['+441234567890', false],
    ['', false],
    ['097abcdefg', false],
    ['09712A34567', false], // letters must not be stripped into a valid number
    ['09798358555555', false], // too long
];

$fail = 0;
foreach ($cases as [$input, $expect]) {
    $norm = normalize_phone($input);
    $ok = valid_phone($input);
    $pass = $ok === $expect;
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . " input=" . json_encode($input)
        . " norm=" . json_encode($norm) . " valid=" . ($ok ? '1' : '0')
        . " expect=" . ($expect ? '1' : '0') . PHP_EOL;
}
echo 'failures=' . $fail . PHP_EOL;
exit($fail > 0 ? 1 : 0);
