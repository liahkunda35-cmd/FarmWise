<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

set_time_limit(0);

$failures = [];
$ok = static function (bool $pass, string $label, string $detail = '') use (&$failures): void {
    echo ($pass ? 'PASS' : 'FAIL') . '  ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$pass) {
        $failures[] = $label . ($detail !== '' ? ' — ' . $detail : '');
    }
};

$tag = bin2hex(random_bytes(3));
$password = 'FarmTest#26';
$createdUsers = [];
$createdFarms = [];
$phone = '+260977000111';

$cleanup = static function () use (&$createdUsers, &$createdFarms): void {
    foreach ($createdUsers as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        try { db()->query('DELETE FROM login_attempts WHERE identity IN (SELECT lower(email) FROM users WHERE id = ? UNION SELECT lower(username) FROM users WHERE id = ?)', [$id, $id]); } catch (Throwable $e) {}
        try { db()->query('DELETE FROM notifications WHERE link = ?', ['users/pending/' . $id]); } catch (Throwable $e) {}
        try { db()->query('DELETE FROM outbound_messages WHERE user_id = ?', [$id]); } catch (Throwable $e) {}
        try { db()->query('DELETE FROM user_farms WHERE user_id = ?', [$id]); } catch (Throwable $e) {}
        try { db()->query('UPDATE farms SET owner_id = NULL WHERE owner_id = ?', [$id]); } catch (Throwable $e) {}
        try { db()->query('UPDATE users SET farm_id = NULL WHERE id = ?', [$id]); } catch (Throwable $e) {}
        try { db()->query('DELETE FROM users WHERE id = ?', [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdFarms as $farmId) {
        try { db()->query('DELETE FROM farms WHERE id = ?', [(int) $farmId]); } catch (Throwable $e) {}
    }
};

$signOut = static function (): void {
    Auth::setOverride(null);
    Auth::clearCache();
    unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['active_farm_id']);
};

$dashLine = static function (array $user): string {
    Auth::setOverride($user);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
    if (!empty($user['farm_id'])) {
        $_SESSION['active_farm_id'] = (int) $user['farm_id'];
    } else {
        unset($_SESSION['active_farm_id']);
    }
    $scopeM = new ReflectionMethod(DashboardController::class, 'scope');
    $scopeM->setAccessible(true);
    $focusM = new ReflectionMethod(DashboardController::class, 'focus');
    $focusM->setAccessible(true);
    $scope = $scopeM->invoke(null);
    $focus = $focusM->invoke(null, $scope, [
        'farms' => 0,
        'pending_tasks' => 0,
        'active_crops' => 0,
        'fields' => 0,
    ], '', []);
    Auth::setOverride(null);
    return trim(($focus['line1'] ?? '') . ' ' . ($focus['line2'] ?? ''));
};

try {
    $farmBefore = db()->first('SELECT id, join_code, owner_id FROM farms WHERE join_code IS NOT NULL ORDER BY id ASC');
    $ok($farmBefore !== null, 'existing farm record is present before the test');

    $weak = RegistrationService::submitRequest([
        'name' => 'Weak User',
        'username' => 'weak' . $tag,
        'email' => 'weak.' . $tag . '@example.test',
        'phone' => $phone,
        'role' => 'worker',
        'password' => 'short',
        'password_confirm' => 'short',
    ]);
    $ok(empty($weak['ok']) && str_contains((string) ($weak['error'] ?? ''), '8 characters'), 'weak password is rejected');

    $mismatch = RegistrationService::submitRequest([
        'name' => 'Mismatch User',
        'username' => 'mis' . $tag,
        'email' => 'mis.' . $tag . '@example.test',
        'phone' => $phone,
        'role' => 'worker',
        'password' => $password,
        'password_confirm' => 'Other#2026',
    ]);
    $ok(empty($mismatch['ok']) && str_contains((string) ($mismatch['error'] ?? ''), 'match'), 'password confirmation is required');

    $register = static function (string $role, string $suffix = '') use ($tag, $password, $phone, &$createdUsers): array {
        $result = RegistrationService::submitRequest([
            'name' => ucfirst($role) . ' Test ' . $suffix . $tag,
            'username' => $role . $suffix . $tag,
            'email' => $role . $suffix . '.' . $tag . '@example.test',
            'phone' => $phone,
            'role' => $role,
            'password' => $password,
            'password_confirm' => $password,
        ]);
        if (!empty($result['user_id'])) {
            $createdUsers[] = (int) $result['user_id'];
        }
        return $result;
    };

    $workerReg = $register('worker');
    $ok(!empty($workerReg['ok']), 'worker registration is saved', (string) ($workerReg['error'] ?? ''));
    $workerId = (int) ($workerReg['user_id'] ?? 0);
    $worker = db()->find('users', $workerId);
    $ok(($worker['status'] ?? '') === 'pending', 'new account status is pending', (string) ($worker['status'] ?? ''));
    $ok((int) ($worker['must_change_password'] ?? 1) === 0, 'registration password is the account password');
    $ok(password_verify($password, (string) ($worker['password'] ?? '')), 'password is stored as a hash');
    $ok(!str_contains((string) ($worker['password'] ?? ''), $password), 'plaintext password is not stored');

    $pendingLogin = Auth::authenticate((string) $worker['email'], $password, 'NOT-A-CODE');
    $ok(empty($pendingLogin['ok']) && ($pendingLogin['error'] ?? '') === 'Your account is awaiting administrator approval.', 'pending login is denied', (string) ($pendingLogin['error'] ?? ''));
    $ok(!str_contains((string) ($pendingLogin['error'] ?? ''), 'Farm Code'), 'farm code is not required to attempt login');

    $_SESSION['user_id'] = $workerId;
    $_SESSION['session_version'] = (int) ($worker['session_version'] ?? 1);
    Auth::setOverride(null);
    Auth::clearCache();
    $ok(Auth::check() === false, 'pending account cannot open protected pages');
    unset($_SESSION['user_id']);

    $notes = (int) db()->value(
        "SELECT COUNT(*) FROM notifications WHERE category = 'registration' AND link = ?",
        ['users/pending/' . $workerId]
    );
    $ok($notes > 0, 'administrator is notified of the registration', 'count=' . $notes);

    $regMail = db()->first(
        "SELECT subject, body, status, error FROM outbound_messages WHERE user_id = ? AND subject LIKE ? ORDER BY id DESC",
        [$workerId, 'Successfully Registered%']
    );
    $ok($regMail !== null, 'registration email is queued');
    $ok($regMail && !str_contains((string) $regMail['body'], $password), 'registration email does not contain the password');
    $ok($regMail && str_contains((string) $regMail['body'], 'Pending Approval'), 'registration email says the account is pending');

    $admin = db()->first("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");
    $ok($admin !== null, 'an administrator account exists');
    $farm = db()->first('SELECT id, join_code, owner_id FROM farms ORDER BY id ASC');
    $ok($farm !== null, 'a farm exists for assignment');

    Auth::setOverride($admin);
    $_SESSION['user_id'] = (int) $admin['id'];
    $_SESSION['session_version'] = (int) ($admin['session_version'] ?? 1);
    $approved = RegistrationService::approve($workerId, 'worker', [(int) $farm['id']], false, 'workflow test');
    Auth::setOverride(null);
    $worker = db()->find('users', $workerId);
    $ok(!empty($approved['ok']) && ($worker['status'] ?? '') === 'active', 'approval sets the account to active', (string) ($approved['error'] ?? ($worker['status'] ?? '')));
    $ok((int) ($worker['farm_id'] ?? 0) === (int) $farm['id'], 'administrator farm assignment is kept');
    $ok((int) db()->value('SELECT owner_id FROM farms WHERE id = ?', [(int) $farm['id']]) === (int) ($farm['owner_id'] ?? 0), 'approving a worker does not replace the farm owner');

    $approvalMail = db()->first(
        "SELECT subject, body, status, error FROM outbound_messages WHERE user_id = ? AND subject = ? ORDER BY id DESC",
        [$workerId, 'Your FarmWise account is approved']
    );
    $ok($approvalMail !== null, 'approval email is queued', (string) ($approved['email_result']['error'] ?? ''));
    $loginUrl = absolute_url('login');
    $ok($approvalMail && str_contains((string) $approvalMail['body'], 'approved and activated'), 'approval email confirms activation');
    $ok($approvalMail && str_contains((string) $approvalMail['body'], $loginUrl), 'approval email includes the login page');
    $ok($approvalMail && !str_contains((string) $approvalMail['body'], $password), 'approval email does not contain the password');
    $ok($approvalMail && !str_contains((string) $approvalMail['body'], 'Farm Code'), 'approval email does not ask for a farm code');
    echo '      approval delivery status=' . (string) ($approvalMail['status'] ?? 'missing')
        . ' error=' . substr((string) ($approvalMail['error'] ?? ''), 0, 180) . PHP_EOL;

    $mailGuard = new ReflectionMethod(RegistrationService::class, 'safeMail');
    $mailGuard->setAccessible(true);
    $guard = $mailGuard->invoke(null, static function (): void {
        throw new RuntimeException('mail transport down');
    });
    $still = db()->find('users', $workerId);
    $ok(($guard['ok'] ?? true) === false && ($still['status'] ?? '') === 'active', 'an email failure does not reverse an active approval');

    Auth::setOverride(null);
    Auth::clearCache();
    $byEmail = Auth::attemptLogin((string) $worker['email'], $password, false, 'IGNORED-CODE');
    $ok(!empty($byEmail['ok']), 'login with email and registration password', (string) ($byEmail['error'] ?? ''));
    $ok(Auth::role() === 'worker' && Auth::check(), 'signed-in role is worker');
    $ok(Auth::dashboardPathForRole(Auth::role()) === 'dashboard', 'worker is sent to the dashboard');
    $ok(Auth::can('tasks', 'view') && !Auth::can('users', 'edit') && !Auth::can('invoices', 'view'), 'worker permissions stay limited');
    $signOut();

    $byUser = Auth::attemptLogin((string) $worker['username'], $password);
    $ok(!empty($byUser['ok']) && Auth::role() === 'worker', 'login with username and registration password', (string) ($byUser['error'] ?? ''));
    $signOut();

    $bad = Auth::authenticate((string) $worker['email'], 'Wrong#Pass1');
    $ok(empty($bad['ok']) && ($bad['error'] ?? '') === 'Invalid username/email or password.', 'wrong password is rejected', (string) ($bad['error'] ?? ''));

    $expected = [
        'worker' => 'Your tasks, Ready for today',
        'manager' => "Today's Farm operations",
        'agronomist' => 'Crop health, Field by field',
        'accountant' => 'Cash position, This month',
        'owner' => 'Healthy Crops, Greater Harvests',
    ];
    $workerLine = $dashLine(db()->find('users', $workerId));
    $ok($workerLine === $expected['worker'], 'worker dashboard content', $workerLine);

    foreach (['manager', 'agronomist', 'accountant', 'owner'] as $role) {
        $reg = $register($role);
        $id = (int) ($reg['user_id'] ?? 0);
        Auth::setOverride($admin);
        $_SESSION['user_id'] = (int) $admin['id'];
        if ($role === 'owner') {
            $result = RegistrationService::approve($id, $role, [], true, 'workflow test');
            $row = db()->find('users', $id);
            if (!empty($row['farm_id'])) {
                $createdFarms[] = (int) $row['farm_id'];
            }
        } else {
            $result = RegistrationService::approve($id, $role, [(int) $farm['id']], false, 'workflow test');
            $row = db()->find('users', $id);
        }
        Auth::setOverride(null);
        Auth::clearCache();
        $login = Auth::attemptLogin((string) ($row['email'] ?? ''), $password);
        $line = $dashLine(db()->find('users', $id));
        $permOk = match ($role) {
            'accountant' => Auth::can('invoices', 'view') && !Auth::can('users', 'edit') && !Auth::isAdmin(),
            'owner' => Auth::can('farms', 'edit') && Auth::can('users', 'edit') && !Auth::isAdmin(),
            'manager' => Auth::can('tasks', 'edit') && !Auth::can('users', 'edit'),
            'agronomist' => Auth::can('pest_scouting', 'view') && !Auth::can('invoices', 'view'),
            default => false,
        };
        $ok(!empty($reg['ok']) && !empty($result['ok']) && !empty($login['ok']) && Auth::role() === $role && $line === $expected[$role] && $permOk, $role . ' reaches its dashboard', $line . ' / ' . (string) ($result['error'] ?? $login['error'] ?? ''));
        $signOut();
    }

    $rejectReg = $register('worker', 'rej');
    $rejectId = (int) ($rejectReg['user_id'] ?? 0);
    Auth::setOverride($admin);
    $_SESSION['user_id'] = (int) $admin['id'];
    $rejected = RegistrationService::reject($rejectId, 'Test rejection');
    Auth::setOverride(null);
    $rejectedRow = db()->find('users', $rejectId);
    $rejectLogin = Auth::authenticate((string) ($rejectedRow['email'] ?? ''), $password);
    $ok(!empty($rejected['ok']) && ($rejectedRow['status'] ?? '') === 'rejected', 'rejection keeps the account closed');
    $ok(empty($rejectLogin['ok']) && ($rejectLogin['error'] ?? '') === 'Your registration was not approved. Contact your administrator.', 'rejected login message', (string) ($rejectLogin['error'] ?? ''));

    $adminLogin = Auth::authenticate('admin@ifms.local', 'Admin#2026');
    $ok(!empty($adminLogin['ok']) && (($adminLogin['user']['role'] ?? '') === 'admin'), 'existing administrator signs in without a farm code', (string) ($adminLogin['error'] ?? ''));
    if (!empty($adminLogin['ok'])) {
        Auth::setOverride($adminLogin['user']);
        $ok(Auth::can('users', 'edit') && $dashLine($adminLogin['user']) === 'Platform Administration', 'administrator dashboard and permissions');
        Auth::setOverride(null);
    }

    $signup = file_get_contents(dirname(__DIR__) . '/frontend/pages/auth/auth.php');
    $loginForm = is_string($signup) ? strstr($signup, "url('login')) ?>\" class=\"auth-shell-fields\"") : false;
    $ok(is_string($signup) && substr_count($signup, 'name="farm_code"') === 1, 'registration asks for a farm code once');
    $ok(is_string($loginForm) && !str_contains($loginForm, 'farm_code'), 'login form has no farm code');
    $ok(is_string($signup) && str_contains($signup, "name' => 'password'") && str_contains($signup, 'password_confirm'), 'sign-up page collects a password');

    $farmAfter = db()->first('SELECT id, join_code, owner_id FROM farms WHERE id = ?', [(int) $farmBefore['id']]);
    $ok(($farmAfter['join_code'] ?? null) === ($farmBefore['join_code'] ?? null) && (int) ($farmAfter['owner_id'] ?? 0) === (int) ($farmBefore['owner_id'] ?? 0), 'existing farm ownership and farm code record are unchanged');

    $visible = db()->select(
        "SELECT status, COUNT(*) AS n FROM users WHERE status IN ('pending','active','rejected') GROUP BY status"
    );
    $ok($visible !== [], 'pending, active and rejected accounts can be queried', json_encode($visible));
} catch (Throwable $e) {
    $ok(false, 'workflow test crashed', $e::class . ': ' . $e->getMessage());
} finally {
    Auth::setOverride(null);
    $cleanup();
}

echo $failures ? ('FAILED ' . count($failures) . PHP_EOL) : "ALL PASSED\n";
exit($failures ? 1 : 0);
