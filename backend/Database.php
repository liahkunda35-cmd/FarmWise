<?php

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo;
    private string $driver;
    private ?array $tableNames = null;
    private bool $reconnecting = false;

    public function __construct(PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public static function boot(): Database
    {
        if (self::$instance) {
            return self::$instance;
        }
        $cfg = config();
        $driver = strtolower((string) ($cfg['db_driver'] ?? 'sqlite'));
        // Treat "pgsql" / "postgresql" / "neon" as postgres
        if (in_array($driver, ['pgsql', 'postgresql', 'neon', 'postgres'], true)) {
            $driver = 'pgsql';
        }

        if ($driver === 'pgsql') {
            $pdo = self::connectPostgres($cfg);
        } elseif ($driver === 'mysql') {
            $m = $cfg['mysql'];
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $m['host'], $m['port'], $m['database'], $m['charset']);
            $pdo = new PDO($dsn, $m['username'], $m['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $driver = 'sqlite';
            $path = $cfg['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        self::$instance = new self($pdo, $driver);
        return self::$instance;
    }

    private static function connectPostgres(array $cfg): PDO
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('PHP pdo_pgsql extension is required for Neon/Postgres. Enable extension=pdo_pgsql in php.ini.');
        }

        // Collect every configured URL. Older XAMPP libpq often lacks SNI — Neon pooler then
        // requires options=endpoint=… and the direct (unpooled) host may fail; try all.
        $urlKeys = [
            'DATABASE_URL_POOLED',
            'DATABASE_URL',
            'IFMS_DATABASE_URL',
            'DATABASE_URL_UNPOOLED',
        ];
        $urls = [];
        foreach ($urlKeys as $key) {
            $u = getenv($key) ?: '';
            if ($u !== '' && !in_array($u, $urls, true)) {
                $urls[] = $u;
            }
        }
        $cfgUrl = (string) ($cfg['database_url'] ?? '');
        if ($cfgUrl !== '' && !in_array($cfgUrl, $urls, true)) {
            $urls[] = $cfgUrl;
        }

        /** @var list<array{host:string,port:int,dbname:string,user:?string,pass:?string,sslmode:string}> $targets */
        $targets = [];
        foreach ($urls as $url) {
            $parts = parse_url($url);
            if ($parts === false || empty($parts['host'])) {
                continue;
            }
            $sslmode = 'require';
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['sslmode'])) {
                    $sslmode = (string) $q['sslmode'];
                }
            }
            $base = [
                'host' => (string) $parts['host'],
                'port' => (int) ($parts['port'] ?? 5432),
                'dbname' => ltrim($parts['path'] ?? '/neondb', '/') ?: 'neondb',
                'user' => isset($parts['user']) ? urldecode($parts['user']) : null,
                'pass' => isset($parts['pass']) ? urldecode($parts['pass']) : null,
                'sslmode' => $sslmode,
            ];
            foreach (self::neonHostVariants($base['host']) as $host) {
                $row = $base;
                $row['host'] = $host;
                $targets[] = $row;
            }
        }

        // Local Neon TLS proxy (127.0.0.1): XAMPP libpq cannot speak modern TLS to neon.tech.
        // If a localhost target exists, never fall through to remote Neon hosts (those hang).
        $localTargets = array_values(array_filter(
            $targets,
            static fn (array $t) => in_array($t['host'], ['127.0.0.1', 'localhost'], true)
        ));
        if ($localTargets) {
            $targets = $localTargets;
        }

        if (!$targets) {
            $p = $cfg['pgsql'] ?? [];
            $host = (string) ($p['host'] ?? '127.0.0.1');
            foreach (self::neonHostVariants($host) as $h) {
                $targets[] = [
                    'host' => $h,
                    'port' => (int) ($p['port'] ?? 5432),
                    'dbname' => (string) ($p['database'] ?? 'neondb'),
                    'user' => $p['username'] ?? null,
                    'pass' => $p['password'] ?? null,
                    'sslmode' => (string) ($p['sslmode'] ?? 'require'),
                ];
            }
        }

        $preferred = @file_get_contents(dirname(__DIR__) . '/storage/.db_host');
        $preferred = is_string($preferred) ? trim($preferred) : '';
        usort($targets, static function (array $a, array $b) use ($preferred): int {
            if ($preferred !== '') {
                return ($a['host'] === $preferred ? 0 : 1) <=> ($b['host'] === $preferred ? 0 : 1);
            }
            $ap = str_contains($a['host'], '-pooler.') ? 0 : 1;
            $bp = str_contains($b['host'], '-pooler.') ? 0 : 1;
            return $ap <=> $bp;
        });

        // De-dupe by host+port+dbname
        $seen = [];
        $unique = [];
        foreach ($targets as $t) {
            $k = $t['host'] . '|' . $t['port'] . '|' . $t['dbname'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $unique[] = $t;
        }
        $targets = $unique;

        if (!$targets) {
            throw new RuntimeException('No Postgres connection settings found. Set DATABASE_URL or PGHOST.');
        }

        // Non-persistent: Neon serverless + PHP built-in server hang less with fresh connections.
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => 10,
            PDO::ATTR_PERSISTENT => false,
        ];

        $last = null;
        // Prefer the last known-good host first (avoids multi-host timeouts on every request).
        $ordered = $targets;
        if ($preferred !== '') {
            usort($ordered, static function (array $a, array $b) use ($preferred): int {
                return ($a['host'] === $preferred ? 0 : 1) <=> ($b['host'] === $preferred ? 0 : 1);
            });
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Attempt 1: preferred host (fast path). Later attempts: all hosts for cold wake / failover.
            $batch = ($attempt === 1 && $preferred !== '')
                ? array_values(array_filter($ordered, static fn (array $t) => $t['host'] === $preferred))
                : $ordered;
            if (!$batch) {
                $batch = $ordered;
            }
            foreach ($batch as $t) {
                // channel_binding is not a PDO DSN option — omit it
                $dsnBase = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=8',
                    $t['host'],
                    $t['port'],
                    $t['dbname'],
                    $t['sslmode']
                );
                $endpoint = self::neonEndpointId($t['host']);
                // XAMPP libpq often lacks SNI — try with endpoint= first, then without.
                $dsns = [];
                if ($endpoint) {
                    $dsns[] = $dsnBase . ";options='endpoint=" . $endpoint . "'";
                }
                $dsns[] = $dsnBase;
                foreach ($dsns as $dsn) {
                    try {
                        $pdo = new PDO($dsn, $t['user'], $t['pass'], $options);
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        @file_put_contents(dirname(__DIR__) . '/storage/.db_host', $t['host']);
                        return $pdo;
                    } catch (PDOException $e) {
                        $last = $e;
                    }
                }
            }
            $msg = strtolower($last ? $last->getMessage() : '');
            $transient = str_contains($msg, 'closed the connection')
                || str_contains($msg, 'connection timed out')
                || str_contains($msg, 'could not connect')
                || str_contains($msg, 'server closed')
                || str_contains($msg, 'connection refused')
                || str_contains($msg, 'timeout expired')
                || str_contains($msg, 'remaining connection slots')
                || str_contains($msg, 'the database system is starting')
                || str_contains($msg, 'endpoint id is not specified')
                || str_contains($msg, '08006');
            if (!$transient || $attempt >= 3) {
                break;
            }
            usleep(400000 * $attempt);
        }
        throw $last ?? new RuntimeException('Could not connect to Postgres.');
    }

    /** @return list<string> */
    private static function neonHostVariants(string $host): array
    {
        $hosts = [$host];
        if (!str_contains($host, 'neon.tech')) {
            return $hosts;
        }
        // Pooler ↔ direct compute variants
        if (str_contains($host, '-pooler.')) {
            $hosts[] = str_replace('-pooler.', '.', $host);
        } elseif (preg_match('/^(ep-[^.]+)\.(.+)$/', $host, $m)) {
            $hosts[] = $m[1] . '-pooler.' . $m[2];
        }
        return array_values(array_unique($hosts));
    }

    /** Extract Neon endpoint id from host like ep-xxx-pooler.region.aws.neon.tech */
    private static function neonEndpointId(string $host): ?string
    {
        if (!str_contains($host, 'neon.tech')) {
            return null;
        }
        $first = explode('.', $host)[0] ?? '';
        // ep-patient-smoke-b59eooh2-pooler → ep-patient-smoke-b59eooh2
        if (str_ends_with($first, '-pooler')) {
            $first = substr($first, 0, -strlen('-pooler'));
        }
        return $first !== '' ? $first : null;
    }

    public function pdo(): PDO
    {
        if (!$this->pdo) {
            throw new RuntimeException('The database connection is closed.');
        }
        return $this->pdo;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

    public function rollBack(): bool
    {
        if ($this->pdo()->inTransaction()) {
            return $this->pdo()->rollBack();
        }
        return false;
    }

    public static function close(): void
    {
        if (self::$instance) {
            self::$instance->pdo = null;
            self::$instance = null;
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($params));
            return $stmt;
        } catch (PDOException $e) {
            if ($this->reconnecting || !$this->connectionLost($e)) {
                throw $e;
            }
            $this->reconnecting = true;
            $this->tableNames = null;
            $this->pdo = self::connectPostgres(config());
            $this->reconnecting = false;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($params));
            return $stmt;
        }
    }

    private function connectionLost(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'closed the connection')
            || str_contains($msg, 'server closed')
            || str_contains($msg, 'could not connect')
            || str_contains($msg, 'connection timed out')
            || str_contains($msg, 'no connection to the server')
            || str_contains($msg, 'ssl connection has been closed');
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    public function value(string $sql, array $params = [])
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    public function insert(string $table, array $data): int
    {
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        if ($this->driver === 'pgsql') {
            $sql .= ' RETURNING id';
            $row = $this->query($sql, array_values($data))->fetch(PDO::FETCH_ASSOC);
            session_memo_flush();
            return (int) ($row['id'] ?? 0);
        }
        $this->query($sql, array_values($data));
        session_memo_flush();
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, $id): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $col . ' = ?';
        }
        $params = array_values($data);
        $params[] = $id;
        $this->query('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        session_memo_flush();
    }

    public function delete(string $table, $id): void
    {
        $this->query('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
        session_memo_flush();
    }

    public function find(string $table, $id): ?array
    {
        return $this->first('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $params);
    }

    public function tables(): array
    {
        if ($this->tableNames !== null) {
            return $this->tableNames;
        }
        if ($this->driver === 'pgsql') {
            $rows = $this->select("SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename");
            return $this->tableNames = array_column($rows, 'name');
        }
        if ($this->driver === 'mysql') {
            $rows = $this->select('SHOW TABLES');
            return $this->tableNames = array_map(fn($r) => array_values($r)[0], $rows);
        }
        $rows = $this->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        return $this->tableNames = array_column($rows, 'name');
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function columns(string $table): array
    {
        if ($this->driver === 'pgsql') {
            return array_column(
                $this->select(
                    "SELECT column_name AS name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position",
                    [$table]
                ),
                'name'
            );
        }
        if ($this->driver === 'mysql') {
            return array_column($this->select('SHOW COLUMNS FROM ' . $table), 'Field');
        }
        return array_column($this->select('PRAGMA table_info(' . $table . ')'), 'name');
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    public function addColumn(string $table, string $column, string $type): void
    {
        if ($this->hasColumn($table, $column)) {
            return;
        }
        $mapped = $type;
        if ($this->driver === 'pgsql') {
            $mapped = preg_replace('/ENUM\([^)]+\)/', 'VARCHAR(40)', $type) ?? $type;
            $mapped = str_replace(['DATETIME', 'LONGTEXT'], ['TIMESTAMP', 'TEXT'], $mapped);
        } elseif ($this->driver !== 'mysql') {
            $mapped = preg_replace('/ENUM\([^)]+\)/', 'VARCHAR(40)', $type) ?? $type;
        }
        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $mapped);
    }
}

function db(): Database
{
    return Database::boot();
}
