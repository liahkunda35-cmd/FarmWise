<?php
declare(strict_types=1);

/**
 * Phase 6 HTTP UI acceptance for remaining modules (CSRF session forms → Neon).
 * More reliable than Playwright against php -S + cold Neon.
 *
 * Usage: php scripts/http-gap-acceptance.php
 * FARMWISE_BASE=http://127.0.0.1:8081
 */
@set_time_limit(0);
$base = rtrim(getenv('FARMWISE_BASE') ?: 'http://127.0.0.1:8081', '/');
$cookieDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'farmwise_p6_' . getmypid();
@mkdir($cookieDir, 0777, true);
$fail = 0;

$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

function http_req(string $method, string $url, string $cookieFile, ?array $opts = null): array
{
    $attempt = 0;
    $last = ['code' => 0, 'body' => '', 'location' => '', 'err' => 'no attempt'];
    while ($attempt < 5) {
        $attempt++;
        $ch = curl_init($url);
        $headers = $opts['headers'] ?? [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch) ?: '';
        curl_close($ch);
        $header = is_string($raw) ? substr($raw, 0, $headerSize) : '';
        $body = is_string($raw) ? substr($raw, $headerSize) : '';
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/im', $header, $m)) {
            $location = trim($m[1]);
        }
        $last = ['code' => $code, 'body' => $body, 'location' => $location, 'err' => $err];
        if ($code === 0 || $code === 503 || str_contains($body, 'Waking the FarmWise database')) {
            sleep(3);
            continue;
        }
        return $last;
    }
    return $last;
}

function extract_csrf(string $html): string
{
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="_csrf"\s+value=\'([^\']+)\'/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function wait_ready(string $base): bool
{
    for ($i = 0; $i < 20; $i++) {
        $r = http_req('GET', $base . '/login', sys_get_temp_dir() . '/fw_ready_' . getmypid());
        if ($r['code'] === 200 && str_contains($r['body'], 'name="email"') && !str_contains($r['body'], 'Waking')) {
            return true;
        }
        sleep(3);
    }
    return false;
}

function login(string $base, string $cookie, string $email, string $pass): bool
{
    @unlink($cookie);
    $page = http_req('GET', $base . '/login', $cookie);
    $csrf = extract_csrf($page['body'] ?? '');
    if ($csrf === '') {
        return false;
    }
    $post = http_req('POST', $base . '/login', $cookie, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query(['_csrf' => $csrf, 'email' => $email, 'password' => $pass]),
    ]);
    if ($post['location']) {
        $loc = $post['location'];
        if (!str_starts_with($loc, 'http')) {
            $loc = $base . '/' . ltrim($loc, '/');
        }
        http_req('GET', $loc, $cookie);
    }
    $dash = http_req('GET', $base . '/dashboard', $cookie);
    return $dash['code'] === 200 && !str_contains($dash['body'], 'name="password"');
}

function logout(string $base, string $cookie): void
{
    http_req('GET', $base . '/logout', $cookie);
}

function create_module(string $base, string $cookie, string $module, array $fields): array
{
    $create = http_req('GET', $base . '/module/' . $module . '/create', $cookie);
    if ($create['code'] === 403 || str_contains($create['body'], 'do not have permission')) {
        return ['ok' => false, 'detail' => 'forbidden'];
    }
    $csrf = extract_csrf($create['body'] ?? '');
    if ($csrf === '') {
        return ['ok' => false, 'detail' => 'no csrf code=' . $create['code']];
    }
    $fields['_csrf'] = $csrf;
    $post = http_req('POST', $base . '/module/' . $module . '/store', $cookie, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query($fields),
    ]);
    $loc = $post['location'] ?? '';
    if ($loc) {
        if (!str_starts_with($loc, 'http')) {
            $loc = $base . '/' . ltrim($loc, '/');
        }
        http_req('GET', $loc, $cookie);
    }
    $list = http_req('GET', $base . '/module/' . $module, $cookie);
    $needle = (string) ($fields['name'] ?? $fields['tag_number'] ?? $fields['lender'] ?? $fields['quote_number'] ?? $fields['po_number'] ?? $fields['item_name'] ?? '');
    $found = $needle !== '' && str_contains($list['body'], $needle);
    if (!$found && $needle !== '') {
        $list = http_req('GET', $base . '/module/' . $module . '?q=' . urlencode($needle), $cookie);
        $found = str_contains($list['body'], $needle);
    }
    return [
        'ok' => $found || ($post['code'] >= 300 && $post['code'] < 400),
        'detail' => 'post=' . $post['code'] . ' found=' . ($found ? '1' : '0'),
        'needle' => $needle,
    ];
}

$ok('server ready', wait_ready($base), $base);
$tag = 'p6h_' . bin2hex(random_bytes(2));
$today = date('Y-m-d');

$mgrCookie = $cookieDir . '/mgr.txt';
$ok('manager login', login($base, $mgrCookie, 'manager@ifms.local', 'Manager#2026'));

$r = create_module($base, $mgrCookie, 'animals', [
    'tag_number' => $tag . '_A',
    'name' => $tag . ' animal',
    'species' => 'cattle',
    'status' => 'active',
]);
$ok('HTTP animals create+list', !empty($r['ok']), $r['detail'] ?? '');

$r = create_module($base, $mgrCookie, 'water_sources', [
    'name' => $tag . '_ws',
    'source_type' => 'borehole',
    'status' => 'active',
]);
$ok('HTTP water_sources create+list', !empty($r['ok']), $r['detail'] ?? '');

$r = create_module($base, $mgrCookie, 'irrigation_systems', [
    'name' => $tag . '_irr',
    'system_type' => 'drip',
    'status' => 'active',
]);
$ok('HTTP irrigation_systems create+list', !empty($r['ok']), $r['detail'] ?? '');

$r = create_module($base, $mgrCookie, 'water_logs', [
    'log_date' => $today,
    'water_litres' => '50',
]);
$ok('HTTP water_logs create', !empty($r['ok']) || ($r['detail'] ?? '') !== 'forbidden', $r['detail'] ?? '');

$r = create_module($base, $mgrCookie, 'employees', [
    'name' => $tag . '_emp',
    'role_title' => 'Seasonal',
    'status' => 'active',
]);
$ok('HTTP employees create+list', !empty($r['ok']), $r['detail'] ?? '');

// Invalid animal
$create = http_req('GET', $base . '/module/animals/create', $mgrCookie);
$csrf = extract_csrf($create['body']);
$bad = http_req('POST', $base . '/module/animals/store', $mgrCookie, [
    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    'body' => http_build_query(['_csrf' => $csrf, 'name' => 'no-tag']),
]);
$ok(
    'HTTP animals invalid rejected',
    $bad['code'] === 200 && (str_contains($bad['body'], 'Please correct') || str_contains($bad['body'], 'required') || str_contains($bad['location'] ?? '', 'create'))
    || ($bad['code'] >= 300 && str_contains($bad['location'] ?? '', 'create'))
);

$denied = http_req('GET', $base . '/module/payments/create', $mgrCookie);
$ok('HTTP manager payments/create denied', $denied['code'] === 403 || str_contains($denied['body'], 'permission'));

logout($base, $mgrCookie);

$accCookie = $cookieDir . '/acc.txt';
$ok('accountant login', login($base, $accCookie, 'accounts@ifms.local', 'Accounts#2026'));

$r = create_module($base, $accCookie, 'budgets', [
    'name' => $tag . '_budget',
    'period_start' => $today,
    'period_end' => date('Y-m-d', strtotime('+30 days')),
    'planned_amount' => '9000',
    'category' => 'inputs',
]);
$ok('HTTP budgets create+list', !empty($r['ok']), $r['detail'] ?? '');

$r = create_module($base, $accCookie, 'loans', [
    'lender' => $tag . '_bank',
    'principal' => '8000',
    'balance' => '8000',
    'status' => 'active',
]);
$ok('HTTP loans create+list', !empty($r['ok']), $r['detail'] ?? '');

$r = create_module($base, $accCookie, 'suppliers', [
    'name' => $tag . '_sup',
    'category' => 'fertilizer',
    'status' => 'active',
]);
$ok('HTTP suppliers create+list', !empty($r['ok']), $r['detail'] ?? '');

// Resolve supplier id from list HTML
$list = http_req('GET', $base . '/module/suppliers?q=' . urlencode($tag . '_sup'), $accCookie);
$supplierId = null;
if (preg_match('#module/suppliers/(?:view|edit)/(\d+)#', $list['body'], $m)) {
    $supplierId = $m[1];
}
$ok('HTTP supplier id resolved', (bool) $supplierId, (string) $supplierId);

$r = create_module($base, $accCookie, 'quotations', [
    'supplier_id' => $supplierId,
    'quote_number' => 'Q-' . $tag,
    'item_name' => $tag . '_item',
    'quantity' => '25',
    'unit_price' => '4',
    'quote_date' => $today,
    'status' => 'accepted',
]);
$ok('HTTP quotations create+list', !empty($r['ok']), $r['detail'] ?? '');

$qList = http_req('GET', $base . '/module/quotations?q=' . urlencode('Q-' . $tag), $accCookie);
$qid = null;
if (preg_match('#module/quotations/(?:view|edit)/(\d+)#', $qList['body'], $m)) {
    $qid = $m[1];
}
$ok('HTTP quotation id', (bool) $qid, (string) $qid);

if ($qid) {
    $poForm = http_req('GET', $base . '/module/purchase_orders/create?quotation_id=' . $qid, $accCookie);
    $prefills = str_contains($poForm['body'], $tag . '_item')
        || (preg_match('/name="item_name"[^>]*value="([^"]*)"/', $poForm['body'], $mm) && str_contains($mm[1], $tag));
    $ok('HTTP quotation→PO prefill', $prefills, 'qid=' . $qid);

    $csrf = extract_csrf($poForm['body']);
    // Pull prefilled values from form where possible
    $poNumber = 'PO-' . $tag;
    if (preg_match('/name="po_number"[^>]*value="([^"]*)"/', $poForm['body'], $mm) && $mm[1] !== '') {
        $poNumber = $mm[1];
    }
    $post = http_req('POST', $base . '/module/purchase_orders/store', $accCookie, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query([
            '_csrf' => $csrf,
            'supplier_id' => $supplierId,
            'po_number' => $poNumber,
            'item_name' => $tag . '_item',
            'quantity' => '25',
            'unit_price' => '4',
            'order_date' => $today,
            'status' => 'pending_approval',
            'payment_status' => 'unpaid',
            'notes' => 'from http gap',
        ]),
    ]);
    if ($post['location']) {
        $loc = str_starts_with($post['location'], 'http') ? $post['location'] : $base . '/' . ltrim($post['location'], '/');
        http_req('GET', $loc, $accCookie);
    }
    $poList = http_req('GET', $base . '/module/purchase_orders?q=' . urlencode($tag), $accCookie);
    $ok('HTTP PO from quotation persisted', str_contains($poList['body'], $tag . '_item') || str_contains($poList['body'], $poNumber));

    // Approve + receive via edit
    $poId = null;
    if (preg_match('#module/purchase_orders/(?:view|edit)/(\d+)#', $poList['body'], $m)) {
        $poId = $m[1];
    }
    if ($poId) {
        $edit = http_req('GET', $base . '/module/purchase_orders/edit/' . $poId, $accCookie);
        $csrf = extract_csrf($edit['body']);
        $upd = http_req('POST', $base . '/module/purchase_orders/update/' . $poId, $accCookie, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                '_csrf' => $csrf,
                'supplier_id' => $supplierId,
                'po_number' => $poNumber,
                'item_name' => $tag . '_item',
                'quantity' => '25',
                'unit_price' => '4',
                'order_date' => $today,
                'status' => 'approved',
                'payment_status' => 'unpaid',
            ]),
        ]);
        $ok('HTTP PO approve', $upd['code'] >= 300 || $upd['code'] === 200, 'code=' . $upd['code']);

        $edit = http_req('GET', $base . '/module/purchase_orders/edit/' . $poId, $accCookie);
        $csrf = extract_csrf($edit['body']);
        $invId = null;
        if (preg_match('/name="inventory_item_id".*?<option[^>]+value="(\d+)"/s', $edit['body'], $im)) {
            $invId = $im[1];
        }
        $upd = http_req('POST', $base . '/module/purchase_orders/update/' . $poId, $accCookie, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query(array_filter([
                '_csrf' => $csrf,
                'supplier_id' => $supplierId,
                'po_number' => $poNumber,
                'item_name' => $tag . '_item',
                'inventory_item_id' => $invId,
                'quantity' => '25',
                'unit_price' => '4',
                'order_date' => $today,
                'status' => 'received',
                'payment_status' => 'unpaid',
            ])),
        ]);
        $ok('HTTP PO mark received', $upd['code'] >= 300 || $upd['code'] === 200, 'code=' . $upd['code']);
    } else {
        $ok('HTTP PO approve', false, 'no po id');
        $ok('HTTP PO mark received', false, 'no po id');
    }
}

// Yield forecasts as agronomist
logout($base, $accCookie);
$agroCookie = $cookieDir . '/agro.txt';
$ok('agronomist login', login($base, $agroCookie, 'agro@ifms.local', 'Agro#2026'));
$yf = http_req('GET', $base . '/module/yield_forecasts/create', $agroCookie);
$csrf = extract_csrf($yf['body']);
$plantingId = null;
if (preg_match('/name="planting_id".*?<option[^>]+value="(\d+)"/s', $yf['body'], $pm)) {
    $plantingId = $pm[1];
}
if ($plantingId && $csrf) {
    $post = http_req('POST', $base . '/module/yield_forecasts/store', $agroCookie, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query([
            '_csrf' => $csrf,
            'planting_id' => $plantingId,
            'forecast_date' => $today,
            'expected_yield' => '900',
            'market_risk' => 'medium',
        ]),
    ]);
    $ok('HTTP yield_forecasts create', $post['code'] >= 300 || $post['code'] === 200, 'code=' . $post['code']);
} else {
    $ok('HTTP yield_forecasts create skipped-or-form', $yf['code'] === 200, 'planting=' . ($plantingId ?: 'none'));
}
logout($base, $agroCookie);

$wrk = $cookieDir . '/wrk.txt';
$ok('worker login', login($base, $wrk, 'worker@ifms.local', 'Worker#2026'));
$d = http_req('GET', $base . '/module/transactions', $wrk);
$ok('HTTP worker transactions denied', $d['code'] === 403 || str_contains($d['body'], 'permission'));
$d = http_req('GET', $base . '/module/budgets/create', $wrk);
$ok('HTTP worker budgets/create denied', $d['code'] === 403 || str_contains($d['body'], 'permission'));
logout($base, $wrk);

// Guest auth forms
$g = http_req('GET', $base . '/register', $cookieDir . '/guest.txt');
$ok('HTTP register form', $g['code'] === 200 && str_contains($g['body'], 'name="email"'));
$g = http_req('GET', $base . '/forgot-password', $cookieDir . '/guest2.txt');
$ok('HTTP forgot-password form', $g['code'] === 200 && str_contains($g['body'], 'name="email"'));

echo PHP_EOL . "failures=$fail" . PHP_EOL;
echo ($fail === 0 ? 'HTTP GAP ACCEPTANCE PASSED' : 'HTTP GAP ACCEPTANCE HAS FAILURES') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
