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

    public static function clearCache(): void
    {
        self::$cached = null;
        self::$cachedId = null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if (($u['status'] ?? '') !== 'active') {
            return false;
        }
        // Enforce role/status/farm changes issued by administrators
        $sessionVer = (int) ($_SESSION['session_version'] ?? 0);
        $dbVer = (int) ($u['session_version'] ?? 1);
        if ($sessionVer > 0 && $sessionVer !== $dbVer) {
            self::logout();
            return false;
        }
        return true;
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
        if ($u && !empty($u['farm_id']) && self::canAccessFarm((int) $u['farm_id'])) {
            return (int) $u['farm_id'];
        }
        // One authorised farm and no selection yet: use that farm.
        // Several farms and no selection: stay unset so lists do not span every farm.
        if (!self::isAdmin()) {
            $farms = self::accessibleFarms();
            if (count($farms) === 1) {
                return (int) $farms[0]['id'];
            }
        }
        return null;
    }

    public static function homeFarmId(): ?int
    {
        $u = self::user();
        return $u && $u['farm_id'] ? (int) $u['farm_id'] : null;
    }

    public static function accessibleFarms(): array
    {
        if (!self::check() && !self::$override) {
            return [];
        }
        if (!self::user()) {
            return [];
        }
        if (self::isAdmin()) {
            return db()->select('SELECT * FROM farms ORDER BY name');
        }
        $u = self::user();
        $ids = [];
        if (class_exists('RegistrationService') && table_exists('user_farms')) {
            $ids = RegistrationService::farmIdsForUser((int) $u['id']);
        }
        if (!$ids && !empty($u['farm_id'])) {
            $ids = [(int) $u['farm_id']];
        }
        if (self::role() === 'owner') {
            $owned = db()->select('SELECT id FROM farms WHERE owner_id = ?', [self::id()]);
            foreach ($owned as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return db()->select("SELECT * FROM farms WHERE id IN ({$placeholders}) ORDER BY name", $ids);
    }

    public static function canAccessFarm(int $farmId): bool
    {
        if ($farmId <= 0) {
            return false;
        }
        if (self::isAdmin()) {
            return (bool) db()->find('farms', $farmId);
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
        if (table_exists('users')) {
            try {
                $byUser = db()->first('SELECT * FROM users WHERE lower(username) = ?', [strtolower($identity)]);
                if ($byUser) {
                    return $byUser;
                }
            } catch (Throwable $e) {
                // username column may not exist yet
            }
        }
        $matches = db()->select('SELECT * FROM users WHERE lower(name) = ?', [strtolower($identity)]);
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return array{ok:bool,error?:string,must_change_password?:bool}
     */
    public static function attemptLogin(string $identity, string $password, bool $remember = false): array
    {
        $user = self::findByLogin($identity);
        if (!$user) {
            Audit::log('login_failed', 'auth', null, 'Unknown identity');
            return ['ok' => false, 'error' => 'Invalid username/email or password.'];
        }
        $status = (string) ($user['status'] ?? '');
        if ($status === 'pending') {
            return ['ok' => false, 'error' => 'Your registration is still awaiting administrator approval.'];
        }
        if ($status === 'approved') {
            return ['ok' => false, 'error' => 'Your account was approved. Open the activation link in your email to set your password before signing in.'];
        }
        if ($status === 'rejected') {
            return ['ok' => false, 'error' => 'Your registration was not approved. Contact your administrator.'];
        }
        if ($status === 'suspended') {
            return ['ok' => false, 'error' => 'Your account is suspended. Contact your administrator.'];
        }
        if ($status !== 'active') {
            return ['ok' => false, 'error' => 'Invalid username/email or password.'];
        }
        if (!password_verify($password, $user['password'])) {
            Audit::log('login_failed', 'auth', (int) $user['id'], 'Bad password');
            return ['ok' => false, 'error' => 'Invalid username/email or password.'];
        }

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
        self::clearCache();
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
        Audit::log('login', 'auth', $user['id'], 'User signed in as ' . ($user['role'] ?? ''));
        return [
            'ok' => true,
            'must_change_password' => !empty($user['must_change_password']),
        ];
    }

    public static function login(string $identity, string $password, bool $remember = false): bool
    {
        $result = self::attemptLogin($identity, $password, $remember);
        return !empty($result['ok']);
    }

    public static function logout(): void
    {
        self::clearCache();
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
        if (!empty(self::user()['must_change_password'])) {
            $path = trim(current_path(), '/');
            if ($path !== 'profile' && !str_starts_with($path, 'profile/') && $path !== 'logout') {
                flash('error', 'You must set a new password before continuing.');
                redirect('profile');
            }
        }
    }

    public static function can(string $module, string $action = 'view'): bool
    {
        $role = self::role();
        if (!$role) {
            return false;
        }
        $map = require __DIR__ . '/config/permissions.php';
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

    public static function dashboardPathForRole(?string $role = null): string
    {
        return 'dashboard';
    }
}
