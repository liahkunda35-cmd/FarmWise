<?php
declare(strict_types=1);

// Minimal Neon wake probe — no Schema / full bootstrap.
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
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

$url = getenv('DATABASE_URL_POOLED') ?: getenv('DATABASE_URL') ?: '';
echo "url_set=" . ($url !== '' ? 'yes' : 'no') . PHP_EOL;
$p = parse_url($url);
$host = $p['host'] ?? '';
$port = (int) ($p['port'] ?? 5432);
$user = $p['user'] ?? '';
$pass = $p['pass'] ?? '';
$db = ltrim((string) ($p['path'] ?? '/neondb'), '/');
parse_str($p['query'] ?? '', $q);
echo "host={$host} port={$port} db={$db}" . PHP_EOL;
echo "query=" . json_encode($q) . PHP_EOL;

$attempts = [
    'sslmode=require' => "pgsql:host={$host};port={$port};dbname={$db};sslmode=require;connect_timeout=8",
    'sslmode=prefer' => "pgsql:host={$host};port={$port};dbname={$db};sslmode=prefer;connect_timeout=8",
    'sslmode=disable' => "pgsql:host={$host};port={$port};dbname={$db};sslmode=disable;connect_timeout=5",
];

foreach ($attempts as $label => $dsn) {
    $t0 = microtime(true);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        $ok = $pdo->query('SELECT 1 AS ok')->fetch(PDO::FETCH_ASSOC);
        $ms = (int) ((microtime(true) - $t0) * 1000);
        echo "OK {$label} in {$ms}ms => " . json_encode($ok) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        $ms = (int) ((microtime(true) - $t0) * 1000);
        echo "FAIL {$label} in {$ms}ms: " . $e->getMessage() . PHP_EOL;
    }
}
exit(1);
