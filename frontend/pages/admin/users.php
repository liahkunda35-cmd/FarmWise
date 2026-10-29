<?php
fe_component('partials/frontend');
$editUser = $editUser ?? null;
$showRoles = input('tab') === 'roles';
include __DIR__ . '/_nav.php';

$directory = $directory ?? fe_user_directory_summary();
$stats = $directory['stats'];
$byRole = $directory['by_role'];
$lastBackup = $directory['last_backup'];
$backupAgeDays = $lastBackup ? ((time() - $lastBackup['mtime']) / 86400) : null;
$latestAudit = $directory['latest_audit'];
$roleDescriptions = [
    'admin' => 'Full system access across all farms, users, backups and integrations.',
    'owner' => 'Owns one or more farms; manages people, money and operations for those farms.',
    'manager' => 'Runs day-to-day crop, livestock, labour and inventory work.',
    'agronomist' => 'Focuses on crop health, scouting, spraying and growth records.',
    'worker' => 'Records field tasks, attendance and basic production data.',
    'accountant' => 'Handles sales, purchases, payroll and financial records.',
];
?>

<?php
$pendingCount = $pendingCount ?? 0;
$editAssigned = [];
if ($editUser) {
    $editAssigned = RegistrationService::farmIdsForUser((int) $editUser['id']);
}
?>
<?php if (!$editUser && !$showRoles): ?>
<?php if (fe_is_admin() && $pendingCount > 0): ?>
<section class="panel" style="border-color:#c9a227">
    <header class="panel-tools">
        <h3>Pending registration requests</h3>
        <a class="panel-link" href="<?= e(url('users')) ?>?status=pending">Review →</a>
    </header>
    <p class="muted"><?= (int) $pendingCount ?> applicant<?= $pendingCount === 1 ? '' : 's' ?> waiting for approval. Open a request from the list below (status Pending).</p>
</section>
<?php endif; ?>
<div class="admin-overview">
    <article class="hub-card"><h4>Users</h4><b><?= (int)$stats['users'] ?></b><span class="muted"><?= (int)$stats['active'] ?> active · <?= (int)$stats['inactive'] ?> inactive<?= $pendingCount ? ' · ' . (int)$pendingCount . ' pending' : '' ?></span></article>
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
            <?php foreach (fe_roles() as $k => $label): ?>
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
    $perms = $perms ?? [];
    $modules = $moduleTitles ?? [];
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
        <?php foreach (fe_roles() as $rk => $rl): ?>
            <p><strong><?= e($rl) ?></strong> — <?= e($roleDescriptions[$rk] ?? '') ?></p>
        <?php endforeach; ?>
    </div>
    <div class="table-wrap sticky-head">
        <table class="perm-matrix">
            <thead>
                <tr>
                    <th>Module</th>
                    <?php foreach (fe_roles() as $rk => $rl): ?>
                        <th><?= e($rl) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $mk => $ml): ?>
                <tr>
                    <td><?= e($ml) ?></td>
                    <?php foreach (fe_roles() as $rk => $rl): ?>
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
    <?php if ($editUser && fe_can('users', 'edit')): ?>
    <form class="stack-form" method="post" action="<?= e(url('users/save/'.$editUser['id'])) ?>" data-sensitive-user-form data-validate-form novalidate>
        <?= fe_csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($editUser['id']) ?>">
        <input type="hidden" name="confirm_sensitive" value="0" data-confirm-sensitive>
        <div class="form-grid">
            <label>Name
                <input name="name" value="<?= e($editUser['name']) ?>" required maxlength="120" data-validate="required">
                <span class="field-msg" data-field-error="name" hidden></span>
            </label>
            <label>Username
                <input name="username" value="<?= e($editUser['username'] ?? '') ?>" maxlength="30" autocomplete="username" pattern="[A-Za-z0-9._\-]*" title="3–30 characters: letters, numbers, underscores, periods, or hyphens — no spaces">
            </label>
            <label>Email
                <input type="email" name="email" value="<?= e($editUser['email']) ?>" required maxlength="190" data-validate="email">
                <span class="field-msg" data-field-error="email" hidden></span>
            </label>
            <label>Phone
                <input type="tel" name="phone" value="<?= e($editUser['phone']) ?>" placeholder="+26097XXXXXXX" maxlength="20" data-validate="phone-optional" inputmode="tel">
                <span class="field-msg" data-field-error="phone" hidden></span>
            </label>
            <label>Role
                <select name="role" required data-original="<?= e($editUser['role']) ?>" <?= (int)$editUser['id'] === (int)fe_id() ? 'disabled' : '' ?>>
                    <?php foreach (fe_roles() as $k=>$l): if ($k==='admin' && !fe_is_admin()) continue; ?>
                        <option value="<?= e($k) ?>" <?= $editUser['role']===$k?'selected':'' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ((int)$editUser['id'] === (int)fe_id()): ?>
                    <input type="hidden" name="role" value="<?= e($editUser['role']) ?>">
                    <small class="muted">You cannot change your own role.</small>
                <?php endif; ?>
            </label>
            <?php if (fe_is_admin()): ?>
            <label class="wide">Farms
                <select name="farm_ids[]" multiple size="5">
                    <?php foreach ($farms as $id=>$name): ?>
                        <option value="<?= e($id) ?>" <?= in_array((int)$id, array_map('intval', $editAssigned), true) || (string)$editUser['farm_id']===(string)$id ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="farm_id" value="<?= e($editUser['farm_id']) ?>">
                <small class="muted">Primary farm stays the first selected assignment.</small>
            </label>
            <?php endif; ?>
            <label>Status
                <select name="status" data-original="<?= e($editUser['status']) ?>">
                    <option value="active" <?= $editUser['status']==='active'?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= $editUser['status']==='inactive'?'selected':'' ?>>Inactive</option>
                    <option value="suspended" <?= $editUser['status']==='suspended'?'selected':'' ?>>Suspended</option>
                </select>
            </label>
            <label class="wide">New password (optional)
                <?= password_input(['name' => 'password', 'required' => false, 'autocomplete' => 'new-password']) ?>
            </label>
        </div>
        <button class="btn btn-gold" type="submit">Save changes</button>
    </form>
    <?php elseif (fe_can('users', 'create')): ?>
    <form class="stack-form" method="post" action="<?= e(url('users/save')) ?>" data-validate-form novalidate>
        <?= fe_csrf_field() ?>
        <div class="form-grid">
            <label>Name
                <input name="name" required maxlength="120" data-validate="required">
                <span class="field-msg" data-field-error="name" hidden></span>
            </label>
            <label>Email
                <input type="email" name="email" required maxlength="190" data-validate="email">
                <span class="field-msg" data-field-error="email" hidden></span>
            </label>
            <label>Phone
                <input type="tel" name="phone" placeholder="+26097XXXXXXX" maxlength="20" data-validate="phone-optional" inputmode="tel">
                <span class="field-msg" data-field-error="phone" hidden></span>
            </label>
            <label>Role
                <select name="role" required>
                    <?php foreach (fe_roles() as $k=>$l): if ($k==='admin' && !fe_is_admin()) continue; ?>
                        <option value="<?= e($k) ?>"><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (fe_is_admin()): ?>
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
            <?php foreach (fe_roles() as $k=>$l): ?>
                <option value="<?= e($k) ?>" <?= input('role')===$k?'selected':'' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="pending" <?= input('status')==='pending'?'selected':'' ?>>Pending</option>
            <option value="approved" <?= input('status')==='approved'?'selected':'' ?>>Approved (awaiting activation)</option>
            <option value="active" <?= input('status')==='active'?'selected':'' ?>>Active</option>
            <option value="rejected" <?= input('status')==='rejected'?'selected':'' ?>>Rejected</option>
            <option value="suspended" <?= input('status')==='suspended'?'selected':'' ?>>Suspended</option>
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
            <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Farm</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u):
                if ($filterRole && $u['role'] !== $filterRole) continue;
                if ($filterStatus && ($u['status'] ?? '') !== $filterStatus) continue;
                if ($filterQ && !str_contains(strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['username'] ?? '')), $filterQ)) continue;
            ?>
                <tr class="<?= $editUser && (int)$editUser['id']===(int)$u['id'] ? 'is-editing' : '' ?>">
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['username'] ?? '—') ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge"><?= e(fe_roles()[$u['role']] ?? $u['role']) ?></span><?php if (!empty($u['requested_role']) && ($u['status'] ?? '') === 'pending'): ?> <small class="muted">req. <?= e(fe_roles()[$u['requested_role']] ?? $u['requested_role']) ?></small><?php endif; ?></td>
                    <td><?= e($u['farm_name'] ?: '—') ?></td>
                    <td><?= status_badge($u['status']) ?></td>
                    <td><?= dater($u['last_login'], 'd M Y H:i') ?></td>
                    <td class="row-actions">
                        <?php if (fe_is_admin() && in_array(($u['status'] ?? ''), ['pending', 'approved', 'rejected'], true)): ?>
                            <a href="<?= e(url('users/pending/'.$u['id'])) ?>">Review</a>
                        <?php endif; ?>
                        <?php if (fe_can('users', 'edit') && fe_can_manage_user($u) && !in_array(($u['status'] ?? ''), ['pending'], true)): ?>
                            <a href="<?= e(url('users/edit/'.$u['id'])) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (fe_can('users', 'delete') && (int)$u['id'] !== (int)fe_id() && fe_can_manage_user($u)): ?>
                            <form method="post" action="<?= e(url('users/delete/'.$u['id'])) ?>" data-confirm="Delete user <?= e($u['name']) ?>? This cannot be undone.">
                                <?= fe_csrf_field() ?><button type="submit">Delete</button>
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
