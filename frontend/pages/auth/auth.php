<?php
$authTab = $authTab ?? 'login';
$resetToken = $resetToken ?? (string) input('token');
$activateToken = $activateToken ?? (string) (input('token') ?: '');
$isSignup = $authTab === 'signup';
$isForgot = $authTab === 'forgot';
$isReset = $authTab === 'reset';
$isActivate = $authTab === 'activate';
$title = $isSignup ? 'Sign up' : ($isForgot ? 'Forgot password' : ($isReset ? 'Reset password' : ($isActivate ? 'Activate account' : 'Sign in')));
$accountTypes = RegistrationService::requestableRoles();
$authHero = asset('img/auth-farm-hero.jpg') . '?v=p13';
$formHeading = $isSignup ? 'Sign up' : ($isForgot ? 'Forgot password' : ($isReset ? 'Reset password' : ($isActivate ? 'Activate account' : 'Sign in')));
$demoSignIn = is_array($demoSignIn ?? null) ? $demoSignIn : ['accounts' => [], 'fills' => []];
$demoAccounts = is_array($demoSignIn['accounts'] ?? null) ? $demoSignIn['accounts'] : [];
$demoFills = is_array($demoSignIn['fills'] ?? null) ? $demoSignIn['fills'] : [];
$authAssetV = 'p30';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head(ucfirst($title)) ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=<?= e($authAssetV) ?>">
</head>
<body class="auth-shell-page">
<div class="auth-shell">
    <div class="auth-shell-card">
        <aside class="auth-shell-visual" style="background-image:url('<?= e($authHero) ?>')" aria-hidden="true">
            <div class="auth-shell-visual-overlay"></div>
            <div class="auth-shell-visual-inner">
                <a class="auth-shell-brand" href="<?= e(url()) ?>">
                    <?= brand_logo('brand-logo brand-logo-auth-shell') ?>
                    <span>FarmWise <em>AI</em></span>
                </a>
                <p class="auth-shell-pillars">MANAGE · TRACK · PLAN · GROW</p>
                <div class="auth-shell-hero-copy">
                    <h1>Welcome to FarmWise AI</h1>
                    <p>Your intelligent farm management workspace.</p>
                </div>
                <ul class="auth-shell-features">
                    <li>
                        <span class="auth-shell-feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/></svg>
                        </span>
                        <span>Organise Fields</span>
                    </li>
                    <li>
                        <span class="auth-shell-feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3"/><path d="M4 20c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
                        </span>
                        <span>Track Livestock</span>
                    </li>
                    <li>
                        <span class="auth-shell-feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        </span>
                        <span>Manage Tasks</span>
                    </li>
                    <li>
                        <span class="auth-shell-feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 17V9"/><path d="M12 17V6"/><path d="M16 17v-4"/></svg>
                        </span>
                        <span>Review Reports</span>
                    </li>
                </ul>
            </div>
        </aside>

        <div class="auth-shell-form">
            <a class="auth-shell-form-brand" href="<?= e(url()) ?>">
                <?= brand_logo('brand-logo brand-logo-auth-form') ?>
                <span>FarmWise <em>AI</em></span>
            </a>

            <h2 class="auth-shell-title"><?= e($formHeading) ?></h2>

            <?php if ($msg = flash('error')): ?><div class="alert danger" data-flash-toast hidden><?= e($msg) ?></div><?php endif; ?>
            <?php if ($msg = flash('success')): ?><div class="alert ok" data-flash-toast hidden><?= e($msg) ?></div><?php endif; ?>

            <?php if ($isForgot): ?>
                <form method="post" action="<?= e(url('forgot-password')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <label class="auth-field">
                        <span class="auth-field-label">Email</span>
                        <span class="auth-field-control">
                            <span class="auth-field-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                            </span>
                            <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required maxlength="190" data-validate="email">
                        </span>
                        <span class="field-msg" data-field-error="email" hidden></span>
                    </label>
                    <button class="btn btn-auth-primary btn-block" type="submit">Send Reset Link</button>
                </form>
                <p class="auth-shell-switch">Remembered it? <a href="<?= e(url('login')) ?>">Sign In</a></p>
            <?php elseif ($isReset): ?>
                <form method="post" action="<?= e(url('reset-password')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($resetToken) ?>">
                    <label class="auth-field">
                        <span class="auth-field-label">New password</span>
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
                        <span class="field-msg" data-password-hint hidden></span>
                    </label>
                    <label class="auth-field">
                        <span class="auth-field-label">Confirm password</span>
                        <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm']) ?>
                        <span class="field-msg" data-password-match-hint hidden></span>
                    </label>
                    <button class="btn btn-auth-primary btn-block" type="submit">Save New Password</button>
                </form>
                <p class="auth-shell-switch">Back to <a href="<?= e(url('login')) ?>">Sign In</a></p>
            <?php elseif ($isActivate): ?>
                <form method="post" action="<?= e(url('activate')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($activateToken) ?>">
                    <label class="auth-field">
                        <span class="auth-field-label">Password</span>
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
                        <span class="field-msg" data-password-hint hidden></span>
                    </label>
                    <label class="auth-field">
                        <span class="auth-field-label">Confirm password</span>
                        <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm']) ?>
                        <span class="field-msg" data-password-match-hint hidden></span>
                    </label>
                    <button class="btn btn-auth-primary btn-block" type="submit">Activate account</button>
                </form>
                <p class="auth-shell-switch">Ready? <a href="<?= e(url('login')) ?>">Sign In</a></p>
            <?php elseif ($isSignup): ?>
                <form method="post" action="<?= e(url('register')) ?>" class="auth-shell-fields" data-validate-form novalidate id="signup-form">
                    <?= fe_csrf_field() ?>
                    <div class="auth-shell-grid auth-signup-grid">
                        <label class="auth-field">
                            <span class="auth-field-label">Full name</span>
                            <span class="auth-field-control">
                                <input name="name" value="<?= e(old('name')) ?>" autocomplete="name" required maxlength="120" data-validate="required">
                            </span>
                            <span class="field-msg" data-field-error="name" hidden></span>
                        </label>
                        <label class="auth-field">
                            <span class="auth-field-label">Username</span>
                            <span class="auth-field-control">
                                <input name="username" value="<?= e(old('username')) ?>" autocomplete="username" required maxlength="30" minlength="3" pattern="[A-Za-z0-9._\-]+" data-validate="username">
                            </span>
                            <span class="field-msg" data-field-error="username" hidden></span>
                        </label>
                        <label class="auth-field">
                            <span class="auth-field-label">Email</span>
                            <span class="auth-field-control">
                                <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required maxlength="190" data-validate="email">
                            </span>
                            <span class="field-msg" data-field-error="email" hidden></span>
                        </label>
                        <label class="auth-field">
                            <span class="auth-field-label">Phone</span>
                            <span class="auth-field-control">
                                <input type="tel" name="phone" value="<?= e(old('phone')) ?>" autocomplete="tel" inputmode="tel" required data-validate="phone" maxlength="20">
                            </span>
                            <span class="field-msg" data-field-error="phone" hidden></span>
                        </label>
                        <label class="auth-field">
                            <span class="auth-field-label">Password</span>
                            <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
                            <span class="field-msg" data-password-hint hidden></span>
                        </label>
                        <label class="auth-field">
                            <span class="auth-field-label">Confirm password</span>
                            <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm']) ?>
                            <span class="field-msg" data-password-match-hint hidden></span>
                        </label>
                        <label class="auth-field auth-field-wide">
                            <span class="auth-field-label">Role</span>
                            <span class="auth-field-control">
                                <select name="role" id="signup-role" required data-validate="role">
                                    <?php foreach ($accountTypes as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= old('role', 'worker') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </span>
                            <span class="field-msg" data-field-error="role" hidden></span>
                        </label>
                        <?php $signupRole = (string) old('role', 'worker'); ?>
                        <label class="auth-field auth-field-wide" data-farm-code-box <?= $signupRole === 'owner' ? 'hidden' : '' ?>>
                            <span class="auth-field-label">Farm Code</span>
                            <span class="auth-field-control">
                                <input name="farm_code" id="signup-farm-code" value="<?= e(old('farm_code')) ?>" maxlength="20" autocomplete="off" <?= $signupRole === 'owner' ? '' : 'required' ?>>
                            </span>
                            <span class="field-msg" data-farm-preview></span>
                        </label>
                    </div>
                    <button class="btn btn-auth-primary btn-block" type="submit">Create account</button>
                </form>
                <p class="auth-shell-switch">Have an account? <a href="<?= e(url('login')) ?>">Sign in</a></p>
            <?php else: ?>
                <form method="post" action="<?= e(url('login')) ?>" class="auth-shell-fields" data-validate-form novalidate data-quick-signin>
                    <?= fe_csrf_field() ?>
                    <label class="auth-field">
                        <span class="auth-field-label">Username or email</span>
                        <span class="auth-field-control auth-quick-wrap">
                            <span class="auth-field-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
                            </span>
                            <input name="email" id="login-identity" value="<?= e(old('email')) ?>" autocomplete="username" required maxlength="190" data-validate="login-identity" data-quick-signin-input aria-autocomplete="list" aria-controls="quick-signin-list" aria-expanded="false">
                            <?php if ($demoAccounts): ?>
                            <div class="quick-signin" id="quick-signin" hidden>
                                <p class="quick-signin-title">Quick sign-in</p>
                                <ul class="quick-signin-list" id="quick-signin-list" role="listbox">
                                    <?php foreach ($demoAccounts as $demo): ?>
                                    <li>
                                        <button type="button" class="quick-signin-option" role="option"
                                            data-identity="<?= e($demo['identity']) ?>"
                                            data-email="<?= e($demo['email']) ?>"
                                            data-can-fill="<?= !empty($demo['can_fill_password']) ? '1' : '0' ?>">
                                            <span class="quick-signin-avatar" aria-hidden="true"><?= e($demo['initials']) ?></span>
                                            <span class="quick-signin-copy">
                                                <strong><?= e($demo['name']) ?></strong>
                                                <span><?= e($demo['identity']) ?></span>
                                            </span>
                                            <span class="quick-signin-role">
                                                <span class="quick-signin-role-icon" aria-hidden="true">
                                                    <?php if (($demo['icon'] ?? '') === 'shield'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/></svg>
                                                    <?php elseif (($demo['icon'] ?? '') === 'landmark'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V10l7-5 7 5v11"/><path d="M9 21v-6h6v6"/></svg>
                                                    <?php elseif (($demo['icon'] ?? '') === 'leaf'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
                                                    <?php elseif (($demo['icon'] ?? '') === 'wrench'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                                    <?php else: ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>
                                                    <?php endif; ?>
                                                </span>
                                                <?= e($demo['label']) ?>
                                            </span>
                                        </button>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="quick-signin-note">Passwords stay hidden. Selecting a demo account fills the sign-in form for you.</p>
                            </div>
                            <?php endif; ?>
                        </span>
                        <span class="field-msg" data-field-error="email" hidden></span>
                    </label>
                    <label class="auth-field">
                        <span class="auth-field-label">Password</span>
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'extra' => 'data-validate="password" data-quick-signin-password']) ?>
                        <span class="field-msg" data-field-error="password" hidden></span>
                    </label>
                    <div class="auth-inline">
                        <label class="auth-check">
                            <input type="checkbox" name="remember" value="1">
                            Remember me
                        </label>
                        <a class="auth-forgot" href="<?= e(url('forgot-password')) ?>">Forgot Password?</a>
                    </div>
                    <button class="btn btn-auth-primary btn-block" type="submit">Sign In</button>
                </form>
                <p class="auth-shell-switch muted">Accounts are created by an administrator. Contact your farm admin if you need access.</p>
            <?php endif; ?>

            <p class="auth-shell-home"><a href="<?= e(url()) ?>">← Back to FarmWise home</a></p>
        </div>
    </div>
</div>
<?php if (!$isSignup && !$isForgot && !$isReset && !$isActivate && $demoAccounts): ?>
<script type="application/json" id="quick-signin-fills"><?= json_encode($demoFills, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<script src="<?= e(asset('js/ui.js')) ?>?v=<?= e($authAssetV) ?>"></script>
<script>window.IFMS_BASE = <?= json_encode(rtrim(url(''), '/')) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e($authAssetV) ?>"></script>
</body>
</html>
