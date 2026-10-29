<?php
declare(strict_types=1);

/**
 * Low-level Neon connection diagnostic (no secrets printed).
 * Does not load full bootstrap/schema — only env + PDO.
 */

$root = dirname(__DIR__);
foreach (['.env', '.env.local'] as $file) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

echo 'pdo_pgsql=' . (extension_loaded('pdo_pgsql') ? 'yes' : 'NO') . PHP_EOL;
echo 'IFMS_DB_DRIVER=' . (getenv('IFMS_DB_DRIVER') ?: '(unset)') . PHP_EOL;

$candidates = [];
foreach (['DATABASE_URL_UNPOOLED', 'DATABASE_URL', 'DATABASE_URL_POOLED'] as $k) {
    $u = getenv($k) ?: '';
    if ($u === '') {
        echo "$k=(empty)" . PHP_EOL;
        continue;
    }
    $p = parse_url($u);
    $host = $p['host'] ?? '';
    $port = (int) ($p['port'] ?? 5432);
    $db = ltrim($p['path'] ?? '', '/');
    $user = isset($p['user']) ? urldecode($p['user']) : '';
    $pass = isset($p['pass']) ? urldecode($p['pass']) : '';
    $ssl = 'require';
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        $ssl = $q['sslmode'] ?? $ssl;
    }
    echo "$k host=$host port=$port db=$db user=$user sslmode=$ssl pass_len=" . strlen($pass) . PHP_EOL;
    $candidates[$k] = compact('host', 'port', 'db', 'user', 'pass', 'ssl');
}

if (!$candidates) {
    fwrite(STDERR, "No DATABASE_URL* set.\n");
    exit(1);
}

function neonEndpointId(string $host): ?string
{
    if (!str_contains($host, 'neon.tech')) {
        return null;
    }
    $first = explode('.', $host)[0] ?? '';
    if (str_ends_with($first, '-pooler')) {
        $first = substr($first, 0, -strlen('-pooler'));
    }
    return $first !== '' ? $first : null;
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
    PDO::ATTR_TIMEOUT => 30,
];

foreach ($candidates as $label => $c) {
    $hosts = [$c['host']];
    if (str_contains($c['host'], '-pooler.')) {
        $hosts[] = str_replace('-pooler.', '.', $c['host']);
    }
    foreach ($hosts as $tryHost) {
        $dsnBase = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $tryHost, $c['port'], $c['db'], $c['ssl']);
        $variants = [$dsnBase];
        $ep = neonEndpointId($tryHost);
        if ($ep) {
            $variants[] = $dsnBase . ";options='endpoint=" . $ep . "'";
        }
        foreach ($variants as $i => $dsn) {
            $safeDsn = preg_replace('/sslmode=\w+/', 'sslmode=***', $dsn);
            echo "TRY $label host=$tryHost variant=$i ... " ;
            try {
                $pdo = new PDO($dsn, $c['user'], $c['pass'], $options);
                $v = $pdo->query('SELECT version(), current_database(), inet_server_addr()')->fetch(PDO::FETCH_NUM);
                echo "OK\n";
                echo "  version=" . substr((string) $v[0], 0, 70) . PHP_EOL;
                echo "  database=" . $v[1] . PHP_EOL;
                echo "  server_addr=" . ($v[2] ?? '(null)') . PHP_EOL;
                echo "SUCCESS via $label host=$tryHost variant=$i\n";
                exit(0);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                // scrub any accidental secrets
                $msg = preg_replace('/password=[^\s]+/i', 'password=***', $msg) ?? $msg;
                echo "FAIL\n  " . str_replace(["\n", "\r"], ' ', $msg) . PHP_EOL;
            }
        }
    }
}

fwrite(STDERR, "All connection attempts failed.\n");
exit(1);
