<?php
declare(strict_types=1);

/**
 * HTTP check of role dashboards and denied URLs. Does not print passwords or page bodies.
 */
$base = 'http://127.0.0.1:8081';
$users = [
    ['admin', 'admin@ifms.local', 'Admin#2026', 'Platform', 'Accounts awaiting approval', ''],
    ['owner', 'owner@ifms.local', 'Owner#2026', 'Healthy Crops', 'Income this month', '/integrations'],
    ['manager', 'manager@ifms.local', 'Manager#2026', 'Farm operations', 'Open tasks', '/module/payments/create'],
    ['agronomist', 'agro@ifms.local', 'Agro#2026', 'Crop health', 'High or critical scouting', '/module/payroll'],
    ['worker', 'worker@ifms.local', 'Worker#2026', 'Your tasks', 'My open tasks', '/module/transactions'],
    ['accountant', 'accounts@ifms.local', 'Accounts#2026', 'Cash position', 'Unpaid invoices', '/module/plantings/create'],
];
$fail = 0;
$ok = static function (bool $pass, string $label) use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . PHP_EOL;
};
$curl = static function (string $url, string $cookie, ?array $post = null): array {
    $header = tempnam(sys_get_temp_dir(), 'fwH');
    $body = tempnam(sys_get_temp_dir(), 'fwB');
    $cmd = ['curl.exe', '-sS', '-D', $header, '-o', $body, '-c', $cookie, '-b', $cookie, '--max-time', '140', '-w', '%{http_code}'];
    if ($post !== null) {
        $cmd[] = '-X';
        $cmd[] = 'POST';
        foreach ($post as $key => $value) {
            $cmd[] = '--data-urlencode';
            $cmd[] = $key . '=' . $value;
        }
    }
    $cmd[] = $url;
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $status = '0';
    if (is_resource($proc)) {
        $status = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    $html = is_file($body) ? (string) file_get_contents($body) : '';
    @unlink($header);
    @unlink($body);
    return ['status' => (int) $status, 'html' => $html];
};
$waking = static function (array $res): bool {
    return $res['status'] === 0
        || $res['status'] === 503
        || str_contains($res['html'], 'Waking the FarmWise database')
        || str_contains($res['html'], 'temporarily unavailable');
};
$hint = static function (array $res): string {
    $bits = ['status=' . $res['status']];
    if (str_contains($res['html'], 'Waking the FarmWise database')) {
        $bits[] = 'waking';
    }
    if (str_contains($res['html'], 'temporarily unavailable')) {
        $bits[] = 'db-unavailable';
    }
    if (str_contains($res['html'], 'name="email"')) {
        $bits[] = 'login-page';
    }
    if (str_contains($res['html'], 'do not have permission')) {
        $bits[] = 'forbidden';
    }
    return implode(' ', $bits);
};
$fetch = static function (string $url, string $cookie, ?array $post = null) use ($curl, $waking): array {
    $last = ['status' => 0, 'html' => ''];
    for ($try = 1; $try <= 4; $try++) {
        $last = $curl($url, $cookie, $post);
        if (!$waking($last) || $try === 4) {
            return $last;
        }
        sleep(4);
    }
    return $last;
};

$only = $argv[1] ?? '';
foreach ($users as [$role, $email, $password, $headline, $card, $deny]) {
    if ($only !== '' && $role !== $only) {
        continue;
    }
    $cookie = tempnam(sys_get_temp_dir(), 'fwC');
    $dash = ['status' => 0, 'html' => ''];
    $sent = ['status' => 0, 'html' => ''];
    for ($try = 1; $try <= 4; $try++) {
        $login = $fetch($base . '/login', $cookie);
        preg_match('/name="_csrf" value="([^"]+)"/', $login['html'], $m);
        $token = $m[1] ?? '';
        if ($token === '') {
            if ($try < 4) {
                sleep(4);
            }
            continue;
        }
        $sent = $curl($base . '/login', $cookie, [
            '_csrf' => $token,
            'email' => $email,
            'password' => $password,
        ]);
        if ($waking($sent) && $try < 4) {
            sleep(4);
            continue;
        }
        $dash = $fetch($base . '/dashboard', $cookie);
        if (!$waking($dash) || $try === 4) {
            break;
        }
        sleep(4);
    }
    $ok($dash['status'] === 200 && str_contains($dash['html'], $headline), "[$role] dashboard headline " . $hint($dash));
    $ok(str_contains($dash['html'], $card), "[$role] dashboard card");
    if ($role === 'worker') {
        $ok(!str_contains($dash['html'], 'Income this month') && !str_contains($dash['html'], 'Unpaid invoices'), "[$role] dashboard hides finance");
    }
    if ($role === 'accountant') {
        $ok(!str_contains($dash['html'], 'My open tasks') && !str_contains($dash['html'], 'Active crops'), "[$role] dashboard hides field work");
    }
    if ($deny !== '') {
        $blocked = $fetch($base . $deny, $cookie);
        $ok($blocked['status'] === 403 || str_contains($blocked['html'], 'do not have permission'), "[$role] denied $deny " . $hint($blocked));
    }
    if ($sent['status'] === 0 && $dash['status'] === 0) {
        $ok(false, "[$role] server did not respond");
    }
    @unlink($cookie);
}

echo ($fail === 0 ? 'ALL PASSED' : "HAS FAILURES $fail") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
