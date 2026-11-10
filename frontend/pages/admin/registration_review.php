<?php
fe_component('partials/frontend');
include __DIR__ . '/_nav.php';
$pendingUser = $pendingUser ?? null;
$farms = $farms ?? [];
$assigned = $assigned ?? [];
$roles = RegistrationService::requestableRoles();
$status = $pendingUser['status'] ?? '';
?>
<section class="panel">
    <header class="panel-tools">
        <h3>Registration request</h3>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('users')) ?>">Back to users</a>
    </header>
    <?php if (!$pendingUser): ?>
        <p class="muted">Request not found.</p>
    <?php else: ?>
        <dl class="detail-list">
            <div><dt>Name</dt><dd><?= e($pendingUser['name']) ?></dd></div>
            <div><dt>Username</dt><dd><?= e($pendingUser['username'] ?: '—') ?></dd></div>
            <div><dt>Email</dt><dd><?= e($pendingUser['email']) ?></dd></div>
            <div><dt>Phone</dt><dd><?= e($pendingUser['phone'] ?: '—') ?></dd></div>
            <div><dt>Requested role</dt><dd><?= e(fe_roles()[$pendingUser['requested_role'] ?? ''] ?? ($pendingUser['requested_role'] ?? '—')) ?></dd></div>
            <div><dt>Submitted</dt><dd><?= e(dater($pendingUser['created_at'], 'd M Y H:i')) ?></dd></div>
            <div><dt>Status</dt><dd><?= status_badge($status) ?></dd></div>
        </dl>

        <?php if ($status === 'pending'): ?>
            <?php $alreadyChosePassword = (int) ($pendingUser['must_change_password'] ?? 1) === 0; ?>
            <form method="post" action="<?= e(url('users/pending/' . $pendingUser['id'] . '/approve')) ?>" class="stack-form" style="margin-top:1.25rem">
                <?= fe_csrf_field() ?>
                <h4><?= fe_is_admin() ? 'Approve &amp; assign' : 'Approve this request' ?></h4>
                <?php if (!fe_is_admin()): ?>
                    <input type="hidden" name="role" value="<?= e((string) ($pendingUser['requested_role'] ?? $pendingUser['role'] ?? '')) ?>">
                <?php endif; ?>
                <div class="form-grid">
                    <?php if (fe_is_admin()): ?>
                    <label>Final role
                        <select name="role" required>
                            <?php foreach ($roles as $k => $l): ?>
                                <option value="<?= e($k) ?>" <?= ($pendingUser['requested_role'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="wide">Assign farm(s)
                        <select name="farm_ids[]" multiple size="6" style="min-height:8rem">
                            <?php foreach ($farms as $fid => $fname): ?>
                                <option value="<?= e($fid) ?>" <?= in_array((int)$fid, array_map('intval', $assigned), true) ? 'selected' : '' ?>><?= e($fname) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="muted">A farm owner can be approved with no farm selected. They create the farm after signing in.</small>
                    </label>
                    <label class="wide">
                        <input type="checkbox" name="create_owner_farm" value="1">
                        Create a new farm workspace if role is Farm Owner and no farm is selected
                    </label>
                    <?php endif; ?>
                    <label class="wide">Notes (optional)
                        <textarea name="notes" rows="2" maxlength="1000"></textarea>
                    </label>
                </div>
                <button class="btn btn-gold" type="submit"><?= $alreadyChosePassword ? 'Approve account' : 'Approve &amp; send activation email' ?></button>
            </form>

            <form method="post" action="<?= e(url('users/pending/' . $pendingUser['id'] . '/reject')) ?>" class="stack-form" style="margin-top:1.5rem" data-confirm="Reject this registration request?">
                <?= fe_csrf_field() ?>
                <h4>Reject</h4>
                <label>Reason (optional)
                    <textarea name="rejection_reason" rows="2" maxlength="1000"></textarea>
                </label>
                <button class="btn btn-ghost" type="submit">Reject request</button>
            </form>
        <?php elseif ($status === 'approved'): ?>
            <p class="muted">Approved — waiting for the applicant to open their activation email and set a password. Passwords are never emailed.</p>
            <?php if (($pendingUser['role'] ?? '') === 'owner' || ($pendingUser['requested_role'] ?? '') === 'owner'): ?>
            <form method="post" action="<?= e(url('users/pending/' . $pendingUser['id'] . '/resend-email')) ?>" style="margin-top:1rem">
                <?= fe_csrf_field() ?>
                <button class="btn btn-gold" type="submit">Resend approval email</button>
            </form>
            <?php endif; ?>
        <?php elseif ($status === 'active'): ?>
            <p class="muted">This account is active. The owner signs in with the email/username and the password they created at registration. FarmWise never emails passwords.</p>
            <?php if (($pendingUser['role'] ?? '') === 'owner'): ?>
            <form method="post" action="<?= e(url('users/pending/' . $pendingUser['id'] . '/resend-email')) ?>" style="margin-top:1rem">
                <?= fe_csrf_field() ?>
                <button class="btn btn-gold" type="submit">Resend approval email</button>
            </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">Rejection reason: <?= e($pendingUser['rejection_reason'] ?: '—') ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
