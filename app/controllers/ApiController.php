<?php

class ApiController
{
    public static function dispatch(array $parts): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        if (request_method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        $action = $parts[1] ?? '';
        try {
            if ($action === 'login' && is_post()) {
                self::login();
                return;
            }
            $user = self::requireToken();
            Auth::setOverride($user);
            match ($action) {
                'me' => self::ok(['user' => self::publicUser($user)]),
                'dashboard' => self::dashboard(),
                'weather' => self::weather(),
                'notifications' => self::notifications(),
                'geocode' => self::geocode(),
                'module' => self::module($parts[2] ?? '', $parts[3] ?? null),
                default => self::fail(404, 'Unknown API route. Use /api/login, /api/me, /api/dashboard, /api/weather, /api/notifications, /api/geocode, /api/module/{name}.'),
            };
        } catch (Throwable $e) {
            self::fail(400, $e->getMessage());
        }
    }

    private static function login(): void
    {
        $body = self::json();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $user = db()->first('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            self::fail(401, 'Invalid credentials.');
        }
        $token = Jwt::encode([
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'farm_id' => $user['farm_id'],
        ]);
        Audit::log('api_login', 'auth', $user['id'], 'JWT issued');
        self::ok(['token' => $token, 'token_type' => 'Bearer', 'expires_in' => 86400, 'user' => self::publicUser($user)]);
    }

    private static function requireToken(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$header && function_exists('apache_request_headers')) {
            $hdrs = apache_request_headers();
            $header = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
        }
        if (!preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            self::fail(401, 'Missing Bearer token.');
        }
        $payload = Jwt::decode($m[1]);
        if (!$payload || empty($payload['sub'])) {
            self::fail(401, 'Invalid or expired token.');
        }
        $user = db()->find('users', $payload['sub']);
        if (!$user || $user['status'] !== 'active') {
            self::fail(401, 'Account is not active.');
        }
        return $user;
    }

    private static function dashboard(): void
    {
        Auth::authorize('dashboard', 'view');
        $farmSql = Auth::isAdmin() || !Auth::farmId() ? '1=1' : 'farm_id = ' . (int) Auth::farmId();
        self::ok([
            'acreage' => (float) db()->value('SELECT COALESCE(SUM(total_area),0) FROM farms' . (Auth::isAdmin() || !Auth::farmId() ? '' : ' WHERE id=' . (int) Auth::farmId())),
            'active_crops' => (int) db()->value("SELECT COUNT(*) FROM plantings WHERE {$farmSql} AND status IN ('planted','growing')"),
            'livestock' => (int) db()->value("SELECT COUNT(*) FROM animals WHERE {$farmSql} AND status='active'"),
            'revenue' => (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} AND type='income'"),
            'expenses' => (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} AND type='expense'"),
            'alerts' => (int) db()->value("SELECT COUNT(*) FROM notifications WHERE is_read=0 AND ({$farmSql} OR farm_id IS NULL)"),
        ]);
    }

    private static function weather(): void
    {
        Auth::authorize('weather', 'view');
        $farm = Auth::farmId() ? db()->find('farms', Auth::farmId()) : db()->first('SELECT * FROM farms ORDER BY id');
        $forecast = $farm ? WeatherService::fetchForFarm($farm) : null;
        self::ok(['farm' => $farm['name'] ?? null, 'current' => $forecast['current'] ?? null, 'daily' => $forecast['daily'] ?? null]);
    }

    private static function notifications(): void
    {
        Auth::authorize('notifications', 'view');
        NotificationService::syncAlerts();
        $sql = 'SELECT id, category, title, message, severity, is_read, created_at FROM notifications WHERE 1=1';
        $params = [];
        if (!Auth::isAdmin() && Auth::farmId()) {
            $sql .= ' AND (farm_id = ? OR farm_id IS NULL)';
            $params[] = Auth::farmId();
        }
        self::ok(['items' => db()->select($sql . ' ORDER BY id DESC LIMIT 50', $params)]);
    }

    private static function geocode(): void
    {
        $q = input('q', '');
        $lat = input('lat');
        $lon = input('lon');
        $url = $q
            ? 'https://nominatim.openstreetmap.org/search?format=json&limit=6&q=' . urlencode($q)
            : 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' . urlencode((string) $lat) . '&lon=' . urlencode((string) $lon);
        $json = self::nominatim($url);
        self::ok(['results' => json_decode($json, true) ?: []]);
    }

    public static function nominatim(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: IFMS-Farm-Management/1.0\r\n",
                'timeout' => 8,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        return $res !== false ? $res : '[]';
    }

    private static function module(string $key, $id): void
    {
        $def = module_def($key);
        if (!$def) {
            self::fail(404, 'Unknown module.');
        }
        $action = is_post() ? ($id ? 'edit' : 'create') : 'view';
        Auth::authorize($key, $action);
        $table = $def['table'];
        if (request_method() === 'GET' && !$id) {
            $where = '1=1';
            $params = [];
            if (!empty($def['farm_scoped']) && !Auth::isAdmin() && Auth::farmId()) {
                $where .= ' AND farm_id = ?';
                $params[] = Auth::farmId();
            }
            self::ok(['items' => db()->select('SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 100', $params)]);
        }
        if (request_method() === 'GET') {
            $row = db()->find($table, $id);
            if (!$row || !Auth::canAccessRecord($row)) {
                self::fail(404, 'Record not found.');
            }
            self::ok(['item' => $row]);
        }
        if (is_post()) {
            $payload = self::json();
            if (!Auth::isAdmin() && Auth::farmId() && isset($def['fields']['farm_id'])) {
                $payload['farm_id'] = Auth::farmId();
            }
            if ($id) {
                $row = db()->find($table, $id);
                if (!$row || !Auth::canAccessRecord($row)) {
                    self::fail(404, 'Record not found.');
                }
                db()->update($table, $payload, $id);
                self::ok(['item' => db()->find($table, $id)]);
            }
            $newId = db()->insert($table, $payload);
            self::ok(['item' => db()->find($table, $newId)], 201);
        }
        self::fail(405, 'Method not allowed.');
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'farm_id' => $user['farm_id'],
            'phone' => $user['phone'],
        ];
    }

    private static function ok($data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode(['ok' => true, 'data' => $data]);
        exit;
    }

    private static function fail(int $code, string $message): void
    {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
}
