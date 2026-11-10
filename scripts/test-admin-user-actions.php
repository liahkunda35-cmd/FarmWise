<?php
declare(strict_types=1);

putenv('IFMS_TEST_CAPTURE=1');
$_ENV['IFMS_TEST_CAPTURE'] = '1';
$_SERVER['IFMS_TEST_CAPTURE'] = '1';

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

$cleanup = static function () use (&$createdUsers): void {
    foreach ($createdUsers as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        try { db()->query('DELETE FROM notifications WHERE link = ?', ['users/pending/' . $id]); } catch (Throwable $e) {}
        try { db()->query('DELETE FROM user_farms WHERE user_id = ?', [$id]); } catch (Throwable $e) {}
        try { db()->query('UPDATE farms SET owner_id = NULL WHERE owner_id = ?', [$id]); } catch (Throwable $e) {}
        try { db()->query('DELETE FROM users WHERE id = ?', [$id]); } catch (Throwable $e) {}
    }
};

$signOut = static function (): void {
    Auth::setOverride(null);
    Auth::clearCache();
    unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['active_farm_id']);
};

$capture = static function (callable $fn): array {
    try {
        $fn();
        return ['ok' => false, 'error' => 'no redirect'];
    } catch (Throwable $e) {
        $exit = $GLOBALS['ifms_test_exit'] ?? null;
        unset($GLOBALS['ifms_test_exit']);
        if (is_array($exit) && ($exit['type'] ?? '') === 'redirect') {
            return ['ok' => true, 'path' => (string) ($exit['path'] ?? ''), 'flash' => $_SESSION['flash'] ?? []];
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
};

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $admin = db()->first("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");
    $ok($admin !== null, 'admin account exists');

    $reg = RegistrationService::submitRequest([
        'name' => 'Pending Owner ' . $tag,
        'username' => 'pend' . $tag,
        'email' => 'pend.' . $tag . '@example.test',
        'phone' => '+260977000222',
        'role' => 'owner',
        'password' => $password,
        'password_confirm' => $password,
    ]);
    $ok(!empty($reg['ok']), 'owner registration creates pending account', (string) ($reg['error'] ?? ''));
    $userId = (int) ($reg['user_id'] ?? 0);
    $createdUsers[] = $userId;
    $pending = db()->find('users', $userId);
    $ok(($pending['status'] ?? '') === 'pending', 'new owner status is pending');

    $note = db()->first(
        "SELECT * FROM notifications WHERE category = 'registration' AND link = ? ORDER BY id DESC",
        ['users/pending/' . $userId]
    );
    $ok($note !== null, 'admin registration notification created');

    Auth::setOverride($admin);
    $_SESSION['user_id'] = (int) $admin['id'];
    $_SESSION['session_version'] = (int) ($admin['session_version'] ?? 1);
    $method = new ReflectionMethod(DashboardController::class, 'pendingApprovals');
    $method->setAccessible(true);
    $list = $method->invoke(null);
    $found = false;
    foreach ($list as $row) {
        if ((int) ($row['id'] ?? 0) === $userId) {
            $found = true;
            break;
        }
    }
    $ok($found, 'pending approval appears on admin dashboard list');

    $beforePending = (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
    $approve = RegistrationService::approve($userId, 'owner', [], false, null);
    $ok(!empty($approve['ok']), 'approve pending owner', (string) ($approve['error'] ?? ''));
    $after = db()->find('users', $userId);
    $ok(($after['status'] ?? '') === 'active', 'approved owner becomes active', (string) ($after['status'] ?? ''));
    $afterPending = (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
    $ok($afterPending === max(0, $beforePending - 1), 'pending count decreases after approval');

    $dup = RegistrationService::approve($userId, 'owner', [], false, null);
    $ok(empty($dup['ok']), 'duplicate approval is rejected', (string) ($dup['error'] ?? ''));

    $noteAfter = db()->first(
        "SELECT is_read FROM notifications WHERE category = 'registration' AND link = ? ORDER BY id DESC",
        ['users/pending/' . $userId]
    );
    $ok($noteAfter === null || (int) ($noteAfter['is_read'] ?? 0) === 1, 'registration notification marked read after approval');

    $signOut();
    $login = Auth::authenticate('pend.' . $tag . '@example.test', $password);
    $ok(!empty($login['ok']), 'approved user can sign in', (string) ($login['error'] ?? ''));
    $signOut();

    Auth::setOverride($admin);
    $_SESSION['user_id'] = (int) $admin['id'];
    $_SESSION['session_version'] = (int) ($admin['session_version'] ?? 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'status' => 'suspended',
        '_csrf' => Csrf::token(),
        'redirect_to' => 'users',
    ];
    $suspend = $capture(static fn () => AppPagesController::setUserStatus($userId));
    $ok(!empty($suspend['ok']), 'suspend endpoint redirects', (string) ($suspend['error'] ?? ''));
    $fresh = db()->find('users', $userId);
    $ok(($fresh['status'] ?? '') === 'suspended', 'account suspended in database');

    $signOut();
    $blocked = Auth::authenticate('pend.' . $tag . '@example.test', $password);
    $ok(empty($blocked['ok']), 'suspended user cannot sign in', (string) ($blocked['error'] ?? ''));
    $ok(str_contains((string) ($blocked['error'] ?? ''), 'suspended'), 'suspend message is clear');

    Auth::setOverride($admin);
    $_SESSION['user_id'] = (int) $admin['id'];
    $_SESSION['session_version'] = (int) ($admin['session_version'] ?? 1);
    $_POST = [
        'status' => 'active',
        '_csrf' => Csrf::token(),
        'redirect_to' => 'users',
    ];
    $reactivate = $capture(static fn () => AppPagesController::setUserStatus($userId));
    $ok(!empty($reactivate['ok']), 'reactivate endpoint redirects', (string) ($reactivate['error'] ?? ''));
    $ok((db()->find('users', $userId)['status'] ?? '') === 'active', 'account reactivated in database');

    $signOut();
    $relogin = Auth::authenticate('pend.' . $tag . '@example.test', $password);
    $ok(!empty($relogin['ok']), 'reactivated user can sign in', (string) ($relogin['error'] ?? ''));
    $signOut();

    Auth::setOverride($admin);
    $_SESSION['user_id'] = (int) $admin['id'];
    $_SESSION['session_version'] = (int) ($admin['session_version'] ?? 1);
    $_POST = [
        '_csrf' => Csrf::token(),
        'redirect_to' => 'users',
    ];
    $soft = $capture(static fn () => AppPagesController::deleteUser($userId));
    $ok(!empty($soft['ok']), 'delete/deactivate endpoint redirects', (string) ($soft['error'] ?? ''));
    $softRow = db()->find('users', $userId);
    $ok($softRow !== null && ($softRow['status'] ?? '') === 'inactive', 'active account soft-deleted to inactive');

    $reg2 = RegistrationService::submitRequest([
        'name' => 'Delete Me ' . $tag,
        'username' => 'del' . $tag,
        'email' => 'del.' . $tag . '@example.test',
        'phone' => '+260977000333',
        'role' => 'owner',
        'password' => $password,
        'password_confirm' => $password,
    ]);
    $delId = (int) ($reg2['user_id'] ?? 0);
    $createdUsers[] = $delId;
    Auth::setOverride($admin);
    $_SESSION['user_id'] = (int) $admin['id'];
    $_SESSION['session_version'] = (int) ($admin['session_version'] ?? 1);
    $_POST = [
        '_csrf' => Csrf::token(),
        'redirect_to' => 'users',
    ];
    $hard = $capture(static fn () => AppPagesController::deleteUser($delId));
    $ok(!empty($hard['ok']), 'pending delete endpoint redirects', (string) ($hard['error'] ?? ''));
    $ok(db()->find('users', $delId) === null, 'pending registration hard-deleted safely');
    $createdUsers = array_values(array_filter($createdUsers, static fn ($id) => (int) $id !== $delId));

    $_POST = [
        'status' => 'suspended',
        '_csrf' => Csrf::token(),
        'redirect_to' => 'users',
    ];
    $self = $capture(static fn () => AppPagesController::setUserStatus((int) $admin['id']));
    $ok(!empty($self['ok']), 'self-status change redirects with error path');
    $ok((db()->find('users', (int) $admin['id'])['status'] ?? '') === 'active', 'admin cannot suspend own account');

    $authSrc = file_get_contents(dirname(__DIR__) . '/frontend/pages/auth/auth.php') ?: '';
    $ok(!str_contains($authSrc, 'placeholder="you@example.com"'), 'example email placeholder removed');
    $ok(!str_contains($authSrc, 'placeholder="Username or email"'), 'login identity placeholder removed');
    $ok(!str_contains($authSrc, 'placeholder="Create a password"'), 'password placeholders removed');

    $signOut();
} catch (Throwable $e) {
    $ok(false, 'unexpected exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $cleanup();
}

if ($failures) {
    echo PHP_EOL . count($failures) . ' failure(s)' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'ALL PASSED' . PHP_EOL;
exit(0);
