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

        // Prefer DATABASE_URL / DATABASE_URL_POOLED (Neon deploy), then IFMS_DATABASE_URL, then discrete vars
        $url = getenv('DATABASE_URL_POOLED')
            ?: getenv('DATABASE_URL')
            ?: getenv('IFMS_DATABASE_URL')
            ?: (string) ($cfg['database_url'] ?? '');

        $user = null;
        $pass = null;
        if ($url !== '') {
            $parts = parse_url($url);
            if ($parts === false || empty($parts['host'])) {
                throw new RuntimeException('Invalid DATABASE_URL for Postgres.');
            }
            $host = $parts['host'];
            $port = $parts['port'] ?? 5432;
            $dbname = ltrim($parts['path'] ?? '/neondb', '/');
            $user = isset($parts['user']) ? urldecode($parts['user']) : null;
            $pass = isset($parts['pass']) ? urldecode($parts['pass']) : null;
            $sslmode = 'require';
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['sslmode'])) {
                    $sslmode = $q['sslmode'];
                }
            }
            // channel_binding is not a PDO DSN option; omit it.
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, (int) $port, $dbname, $sslmode);
        } else {
            $p = $cfg['pgsql'] ?? [];
            $host = $p['host'] ?? '127.0.0.1';
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                $host,
                $p['port'] ?? '5432',
                $p['database'] ?? 'neondb',
                $p['sslmode'] ?? 'require'
            );
            $user = $p['username'] ?? null;
            $pass = $p['password'] ?? null;
        }

        // Older Windows libpq lacks SNI — Neon needs endpoint id (https://neon.com/sni)
        $endpoint = self::neonEndpointId($host ?? '');
        if ($endpoint && !str_contains($dsn, 'options=')) {
            $dsn .= ";options='endpoint=" . $endpoint . "'";
        }

        // Emulate prepares: 1 RTT per query on Neon instead of prepare+execute
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => 15,
        ]);
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
