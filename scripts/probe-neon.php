<?php
declare(strict_types=1);

/**
 * Sanitized Neon/Postgres connectivity + schema probe.
 * Never prints passwords or full connection URLs.
 */
require dirname(__DIR__) . '/backend/bootstrap.php';

$fail = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$driver = db()->driver();
$ok('driver is pgsql', $driver === 'pgsql', 'driver=' . $driver);

$pdo = db()->pdo();
$ver = (string) $pdo->query('SELECT version()')->fetchColumn();
$isPg = stripos($ver, 'PostgreSQL') !== false;
$ok('server is PostgreSQL', $isPg, substr($ver, 0, 60));

$dbName = (string) $pdo->query('SELECT current_database()')->fetchColumn();
$user = (string) $pdo->query('SELECT current_user')->fetchColumn();
$hostEnv = (string) (getenv('PGHOST') ?: '');
$urlHost = '';
foreach (['DATABASE_URL_UNPOOLED', 'DATABASE_URL', 'DATABASE_URL_POOLED'] as $k) {
    $u = (string) (getenv($k) ?: '');
    if ($u !== '') {
        $p = parse_url($u);
        $urlHost = (string) ($p['host'] ?? '');
        break;
    }
}
$resolvedHost = $urlHost !== '' ? $urlHost : $hostEnv;
$isNeon = str_contains(strtolower($resolvedHost), 'neon.tech');
$ok('connects to Neon host', $isNeon, 'host=' . $resolvedHost);
$ok('database name neondb (or configured)', $dbName !== '', 'database=' . $dbName . ' user=' . $user);
$ok('not sqlite fallback', $driver !== 'sqlite' && !str_contains(strtolower($ver), 'sqlite'));
$ok('pdo_pgsql loaded', extension_loaded('pdo_pgsql'));

$configured = [];
foreach (['DATABASE_URL_POOLED', 'DATABASE_URL', 'DATABASE_URL_UNPOOLED', 'IFMS_DATABASE_URL'] as $k) {
    if (getenv($k)) {
        $configured[] = $k;
    }
}
echo 'INFO configured URL keys: ' . ($configured ? implode(', ', $configured) : '(none — using PG*)') . PHP_EOL;
echo 'INFO app tries Neon pooler hosts first (endpoint option for older libpq), then direct' . PHP_EOL;
echo 'INFO IFMS_DB_DRIVER=' . (getenv('IFMS_DB_DRIVER') ?: '(unset)') . PHP_EOL;
echo 'INFO NEON_BRANCH=' . (getenv('NEON_BRANCH') ?: '(unset)') . PHP_EOL;
echo 'INFO env PGHOST=' . (getenv('PGHOST') ?: '(unset)') . PHP_EOL;

$required = [
    'users', 'farms', 'user_farms', 'employees', 'tasks', 'notifications',
    'inventory_items', 'stock_movements', 'harvests', 'warehouses', 'storage_lots',
    'customers', 'sales_orders', 'invoices', 'payments', 'dispatches', 'transactions',
    'plantings', 'fields', 'crop_varieties', 'fertilizer_applications',
];
$existing = db()->select(
    "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'"
);
$names = array_map(static fn ($r) => $r['tablename'], $existing);
foreach ($required as $t) {
    $ok('table ' . $t, in_array($t, $names, true));
}

$colsTasks = db()->columns('tasks');
foreach (['related_module', 'related_id', 'created_by'] as $c) {
    $ok('tasks.' . $c, in_array($c, $colsTasks, true));
}
$colsSm = db()->columns('stock_movements');
foreach (['created_by', 'task_id', 'reference'] as $c) {
    $ok('stock_movements.' . $c, in_array($c, $colsSm, true));
}
$colsSo = db()->columns('sales_orders');
$ok('sales_orders.storage_lot_id', in_array('storage_lot_id', $colsSo, true));
$colsDp = db()->columns('dispatches');
$ok('dispatches.sales_order_id', in_array('sales_order_id', $colsDp, true));

// Indexes (best-effort)
$idx = db()->select(
    "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename IN ('stock_movements','storage_lots','tasks')"
);
$idxNames = array_map(static fn ($r) => $r['indexname'], $idx);
echo 'INFO indexes sample: ' . implode(', ', array_slice($idxNames, 0, 12)) . PHP_EOL;

// Persistence round-trip
$tag = 'neon_probe_' . bin2hex(random_bytes(3));
$farm = db()->first('SELECT id FROM farms ORDER BY id ASC');
$ok('farm available for probe', (bool) $farm);
if ($farm) {
    $farmId = (int) $farm['id'];
    $taskId = (int) db()->insert('tasks', [
        'farm_id' => $farmId,
        'title' => $tag,
        'description' => 'neon persistence probe',
        'status' => 'pending',
        'priority' => 'medium',
        'due_date' => date('Y-m-d'),
    ]);
    $again = db()->first('SELECT id, title FROM tasks WHERE id = ? AND title = ?', [$taskId, $tag]);
    $ok('task insert readable from Neon', (bool) $again, 'id=' . $taskId);

    // Prove not writing to local sqlite: sqlite file should not gain this title
    $sqlitePath = config('sqlite_path');
    $sqliteHas = false;
    if (is_file($sqlitePath) && extension_loaded('pdo_sqlite')) {
        try {
            $sq = new PDO('sqlite:' . $sqlitePath);
            $stmt = $sq->prepare('SELECT COUNT(*) FROM tasks WHERE title = ?');
            $stmt->execute([$tag]);
            $sqliteHas = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $sqliteHas = false;
        }
    }
    $ok('probe task not in local sqlite', !$sqliteHas);

    db()->delete('tasks', $taskId);
    $gone = db()->first('SELECT id FROM tasks WHERE id = ?', [$taskId]);
    $ok('probe task cleaned up', !$gone);
}

echo PHP_EOL . ($fail === 0 ? 'ALL PASSED' : "FAILURES: $fail") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
