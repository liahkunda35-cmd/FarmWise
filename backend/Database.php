<?php

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo;
    private string $driver;

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

        // Prefer Neon pooler hosts first (works with endpoint option on older libpq)
        usort($targets, static function (array $a, array $b): int {
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

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => 20,
        ];

        $last = null;
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            foreach ($targets as $t) {
                // channel_binding is not a PDO DSN option — omit it
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                    $t['host'],
                    $t['port'],
                    $t['dbname'],
                    $t['sslmode']
                );
                $endpoint = self::neonEndpointId($t['host']);
                // Neon pooler without SNI (common on Windows/XAMPP libpq) requires endpoint=
                if ($endpoint) {
                    $dsn .= ";options='endpoint=" . $endpoint . "'";
                }
                try {
                    $pdo = new PDO($dsn, $t['user'], $t['pass'], $options);
                    $pdo->query('SELECT 1');
                    return $pdo;
                } catch (PDOException $e) {
                    $last = $e;
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
                || str_contains($msg, 'endpoint id is not specified');
            if (!$transient || $attempt >= 6) {
                break;
            }
            usleep(1000000 * $attempt);
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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
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
            return (int) ($row['id'] ?? 0);
        }
        $this->query($sql, array_values($data));
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
    }

    public function delete(string $table, $id): void
    {
        $this->query('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
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
        if ($this->driver === 'pgsql') {
            $rows = $this->select("SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename");
            return array_column($rows, 'name');
        }
        if ($this->driver === 'mysql') {
            $rows = $this->select('SHOW TABLES');
            return array_map(fn($r) => array_values($r)[0], $rows);
        }
        $rows = $this->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        return array_column($rows, 'name');
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
