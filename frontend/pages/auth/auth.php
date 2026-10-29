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
$formHeading = $isSignup ? 'Request access' : ($isForgot ? 'Forgot password' : ($isReset ? 'Reset password' : ($isActivate ? 'Activate account' : 'Sign in')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head(ucfirst($title)) ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=p18">
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
                <p class="auth-shell-lede">Enter your email address and we’ll help you reset your password.</p>
                <form method="post" action="<?= e(url('forgot-password')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <label class="auth-field">
                        <span class="auth-field-label">Email</span>
                        <span class="auth-field-control">
                            <span class="auth-field-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                            </span>
                            <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" placeholder="you@example.com" required maxlength="190" data-validate="email">
                        </span>
                        <span class="field-msg" data-field-error="email" hidden></span>
                    </label>
                    <button class="btn btn-auth-primary btn-block" type="submit">Send Reset Link</button>
                </form>
                <p class="auth-shell-switch">Remembered it? <a href="<?= e(url('login')) ?>">Sign In</a></p>
            <?php elseif ($isReset): ?>
                <p class="auth-shell-lede">Choose a strong password for your FarmWise account.</p>
                <form method="post" action="<?= e(url('reset-password')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($resetToken) ?>">
                    <label class="auth-field">
                        <span class="auth-field-label">New password</span>
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input', 'placeholder' => 'Create a password']) ?>
                        <span class="field-msg" data-password-hint hidden></span>
                    </label>
                    <label class="auth-field">
                        <span class="auth-field-label">Confirm password</span>
                        <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm', 'placeholder' => 'Confirm password']) ?>
                        <span class="field-msg" data-password-match-hint hidden></span>
                    </label>
                    <button class="btn btn-auth-primary btn-block" type="submit">Save New Password</button>
                </form>
                <p class="auth-shell-switch">Back to <a href="<?= e(url('login')) ?>">Sign In</a></p>
            <?php elseif ($isActivate): ?>
                <p class="auth-shell-lede">Choose a password to activate your approved FarmWise account.</p>
                <form method="post" action="<?= e(url('activate')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($activateToken) ?>">
                    <label class="auth-field">
                        <span class="auth-field-label">Password</span>
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input', 'placeholder' => 'Create a password']) ?>
                        <span class="field-msg" data-password-hint hidden></span>
                    </label>
                    <label class="auth-field">
                        <span class="auth-field-label">Confirm password</span>
                        <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm', 'placeholder' => 'Confirm password']) ?>
                        <span class="field-msg" data-password-match-hint hidden></span>
                    </label>
                    <button class="btn btn-auth-primary btn-block" type="submit">Activate account</button>
                </form>
                <p class="auth-shell-switch">Ready? <a href="<?= e(url('login')) ?>">Sign In</a></p>
            <?php elseif ($isSignup): ?>
                <p class="auth-shell-lede">Submit an access request. An administrator must approve you before you can sign in.</p>
                <form method="post" action="<?= e(url('register')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <div class="auth-shell-grid">
                        <label class="auth-field auth-field-wide">
                            <span class="auth-field-label">Username</span>
                            <span class="auth-field-control">
                                <input name="username" value="<?= e(old('username')) ?>" autocomplete="username" placeholder="e.g. liah_kunda3" required maxlength="30" minlength="3" pattern="[A-Za-z0-9._\-]+" data-validate="username" title="3–30 characters: letters, numbers, underscores, periods, or hyphens — no spaces">
                            </span>
                            <span class="field-msg" data-field-error="username" hidden></span>
                        </label>
                        <label class="auth-field auth-field-wide">
                            <span class="auth-field-label">Email</span>
                            <span class="auth-field-control">
                                <span class="auth-field-icon" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                                </span>
                                <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" placeholder="you@example.com" required maxlength="190" data-validate="email">
                            </span>
                            <span class="field-msg" data-field-error="email" hidden></span>
                        </label>
                        <label class="auth-field auth-field-wide">
                            <span class="auth-field-label">Phone Number</span>
                            <span class="auth-field-control">
                                <input type="tel" name="phone" value="<?= e(old('phone')) ?>" autocomplete="tel" inputmode="tel" placeholder="097XXXXXXX or +26097XXXXXXX" required data-validate="phone" maxlength="20">
                            </span>
                            <span class="field-msg" data-field-error="phone" hidden></span>
                        </label>
                        <label class="auth-field auth-field-wide">
                            <span class="auth-field-label">Requested role</span>
                            <span class="auth-field-control">
                                <select name="role" required data-validate="role">
                                    <?php foreach ($accountTypes as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= old('role', 'worker') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </span>
                            <span class="field-msg" data-field-error="role" hidden></span>
                        </label>
                    </div>
                    <button class="btn btn-auth-primary btn-block" type="submit">Submit request</button>
                </form>
                <p class="auth-shell-switch">Already approved? <a href="<?= e(url('login')) ?>">Sign In</a></p>
            <?php else: ?>
                <p class="auth-shell-lede">Sign in to access your farm management workspace.</p>
                <form method="post" action="<?= e(url('login')) ?>" class="auth-shell-fields" data-validate-form novalidate>
                    <?= fe_csrf_field() ?>
                    <label class="auth-field">
                        <span class="auth-field-label">Username or email</span>
                        <span class="auth-field-control">
                            <span class="auth-field-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
                            </span>
                            <input name="email" value="<?= e(old('email')) ?>" autocomplete="username" placeholder="Username or email" required maxlength="190" data-validate="login-identity">
                        </span>
                        <span class="field-msg" data-field-error="email" hidden></span>
                    </label>
                    <label class="auth-field">
                        <span class="auth-field-label">Password</span>
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'placeholder' => 'Password', 'extra' => 'data-validate="password"']) ?>
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
                <p class="auth-shell-switch">Need an account? <a href="<?= e(url('register')) ?>">Sign Up</a></p>
            <?php endif; ?>

            <p class="auth-shell-home"><a href="<?= e(url()) ?>">← Back to FarmWise home</a></p>
        </div>
    </div>
</div>
<script src="<?= e(asset('js/ui.js')) ?>?v=p18"></script>
<script src="<?= e(asset('js/app.js')) ?>?v=p20"></script>
</body>
</html>
