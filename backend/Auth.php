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
        // Cookie sessions must match the database copy. A token or test override
        // is already the current user row, so it is not compared with the browser session.
        if (!self::$override) {
            $sessionVer = (int) ($_SESSION['session_version'] ?? 0);
            $dbVer = (int) ($u['session_version'] ?? 1);
            if ($sessionVer > 0 && $sessionVer !== $dbVer) {
                self::logout();
                return false;
            }
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
        // One authorised farm and no selection yet: use that farm (admins included).
        // Several farms and no selection: stay unset so lists do not span every farm.
        $farms = self::accessibleFarms();
        if (count($farms) === 1) {
            $only = (int) $farms[0]['id'];
            if ($only > 0 && empty($_SESSION['active_farm_id'])) {
                $_SESSION['active_farm_id'] = $only;
            }
            return $only;
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
        $cacheKey = !self::$override && self::id() ? 'access-farms:' . self::id() : null;
        if ($cacheKey) {
            $cached = session_memo_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $rows = self::queryAccessibleFarms();
        if ($cacheKey) {
            session_memo_set($cacheKey, $rows, 25);
        }
        return $rows;
    }

    private static function queryAccessibleFarms(): array
    {
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

    public static function userBelongsToFarm(array $user, int $farmId): bool
    {
        if ($farmId <= 0) {
            return false;
        }
        if ((int) ($user['farm_id'] ?? 0) === $farmId) {
            return true;
        }
        if (table_exists('user_farms') && !empty($user['id'])) {
            $row = db()->first('SELECT id FROM user_farms WHERE user_id = ? AND farm_id = ?', [(int) $user['id'], $farmId]);
            if ($row) {
                return true;
            }
        }
        if (($user['role'] ?? '') === 'owner' && !empty($user['id'])) {
            $owned = db()->first('SELECT id FROM farms WHERE id = ? AND owner_id = ?', [$farmId, (int) $user['id']]);
            return (bool) $owned;
        }
        return false;
    }

    /**
     * Sign-in uses the farm already assigned to the account.
     * A farm code is not part of authentication.
     * @return array{error:?string,farm_id:?int}
     */
    public static function farmLoginGate(array $user, string $farmCode = ''): array
    {
        unset($farmCode);
        if (($user['role'] ?? '') === 'admin') {
            return ['error' => null, 'farm_id' => null];
        }
        $farmId = (int) ($user['farm_id'] ?? 0);
        return ['error' => null, 'farm_id' => $farmId > 0 ? $farmId : null];
    }

    /**
     * Check credentials without opening a browser session.
     * @return array{ok:bool,error?:string,user?:array,farm_id?:?int,must_change_password?:bool}
     */
    public static function authenticate(string $identity, string $password, string $farmCode = ''): array
    {
        $identity = trim($identity);
        if (self::loginThrottled($identity)) {
            return ['ok' => false, 'error' => 'Too many sign-in attempts. Please wait 15 minutes and try again.'];
        }
        $user = self::findByLogin($identity);
        if (!$user) {
            self::recordLoginFailure($identity);
            Audit::log('login_failed', 'auth', null, 'Unknown identity');
            return ['ok' => false, 'error' => 'Invalid username/email or password.'];
        }
        $status = (string) ($user['status'] ?? '');
        if ($status === 'pending') {
            self::recordLoginFailure($identity);
            return ['ok' => false, 'error' => 'Your account is awaiting administrator approval.'];
        }
        if ($status === 'approved') {
            self::recordLoginFailure($identity);
            return ['ok' => false, 'error' => 'Your account was approved. Open the activation link in your email to set your password before signing in.'];
        }
        if ($status === 'rejected') {
            self::recordLoginFailure($identity);
            return ['ok' => false, 'error' => 'Your registration was not approved. Contact your administrator.'];
        }
        if ($status === 'suspended') {
            self::recordLoginFailure($identity);
            return ['ok' => false, 'error' => 'Your account is suspended. Contact your administrator.'];
        }
        if ($status !== 'active') {
            self::recordLoginFailure($identity);
            return ['ok' => false, 'error' => 'Invalid username/email or password.'];
        }
        if (!password_verify($password, (string) $user['password'])) {
            self::recordLoginFailure($identity);
            Audit::log('login_failed', 'auth', (int) $user['id'], 'Bad password');
            return ['ok' => false, 'error' => 'Invalid username/email or password.'];
        }
        if (!self::isKnownRole((string) ($user['role'] ?? ''))) {
            self::recordLoginFailure($identity);
            Audit::log('login_failed', 'auth', (int) $user['id'], 'Unrecognised role');
            return ['ok' => false, 'error' => 'This account is not allowed to sign in.'];
        }
        $gate = self::farmLoginGate($user, $farmCode);
        if ($gate['error'] !== null) {
            self::recordLoginFailure($identity);
            Audit::log('login_failed', 'auth', (int) $user['id'], 'Farm code rejected');
            return ['ok' => false, 'error' => $gate['error']];
        }
        self::clearLoginFailures($identity);
        return [
            'ok' => true,
            'user' => $user,
            'farm_id' => $gate['farm_id'],
            'must_change_password' => !empty($user['must_change_password']),
        ];
    }

    /**
     * @return array{ok:bool,error?:string,must_change_password?:bool}
     */
    public static function attemptLogin(string $identity, string $password, bool $remember = false, string $farmCode = ''): array
    {
        $result = self::authenticate($identity, $password, $farmCode);
        if (empty($result['ok']) || empty($result['user'])) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Invalid username/email or password.')];
        }
        $user = $result['user'];

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
        self::clearCache();
        if (!empty($result['farm_id'])) {
            $_SESSION['active_farm_id'] = (int) $result['farm_id'];
        } elseif (!empty($user['farm_id'])) {
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
            'must_change_password' => !empty($result['must_change_password']),
        ];
    }

    public static function login(string $identity, string $password, bool $remember = false, string $farmCode = ''): bool
    {
        $result = self::attemptLogin($identity, $password, $remember, $farmCode);
        return !empty($result['ok']);
    }

    private static function loginThrottled(string $identity): bool
    {
        $identity = strtolower(trim($identity));
        if ($identity === '' || !table_exists('login_attempts')) {
            return false;
        }
        $since = date('Y-m-d H:i:s', time() - 900);
        $count = (int) db()->value(
            'SELECT COUNT(*) FROM login_attempts WHERE identity = ? AND created_at >= ?',
            [$identity, $since]
        );
        return $count >= 8;
    }

    private static function recordLoginFailure(string $identity): void
    {
        $identity = strtolower(trim($identity));
        if ($identity === '' || !table_exists('login_attempts')) {
            return;
        }
        try {
            db()->insert('login_attempts', [
                'identity' => $identity,
                'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }

    private static function clearLoginFailures(string $identity): void
    {
        $identity = strtolower(trim($identity));
        if ($identity === '' || !table_exists('login_attempts')) {
            return;
        }
        try {
            db()->query('DELETE FROM login_attempts WHERE identity = ?', [$identity]);
        } catch (Throwable $e) {
        }
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
        if (!self::isKnownRole((string) (self::role() ?? ''))) {
            self::deny(403, 'Your account role is not allowed to use FarmWise.');
        }
        if (!empty(self::user()['must_change_password'])) {
            $path = trim(current_path(), '/');
            if ($path !== 'profile' && !str_starts_with($path, 'profile/') && $path !== 'logout') {
                flash('error', 'You must set a new password before continuing.');
                redirect('profile');
            }
        }
    }

    /** @return array<string, array<string, list<string>>> */
    public static function permissionMap(): array
    {
        static $map;
        if ($map === null) {
            $loaded = require __DIR__ . '/config/permissions.php';
            $map = is_array($loaded) ? $loaded : [];
        }
        return $map;
    }

    public static function isKnownRole(string $role): bool
    {
        return $role !== '' && array_key_exists($role, self::permissionMap());
    }

    public static function can(string $module, string $action = 'view'): bool
    {
        $role = (string) (self::role() ?? '');
        if (!self::isKnownRole($role)) {
            return false;
        }
        $perms = self::permissionMap()[$role] ?? [];
        if (isset($perms['*']) && in_array($action, $perms['*'], true)) {
            return true;
        }
        return isset($perms[$module]) && in_array($action, $perms[$module], true);
    }

    public static function authorize(string $module, string $action = 'view'): void
    {
        if (!self::check()) {
            self::deny(401, 'Please sign in to continue.');
        }
        if (!self::isKnownRole((string) (self::role() ?? ''))) {
            self::deny(403, 'Your account role is not allowed to use FarmWise.');
        }
        if (!self::can($module, $action)) {
            self::deny(403, 'You do not have permission to access this module.');
        }
    }

    public static function deny(int $code, string $message): void
    {
        $path = trim(current_path(), '/');
        if ($path === 'api' || str_starts_with($path, 'api/')) {
            json_response(['ok' => false, 'error' => $message], $code);
        }
        abort($code, $message);
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
