<?php
fe_component('partials/frontend');
$editUser = $editUser ?? null;
$showRoles = input('tab') === 'roles';
include __DIR__ . '/_nav.php';

$directory = $directory ?? fe_user_directory_summary();
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
$pendingUsers = $pendingUsers ?? [];
$editAssigned = [];
if ($editUser) {
    $editAssigned = RegistrationService::farmIdsForUser((int) $editUser['id']);
}
?>
<?php if (!$editUser && !$showRoles): ?>
<?php if ($pendingCount > 0 && (fe_is_admin() || fe_role() === 'owner')): ?>
<section class="panel pending-approvals">
    <header class="panel-tools">
        <h3>Pending registration requests <span class="badge badge-warn"><?= (int) $pendingCount ?></span></h3>
        <a class="panel-link" href="<?= e(url('users')) ?>?status=pending">Filter pending →</a>
    </header>
    <?php if ($pendingUsers): ?>
    <div class="pending-approvals-list">
        <?php foreach ($pendingUsers as $pending):
            $reqRole = (string) ($pending['requested_role'] ?: $pending['role'] ?: '');
            $farmId = (int) ($pending['farm_id'] ?? 0);
            ?>
            <article class="pending-approval-card">
                <div class="pending-approval-meta">
                    <strong><?= e($pending['name'] ?? 'Applicant') ?></strong>
                    <span>@<?= e(($pending['username'] ?? '') !== '' ? $pending['username'] : '—') ?> · <?= e($pending['email'] ?? '') ?></span>
                    <span>
                        Role: <?= e(fe_roles()[$reqRole] ?? ($reqRole ?: '—')) ?>
                        · Registered <?= e(dater($pending['created_at'] ?? null, 'd M Y H:i')) ?>
                    </span>
                </div>
                <div class="user-actions">
                    <form method="post" action="<?= e(url('users/pending/' . (int) $pending['id'] . '/approve')) ?>">
                        <?= fe_csrf_field() ?>
                        <input type="hidden" name="role" value="<?= e($reqRole) ?>">
                        <?php if ($farmId > 0): ?>
                            <input type="hidden" name="farm_ids[]" value="<?= $farmId ?>">
                            <input type="hidden" name="farm_id" value="<?= $farmId ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-approve btn-sm">Approve</button>
                    </form>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('users/pending/' . (int) $pending['id'])) ?>">Review</a>
                    <form method="post" action="<?= e(url('users/pending/' . (int) $pending['id'] . '/reject')) ?>" data-confirm="Reject registration for <?= e($pending['name'] ?? 'this user') ?>?">
                        <?= fe_csrf_field() ?>
                        <button type="submit" class="btn btn-delete btn-sm">Reject</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="muted"><?= (int) $pendingCount ?> applicant<?= $pendingCount === 1 ? '' : 's' ?> waiting for approval.</p>
    <?php endif; ?>
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

<section class="panel users-panel">
    <header class="panel-tools">
        <h3><?= $editUser ? 'Edit user' : 'Users' ?></h3>
        <div class="tool-row">
            <?php if ($editUser): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('users')) ?>">Back to list</a>
            <?php elseif (fe_can('users', 'create')): ?>
                <button type="button" class="btn btn-gold" data-toggle-add-user aria-expanded="false" aria-controls="add-user-form">Add user</button>
            <?php endif; ?>
        </div>
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
                <input type="tel" name="phone" value="<?= e($editUser['phone']) ?>" maxlength="20" data-validate="phone-optional" inputmode="tel">
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
    <div class="admin-add-user" id="add-user-form" hidden>
        <p class="muted">Fill in the details below, then share the login with the new user.</p>
        <form class="stack-form" method="post" action="<?= e(url('users/save')) ?>" data-validate-form novalidate>
            <?= fe_csrf_field() ?>
            <div class="form-grid">
                <label>Name
                    <input name="name" required maxlength="120" data-validate="required" placeholder="Full name">
                    <span class="field-msg" data-field-error="name" hidden></span>
                </label>
                <label>Username
                    <input name="username" maxlength="30" autocomplete="username" pattern="[A-Za-z0-9._\-]*" title="3–30 characters: letters, numbers, underscores, periods, or hyphens" placeholder="Optional — from email if blank">
                </label>
                <label>Email
                    <input type="email" name="email" required maxlength="190" data-validate="email" placeholder="name@example.com">
                    <span class="field-msg" data-field-error="email" hidden></span>
                </label>
                <label>Phone
                    <input type="tel" name="phone" maxlength="20" data-validate="phone-optional" inputmode="tel">
                    <span class="field-msg" data-field-error="phone" hidden></span>
                </label>
                <label>Role
                    <select name="role" required>
                        <?php foreach (fe_roles() as $k=>$l): if ($k==='admin' && !fe_is_admin()) continue; ?>
                            <option value="<?= e($k) ?>" <?= $k === 'worker' ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (fe_is_admin()): ?>
                <label class="wide">Farm
                    <select name="farm_ids[]" data-farm-assign>
                        <option value="">Select farm…</option>
                        <?php foreach ($farms as $id=>$name): ?>
                            <option value="<?= e($id) ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label class="wide">Temporary password
                    <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
                </label>
                <label>Status
                    <select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </label>
                <label class="wide checkbox-row">
                    <input type="checkbox" name="must_change_password" value="1" checked>
                    <span>Require password change on first sign-in</span>
                </label>
            </div>
            <div class="admin-add-user-actions">
                <button class="btn btn-gold" type="submit">Create user</button>
                <button type="button" class="btn btn-ghost" data-toggle-add-user>Cancel</button>
            </div>
        </form>
    </div>
    <script>
    (function () {
        var btn = document.querySelector('[data-toggle-add-user]');
        var panel = document.getElementById('add-user-form');
        if (!btn || !panel) return;
        var toggles = document.querySelectorAll('[data-toggle-add-user]');
        function sync(open) {
            panel.hidden = !open;
            toggles.forEach(function (el) {
                if (el.tagName === 'BUTTON' && el.classList.contains('btn-gold') && el.getAttribute('aria-controls') === 'add-user-form') {
                    el.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
            });
            if (open) {
                var first = panel.querySelector('input[name="name"]');
                if (first) first.focus();
            }
        }
        toggles.forEach(function (el) {
            el.addEventListener('click', function () { sync(panel.hidden); });
        });
    })();
    </script>
    <?php endif; ?>

    <form class="search-form user-filter-form" method="get" action="<?= e(url('users')) ?>">
        <label class="sr-only" for="user-q">Search users</label>
        <input id="user-q" type="search" name="q" value="<?= e(input('q')) ?>" aria-label="Search name, username or email">
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
    <div class="table-wrap sticky-head user-mgmt-table">
        <table data-user-table>
            <thead>
                <tr>
                    <th>User information</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Assigned role</th>
                    <th>Registration date</th>
                    <th>Account status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $u):
                $status = (string) ($u['status'] ?? '');
                $reqRole = (string) ($u['requested_role'] ?: $u['role'] ?: '');
                $farmId = (int) ($u['farm_id'] ?? 0);
                $canManage = fe_can_manage_user($u);
                $isSelf = (int) $u['id'] === (int) fe_id();
            ?>
                <tr class="<?= $editUser && (int)$editUser['id']===(int)$u['id'] ? 'is-editing' : '' ?>">
                    <td>
                        <strong><?= e($u['name']) ?></strong>
                        <?php if (!empty($u['farm_name'])): ?><span class="muted user-farm-line"><?= e($u['farm_name']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e(($u['username'] ?? '') !== '' ? $u['username'] : '—') ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td>
                        <span class="badge"><?= e(fe_roles()[$u['role']] ?? $u['role']) ?></span>
                        <?php if (!empty($u['requested_role']) && $status === 'pending'): ?>
                            <small class="muted">req. <?= e(fe_roles()[$u['requested_role']] ?? $u['requested_role']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e(dater($u['created_at'] ?? null, 'd M Y H:i')) ?></td>
                    <td><?= status_badge($status) ?></td>
                    <td class="user-actions">
                        <?php if ((fe_is_admin() || fe_role() === 'owner') && $status === 'pending' && $canManage && fe_can('users', 'edit')): ?>
                            <form method="post" action="<?= e(url('users/pending/' . (int) $u['id'] . '/approve')) ?>">
                                <?= fe_csrf_field() ?>
                                <input type="hidden" name="role" value="<?= e($reqRole) ?>">
                                <?php if ($farmId > 0): ?>
                                    <input type="hidden" name="farm_ids[]" value="<?= $farmId ?>">
                                    <input type="hidden" name="farm_id" value="<?= $farmId ?>">
                                <?php endif; ?>
                                <button type="submit" class="btn btn-approve btn-sm">Approve</button>
                            </form>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('users/pending/' . (int) $u['id'])) ?>">Review</a>
                        <?php elseif ((fe_is_admin() || fe_role() === 'owner') && in_array($status, ['approved', 'rejected', 'active'], true) && (($u['role'] ?? '') === 'owner' || ($u['requested_role'] ?? '') === 'owner' || $status !== 'active')): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('users/pending/' . (int) $u['id'])) ?>">Review</a>
                        <?php endif; ?>
                        <?php if (fe_can('users', 'edit') && $canManage && $status === 'active' && (($u['role'] ?? '') === 'owner')): ?>
                            <form method="post" action="<?= e(url('users/pending/' . (int) $u['id'] . '/resend-email')) ?>">
                                <?= fe_csrf_field() ?>
                                <input type="hidden" name="redirect_to" value="users">
                                <button type="submit" class="btn btn-ghost btn-sm">Resend email</button>
                            </form>
                        <?php endif; ?>
                        <?php if (fe_can('users', 'edit') && $canManage && $status === 'active' && !$isSelf): ?>
                            <form method="post" action="<?= e(url('users/status/' . (int) $u['id'])) ?>" data-confirm="Suspend <?= e($u['name']) ?>? They will not be able to sign in until reactivated.">
                                <?= fe_csrf_field() ?>
                                <input type="hidden" name="status" value="suspended">
                                <button type="submit" class="btn btn-suspend btn-sm">Suspend</button>
                            </form>
                        <?php endif; ?>
                        <?php if (fe_can('users', 'edit') && $canManage && in_array($status, ['suspended', 'inactive'], true) && !$isSelf): ?>
                            <form method="post" action="<?= e(url('users/status/' . (int) $u['id'])) ?>">
                                <?= fe_csrf_field() ?>
                                <input type="hidden" name="status" value="active">
                                <button type="submit" class="btn btn-reactivate btn-sm">Reactivate</button>
                            </form>
                        <?php endif; ?>
                        <?php if (fe_can('users', 'edit') && $canManage && !in_array($status, ['pending'], true)): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('users/edit/' . (int) $u['id'])) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (fe_can('users', 'delete') && !$isSelf && $canManage): ?>
                            <form method="post" action="<?= e(url('users/delete/' . (int) $u['id'])) ?>" data-confirm="Delete <?= e($u['name']) ?>? Pending requests are removed; other accounts are deactivated so farm and business records stay intact.">
                                <?= fe_csrf_field() ?>
                                <button type="submit" class="btn btn-delete btn-sm">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted">No users match this filter.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
