<?php $user = $user ?? fe_user(); $farm = $farm ?? null; ?>
<section class="panel">
    <header><h3>My Profile</h3></header>
    <form class="stack-form" method="post" action="<?= e(url('profile')) ?>" data-validate-form novalidate>
        <?= fe_csrf_field() ?>
        <div class="form-grid">
            <label>Display name
                <input name="name" value="<?= e(old('name', $user['name'])) ?>" required maxlength="120" data-validate="required">
                <span class="field-msg" data-field-error="name" hidden></span>
            </label>
            <label>Email
                <input type="email" value="<?= e($user['email']) ?>" readonly>
                <small class="muted">Email changes require a separate verification workflow and cannot be updated here.</small>
            </label>
            <label>Phone number
                <input type="tel" name="phone" value="<?= e(old('phone', $user['phone'])) ?>" autocomplete="tel" inputmode="tel" placeholder="+26097XXXXXXX" maxlength="20" data-validate="phone-optional">
                <span class="field-msg" data-field-error="phone" hidden></span>
            </label>
            <label>Role
                <input value="<?= e(fe_roles()[$user['role']] ?? $user['role']) ?>" readonly>
            </label>
            <label class="wide">Assigned farm
                <input value="<?= e($farm['name'] ?? 'No farm assigned') ?>" readonly>
            </label>
        </div>
        <button class="btn btn-gold" type="submit">Save Changes</button>
    </form>
</section>

<section class="panel">
    <header><h3>Change password</h3></header>
    <form class="stack-form" method="post" action="<?= e(url('profile/password')) ?>">
        <?= fe_csrf_field() ?>
        <div class="form-grid">
            <label class="wide">Current password
                <?= password_input(['name' => 'current_password', 'id' => 'current_password', 'required' => true, 'autocomplete' => 'current-password']) ?>
            </label>
            <label class="wide">New password
                <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
                <span class="field-msg" data-password-hint hidden></span>
            </label>
            <label class="wide">Confirm new password
                <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm']) ?>
                <span class="field-msg" data-password-match-hint hidden></span>
            </label>
        </div>
        <button class="btn btn-gold" type="submit">Change Password</button>
    </form>
</section>
