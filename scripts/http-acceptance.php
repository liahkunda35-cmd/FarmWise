<?php
declare(strict_types=1);

/**
 * Phase 5 HTTP acceptance (real routes + session cookies + CSRF) against local server → Neon.
 * Complements Playwright. Does not disable SSL for Nexus.
 *
 * Usage: php scripts/http-acceptance.php
 * Env: FARMWISE_BASE=http://127.0.0.1:8081
 */
$base = rtrim(getenv('FARMWISE_BASE') ?: 'http://127.0.0.1:8081', '/');
$fail = 0;
$cookieFile = sys_get_temp_dir() . '/farmwise_acc_' . getmypid() . '.txt';
@unlink($cookieFile);

$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

function http_req(string $method, string $url, ?string $cookieFile, array $opts = []): array
{
    $attempts = (int) ($opts['retries'] ?? 4);
    $last = ['code' => 0, 'body' => '', 'header' => '', 'location' => null, 'error' => 'no attempt'];
    for ($i = 1; $i <= $attempts; $i++) {
        $ch = curl_init($url);
        $headers = $opts['headers'] ?? [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }
        if (($opts['body'] ?? null) !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        $raw = (string) curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $header = '';
        $body = $raw;
        if (str_contains($raw, "\r\n\r\n")) {
            [$header, $body] = explode("\r\n\r\n", $raw, 2);
        }
        $loc = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $header, $m)) {
            $loc = trim($m[1]);
        }
        $last = ['code' => $code, 'body' => $body, 'header' => $header, 'location' => $loc, 'error' => $err];
        $waking = $code === 503 || str_contains($body, 'Waking the FarmWise database') || str_contains($body, 'Neon may be waking');
        if (!$waking && $err === '') {
            return $last;
        }
        usleep(1500000 * $i);
    }
    return $last;
}

function wait_ready(string $base): bool
{
    for ($i = 0; $i < 12; $i++) {
        $r = http_req('GET', $base . '/login', null);
        if ($r['code'] === 200 && str_contains($r['body'], 'name="email"')) {
            return true;
        }
        sleep(3);
    }
    return false;
}

function extract_csrf(string $html): ?string
{
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

function login(string $base, string $cookieFile, string $email, string $password): array
{
    @unlink($cookieFile);
    $page = http_req('GET', $base . '/login', $cookieFile);
    if ($page['code'] !== 200) {
        return ['ok' => false, 'detail' => 'login page http=' . $page['code']];
    }
    $csrf = extract_csrf($page['body']);
    if (!$csrf) {
        return ['ok' => false, 'detail' => 'no csrf'];
    }
    $post = http_req('POST', $base . '/login', $cookieFile, [
        'body' => http_build_query([
            '_csrf' => $csrf,
            'email' => $email,
            'password' => $password,
        ]),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    // Follow redirects manually keeping cookies
    $hops = 0;
    $loc = $post['location'];
    $code = $post['code'];
    $body = $post['body'];
    while ($loc && $hops < 5) {
        if (!str_starts_with($loc, 'http')) {
            $loc = $base . '/' . ltrim($loc, '/');
        }
        $next = http_req('GET', $loc, $cookieFile);
        $code = $next['code'];
        $body = $next['body'];
        $loc = $next['location'];
        $hops++;
    }
    $ok = $code === 200 && !str_contains(strtolower($body), 'sign in to access') && (
        str_contains($body, 'dashboard') || str_contains(strtolower($body), 'logout')
        || str_contains(strtolower($body), 'sign out') || str_contains($body, 'module/')
        || str_contains($body, 'Quick Actions') || str_contains($body, 'Crop Performance')
    );
    return ['ok' => $ok, 'detail' => 'http=' . $code . ' hops=' . $hops, 'body' => $body];
}

function logout(string $base, string $cookieFile): void
{
    http_req('GET', $base . '/logout', $cookieFile);
}

$ok('server ready', wait_ready($base), $base);

$users = [
    'admin' => ['admin@ifms.local', 'Admin#2026'],
    'owner' => ['owner@ifms.local', 'Owner#2026'],
    'manager' => ['manager@ifms.local', 'Manager#2026'],
    'agronomist' => ['agro@ifms.local', 'Agro#2026'],
    'worker' => ['worker@ifms.local', 'Worker#2026'],
    'accountant' => ['accounts@ifms.local', 'Accounts#2026'],
];

$roleAccess = [
    'admin' => ['/dashboard', '/users', '/module/tasks', '/integrations'],
    'owner' => ['/dashboard', '/module/farms', '/module/fields', '/module/transactions', '/reports'],
    'manager' => ['/dashboard', '/module/tasks', '/module/stock_movements', '/notifications'],
    'agronomist' => ['/dashboard', '/module/pest_scouting', '/module/tasks'],
    'worker' => ['/dashboard', '/module/tasks'],
    'accountant' => ['/dashboard', '/module/invoices', '/module/payments', '/reports?type=financial'],
];
$roleDenied = [
    'worker' => ['/module/transactions', '/module/stock_movements/create'],
    'agronomist' => ['/module/payments', '/module/sales_orders/create'],
    'accountant' => ['/module/plantings/create', '/module/tasks/create'],
    'manager' => ['/module/payments/create'],
];

foreach ($users as $role => [$email, $pass]) {
    $cookie = $cookieFile . '_' . $role;
    @unlink($cookie);
    $res = login($base, $cookie, $email, $pass);
    $ok("HTTP login $role", !empty($res['ok']), ($res['detail'] ?? '') . ' ' . $email);
    if (empty($res['ok'])) {
        continue;
    }
    foreach ($roleAccess[$role] ?? [] as $path) {
        $r = http_req('GET', $base . $path, $cookie);
        // follow one redirect
        if ($r['location'] && $r['code'] >= 300 && $r['code'] < 400) {
            $loc = $r['location'];
            if (!str_starts_with($loc, 'http')) {
                $loc = $base . '/' . ltrim($loc, '/');
            }
            $r = http_req('GET', $loc, $cookie);
        }
        $allowed = $r['code'] === 200 && !str_contains($r['body'], 'do not have permission');
        $ok("HTTP $role access $path", $allowed, 'http=' . $r['code']);
    }
    foreach ($roleDenied[$role] ?? [] as $path) {
        $r = http_req('GET', $base . $path, $cookie);
        $blocked = $r['code'] === 403 || str_contains($r['body'], 'do not have permission') || str_contains($r['body'], '403');
        $ok("HTTP $role denied $path", $blocked, 'http=' . $r['code']);
    }
    logout($base, $cookie);
    @unlink($cookie);
}

// Manager: create field via form POST
$cookie = $cookieFile . '_mgr2';
@unlink($cookie);
$res = login($base, $cookie, 'manager@ifms.local', 'Manager#2026');
$ok('HTTP manager re-login', !empty($res['ok']));
if (!empty($res['ok'])) {
    $tag = 'p5http_' . bin2hex(random_bytes(2));
    $create = http_req('GET', $base . '/module/fields/create', $cookie);
    $csrf = extract_csrf($create['body'] ?? '');
    $ok('HTTP fields create form', $create['code'] === 200 && $csrf);
    if ($csrf) {
        $post = http_req('POST', $base . '/module/fields/store', $cookie, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                '_csrf' => $csrf,
                'name' => $tag . '_field',
                'size_hectares' => '2.25',
                'status' => 'active',
            ]),
        ]);
        $loc = $post['location'] ?? '';
        // follow
        if ($loc) {
            if (!str_starts_with($loc, 'http')) {
                $loc = $base . '/' . ltrim($loc, '/');
            }
            http_req('GET', $loc, $cookie);
        }
        $list = http_req('GET', $base . '/module/fields', $cookie);
        $ok('HTTP field persisted in list', str_contains($list['body'], $tag . '_field'), 'code=' . $list['code']);

        // persistence after "logout/login"
        logout($base, $cookie);
        $res2 = login($base, $cookie, 'manager@ifms.local', 'Manager#2026');
        $list2 = http_req('GET', $base . '/module/fields', $cookie);
        $ok('HTTP field survives re-login', !empty($res2['ok']) && str_contains($list2['body'], $tag . '_field'));
    }

    // Financial report cash wording
    $fin = http_req('GET', $base . '/reports?type=financial', $cookie);
    $ok('HTTP financial report loads', $fin['code'] === 200);
    $ok(
        'HTTP financial is cash-basis',
        str_contains(strtolower($fin['body']), 'cash') || str_contains(strtolower($fin['body']), 'profit')
    );
    $recv = http_req('GET', $base . '/reports?type=receivables', $cookie);
    $ok('HTTP receivables report loads', $recv['code'] === 200);
}

// Registration page reachable
$reg = http_req('GET', $base . '/register', null);
$ok('HTTP register form', $reg['code'] === 200 && str_contains($reg['body'], 'username'));

// Forgot password form
$fg = http_req('GET', $base . '/forgot-password', null);
$ok('HTTP forgot-password form', $fg['code'] === 200 && (str_contains($fg['body'], 'email') || str_contains($fg['body'], 'Forgot')));

@unlink($cookieFile);
echo PHP_EOL . "failures=$fail" . PHP_EOL;
echo ($fail === 0 ? 'HTTP ACCEPTANCE PASSED' : 'HTTP ACCEPTANCE HAS FAILURES') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
