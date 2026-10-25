<?php
require dirname(__DIR__) . '/partials/frontend.php';
$editUser = $editUser ?? null;
$showRoles = input('tab') === 'roles';
include __DIR__ . '/_nav.php';

// Overview stats (read-only)
$stats = [
    'users' => table_exists('users') ? (int) db()->value('SELECT COUNT(*) FROM users') : 0,
    'active' => table_exists('users') ? (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'active'") : 0,
    'inactive' => table_exists('users') ? (int) db()->value("SELECT COUNT(*) FROM users WHERE status != 'active'") : 0,
    'farms' => table_exists('farms') ? (int) db()->value('SELECT COUNT(*) FROM farms') : 0,
];
$byRole = [];
if (table_exists('users')) {
    try {
        foreach (db()->select('SELECT role, COUNT(*) AS total FROM users GROUP BY role') as $r) {
            $byRole[$r['role']] = (int) $r['total'];
        }
    } catch (Throwable $e) { /* ignore */ }
}
$lastBackup = null;
$backupDir = dirname(__DIR__, 2) . '/storage/backups';
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/*.sqlite') ?: [];
    rsort($files);
    if ($files) {
        $lastBackup = ['file' => basename($files[0]), 'mtime' => filemtime($files[0]), 'size' => filesize($files[0])];
    }
}
$backupAgeDays = $lastBackup ? ((time() - $lastBackup['mtime']) / 86400) : null;
$latestAudit = [];
if (table_exists('audit_logs') && Auth::can('audit_logs', 'view')) {
    try {
        $latestAudit = db()->select('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 5');
    } catch (Throwable $e) { /* ignore */ }
}
$roleDescriptions = [
    'admin' => 'Full system access across all farms, users, backups and integrations.',
    'owner' => 'Owns one or more farms; manages people, money and operations for those farms.',
    'manager' => 'Runs day-to-day crop, livestock, labour and inventory work.',
    'agronomist' => 'Focuses on crop health, scouting, spraying and growth records.',
    'worker' => 'Records field tasks, attendance and basic production data.',
    'accountant' => 'Handles sales, purchases, payroll and financial records.',
];
?>

<?php if (!$editUser && !$showRoles): ?>
<div class="admin-overview">
    <article class="hub-card"><h4>Users</h4><b><?= (int)$stats['users'] ?></b><span class="muted"><?= (int)$stats['active'] ?> active · <?= (int)$stats['inactive'] ?> inactive</span></article>
    <article class="hub-card"><h4>Farms</h4><b><?= (int)$stats['farms'] ?></b></article>
    <article class="hub-card">
        <h4>Last backup</h4>
        <?php if ($lastBackup): ?>
            <b><?= e(date('d M Y', $lastBackup['mtime'])) ?></b>
            <span class="muted"><?= e(number_format($lastBackup['size'] / 1024, 0)) ?> KB<?= $backupAgeDays !== null && $backupAgeDays > 7 ? ' · ⚠ older than 7 days' : '' ?></span>
        <?php else: ?>
            <b>—</b><span class="muted">No backup found</span>
        <?php endif; ?>
    </article>
    <article class="hub-card">
        <h4>By role</h4>
        <ul class="muted" style="margin:0;padding-left:1.1rem;font-size:.82rem">
            <?php foreach (Auth::roles() as $k => $label): ?>
                <li><?= e($label) ?>: <?= (int)($byRole[$k] ?? 0) ?></li>
            <?php endforeach; ?>
        </ul>
    </article>
</div>
<?php if ($latestAudit): ?>
<section class="panel">
    <header class="panel-tools"><h3>Latest audit</h3><a class="panel-link" href="<?= e(url('audit')) ?>">View all →</a></header>
    <ul class="feed">
        <?php foreach ($latestAudit as $a): ?>
            <li>
                <strong><?= e($a['action'] ?? '') ?> · <?= e($a['module'] ?? '') ?></strong>
                <span><?= e($a['user_name'] ?: 'System') ?> · <?= e($a['created_at'] ?? '') ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($showRoles): ?>
<section class="panel">
    <header><h3>Roles &amp; permissions</h3><p class="muted">Read-only matrix from config. Tick = allowed.</p></header>
    <?php
    $perms = require dirname(__DIR__, 2) . '/config/permissions.php';
    $modules = [];
    foreach (all_modules() as $key => $def) {
        $modules[$key] = $def['title'] ?? $key;
    }
    foreach (['dashboard','calendar','weather','notifications','settings','users','integrations','messages','audit_logs','backups','reports','yield_forecasts'] as $extra) {
        if (!isset($modules[$extra])) $modules[$extra] = ucwords(str_replace('_', ' ', $extra));
    }
    $actions = ['view', 'create', 'edit', 'delete'];
    if (!function_exists('fe_role_can')) {
        function fe_role_can(array $perms, string $role, string $module, string $action): bool {
            $p = $perms[$role] ?? [];
            if (isset($p['*']) && in_array($action, $p['*'], true)) return true;
            return isset($p[$module]) && in_array($action, $p[$module], true);
        }
    }
    ?>
    <div class="role-blurb">
        <?php foreach (Auth::roles() as $rk => $rl): ?>
            <p><strong><?= e($rl) ?></strong> — <?= e($roleDescriptions[$rk] ?? '') ?></p>
        <?php endforeach; ?>
    </div>
    <div class="table-wrap sticky-head">
        <table class="perm-matrix">
            <thead>
                <tr>
                    <th>Module</th>
                    <?php foreach (Auth::roles() as $rk => $rl): ?>
                        <th><?= e($rl) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $mk => $ml): ?>
                <tr>
                    <td><?= e($ml) ?></td>
                    <?php foreach (Auth::roles() as $rk => $rl): ?>
                        <td class="perm-cell">
                            <?php foreach ($actions as $act): ?>
                                <span class="perm-tick <?= fe_role_can($perms, $rk, $mk, $act) ? 'on' : 'off' ?>" title="<?= e($act) ?>"><?= fe_role_can($perms, $rk, $mk, $act) ? '✓' : '·' ?></span>
                            <?php endforeach; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Columns show view / create / edit / delete.</p>
</section>
<?php else: ?>

<section class="panel">
    <header class="panel-tools">
        <h3><?= $editUser ? 'Edit user' : 'Users' ?></h3>
        <?php if ($editUser): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('users')) ?>">Back to list</a><?php endif; ?>
    </header>
    <?php if ($editUser && Auth::can('users', 'edit')): ?>
    <form class="stack-form" method="post" action="<?= e(url('users/save/'.$editUser['id'])) ?>" data-sensitive-user-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= e($editUser['id']) ?>">
        <input type="hidden" name="confirm_sensitive" value="0" data-confirm-sensitive>
        <div class="form-grid">
            <label>Name<input name="name" value="<?= e($editUser['name']) ?>" required></label>
            <label>Email<input type="email" name="email" value="<?= e($editUser['email']) ?>" required></label>
            <label>Phone<input name="phone" value="<?= e($editUser['phone']) ?>"></label>
            <label>Role
                <select name="role" required data-original="<?= e($editUser['role']) ?>" <?= (int)$editUser['id'] === (int)Auth::id() ? 'disabled' : '' ?>>
                    <?php foreach (Auth::roles() as $k=>$l): if ($k==='admin' && !Auth::isAdmin()) continue; ?>
                        <option value="<?= e($k) ?>" <?= $editUser['role']===$k?'selected':'' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ((int)$editUser['id'] === (int)Auth::id()): ?>
                    <input type="hidden" name="role" value="<?= e($editUser['role']) ?>">
                    <small class="muted">You cannot change your own role.</small>
                <?php endif; ?>
            </label>
            <?php if (Auth::isAdmin()): ?>
            <label>Farm
                <select name="farm_id">
                    <option value="">—</option>
                    <?php foreach ($farms as $id=>$name): ?><option value="<?= e($id) ?>" <?= (string)$editUser['farm_id']===(string)$id?'selected':'' ?>><?= e($name) ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label>Status
                <select name="status" data-original="<?= e($editUser['status']) ?>">
                    <option value="active" <?= $editUser['status']==='active'?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= $editUser['status']==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
            </label>
            <label class="wide">New password (optional)
                <?= password_input(['name' => 'password', 'required' => false, 'autocomplete' => 'new-password']) ?>
            </label>
        </div>
        <button class="btn btn-gold" type="submit">Save changes</button>
    </form>
    <?php elseif (Auth::can('users', 'create')): ?>
    <form class="stack-form" method="post" action="<?= e(url('users/save')) ?>">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>Name<input name="name" required></label>
            <label>Email<input type="email" name="email" required></label>
            <label>Phone<input name="phone"></label>
            <label>Role
                <select name="role" required>
                    <?php foreach (Auth::roles() as $k=>$l): if ($k==='admin' && !Auth::isAdmin()) continue; ?>
                        <option value="<?= e($k) ?>"><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (Auth::isAdmin()): ?>
            <label>Farm
                <select name="farm_id">
                    <option value="">—</option>
                    <?php foreach ($farms as $id=>$name): ?><option value="<?= e($id) ?>"><?= e($name) ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label class="wide">Password
                <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
            </label>
            <label>Status
                <select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </label>
        </div>
        <button class="btn btn-gold" type="submit">Create user</button>
    </form>
    <?php endif; ?>

    <form class="search-form" method="get" action="<?= e(url('users')) ?>" style="margin:1rem 0">
        <label class="sr-only" for="user-q">Search users</label>
        <input id="user-q" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Search name or email">
        <select name="role" aria-label="Filter by role">
            <option value="">All roles</option>
            <?php foreach (Auth::roles() as $k=>$l): ?>
                <option value="<?= e($k) ?>" <?= input('role')===$k?'selected':'' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="active" <?= input('status')==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= input('status')==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
    </form>
    <?php
    // Client-side filter fallback (list already server-rendered; filter rows in JS if q provided without backend support)
    $filterQ = strtolower((string) input('q'));
    $filterRole = (string) input('role');
    $filterStatus = (string) input('status');
    ?>
    <div class="table-wrap sticky-head">
        <table data-user-table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Farm</th><th>Join code</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u):
                if ($filterRole && $u['role'] !== $filterRole) continue;
                if ($filterStatus && ($u['status'] ?? '') !== $filterStatus) continue;
                if ($filterQ && !str_contains(strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '')), $filterQ)) continue;
                $joinCode = '';
                if (!empty($u['farm_id']) && table_exists('farms')) {
                    try { $joinCode = (string) db()->value('SELECT join_code FROM farms WHERE id = ?', [$u['farm_id']]); } catch (Throwable $e) { $joinCode = ''; }
                }
            ?>
                <tr class="<?= $editUser && (int)$editUser['id']===(int)$u['id'] ? 'is-editing' : '' ?>">
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge"><?= e(Auth::roles()[$u['role']] ?? $u['role']) ?></span></td>
                    <td><?= e($u['farm_name']) ?></td>
                    <td><?php if ($joinCode): ?><code data-copy-text="<?= e($joinCode) ?>"><?= e($joinCode) ?></code> <button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button><?php else: ?>—<?php endif; ?></td>
                    <td><?= status_badge($u['status']) ?></td>
                    <td><?= dater($u['last_login'], 'd M Y H:i') ?></td>
                    <td class="row-actions">
                        <?php if (Auth::can('users', 'edit') && Auth::canManageUser($u)): ?>
                            <a href="<?= e(url('users/edit/'.$u['id'])) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (Auth::can('users', 'delete') && (int)$u['id'] !== (int)Auth::id() && Auth::canManageUser($u)): ?>
                            <form method="post" action="<?= e(url('users/delete/'.$u['id'])) ?>" data-confirm="Delete user <?= e($u['name']) ?>? This cannot be undone.">
                                <?= Csrf::field() ?><button type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
