<?php

class Auth
{
    private static ?array $override = null;
    private static ?array $cached = null;
    private static $cachedId = null;

    public static function setOverride(?array $user): void
    {
        self::$override = $user;
        self::$cached = $user;
        self::$cachedId = $user['id'] ?? null;
    }

    public static function user(): ?array
    {
        if (self::$override) {
            return self::$override;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        if (self::$cached && self::$cachedId === $id) {
            return self::$cached;
        }
        self::$cached = db()->find('users', $id);
        self::$cachedId = $id;
        return self::$cached;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null && ($u = self::user()) && $u['status'] === 'active';
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function farmId(): ?int
    {
        if (!empty($_SESSION['active_farm_id'])) {
            $fid = (int) $_SESSION['active_farm_id'];
            if ($fid > 0 && self::canAccessFarm($fid)) {
                return $fid;
            }
        }
        $u = self::user();
        return $u && $u['farm_id'] ? (int) $u['farm_id'] : null;
    }

    public static function homeFarmId(): ?int
    {
        $u = self::user();
        return $u && $u['farm_id'] ? (int) $u['farm_id'] : null;
    }

    public static function accessibleFarms(): array
    {
        if (!self::check()) {
            return [];
        }
        if (self::isAdmin()) {
            return db()->select('SELECT * FROM farms ORDER BY name');
        }
        $u = self::user();
        if (self::role() === 'owner') {
            return db()->select(
                'SELECT * FROM farms WHERE owner_id = ? OR id = ? ORDER BY name',
                [self::id(), $u['farm_id'] ?: 0]
            );
        }
        if (!empty($u['farm_id'])) {
            $row = db()->find('farms', $u['farm_id']);
            return $row ? [$row] : [];
        }
        return [];
    }

    public static function canAccessFarm(int $farmId): bool
    {
        if ($farmId <= 0) {
            return false;
        }
        foreach (self::accessibleFarms() as $farm) {
            if ((int) $farm['id'] === $farmId) {
                return true;
            }
        }
        return false;
    }

    public static function setActiveFarm(?int $farmId): void
    {
        if (!$farmId) {
            unset($_SESSION['active_farm_id']);
            return;
        }
        if (!self::canAccessFarm($farmId)) {
            return;
        }
        $_SESSION['active_farm_id'] = $farmId;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function findByLogin(string $identity): ?array
    {
        $identity = trim($identity);
        if ($identity === '') {
            return null;
        }
        $email = strtolower($identity);
        $user = db()->first('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user) {
            return $user;
        }
        $matches = db()->select('SELECT * FROM users WHERE lower(name) = ?', [strtolower($identity)]);
        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function login(string $identity, string $password, bool $remember = false): bool
    {
        $user = self::findByLogin($identity);
        if (!$user || $user['status'] !== 'active') {
            return false;
        }
        if (!password_verify($password, $user['password'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        if (!empty($user['farm_id'])) {
            $_SESSION['active_farm_id'] = (int) $user['farm_id'];
        } else {
            unset($_SESSION['active_farm_id']);
        }
        if ($remember) {
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires' => time() + 60 * 60 * 24 * 30,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        db()->update('users', ['last_login' => date('Y-m-d H:i:s')], $user['id']);
        Audit::log('login', 'auth', $user['id'], 'User signed in');
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            flash('error', 'Please sign in to continue.');
            redirect('login');
        }
    }

    public static function can(string $module, string $action = 'view'): bool
    {
        $role = self::role();
        if (!$role) {
            return false;
        }
        $map = require dirname(__DIR__) . '/config/permissions.php';
        $perms = $map[$role] ?? [];
        if (isset($perms['*']) && in_array($action, $perms['*'], true)) {
            return true;
        }
        return isset($perms[$module]) && in_array($action, $perms[$module], true);
    }

    public static function authorize(string $module, string $action = 'view'): void
    {
        if (!self::can($module, $action)) {
            abort(403, 'You do not have permission to access this module.');
        }
    }

    public static function canAccessRecord(array $row): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        if (!isset($row['farm_id'])) {
            return true;
        }
        if (!$row['farm_id']) {
            return true;
        }
        return self::canAccessFarm((int) $row['farm_id']);
    }

    public static function canManageUser(array $target): bool
    {
        if (!self::can('users', 'edit') && !self::can('users', 'view')) {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        if (($target['role'] ?? '') === 'admin') {
            return false;
        }
        if (!empty($target['farm_id'])) {
            return self::canAccessFarm((int) $target['farm_id']);
        }
        return false;
    }

    public static function roles(): array
    {
        return [
            'admin' => 'Administrator',
            'owner' => 'Farm Owner',
            'manager' => 'Farm Manager',
            'agronomist' => 'Agronomist',
            'worker' => 'Worker',
            'accountant' => 'Accountant',
        ];
    }
}
