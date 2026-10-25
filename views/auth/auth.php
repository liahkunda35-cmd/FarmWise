<?php
$authTab = $authTab ?? 'login';
$resetToken = $resetToken ?? (string) (input('token') ?: ($_SESSION['password_reset_token'] ?? ''));
$isSignup = $authTab === 'signup';
$isForgot = $authTab === 'forgot';
$isReset = $authTab === 'reset';
$title = $isSignup ? 'Sign up' : ($isForgot ? 'Forgot password' : ($isReset ? 'Reset password' : 'Log in'));
$accountTypes = [
    'owner' => 'Farmer / Farm Owner',
    'manager' => 'Farm Manager',
    'worker' => 'Farm Worker',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head(ucfirst($title)) ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=p4">
</head>
<body class="public-body auth-hero-page">
<header class="pub-nav">
    <a class="brand" href="<?= e(url()) ?>">
        <?= brand_logo() ?>
    </a>
    <button type="button" class="pub-nav-toggle" data-pub-nav-toggle aria-expanded="false" aria-controls="pub-nav-links" aria-label="Open menu">☰</button>
    <nav id="pub-nav-links" data-pub-nav>
        <a href="<?= e(url()) ?>">Home</a>
        <a class="btn btn-gold btn-sm" href="<?= e(url($isSignup ? 'login' : 'register')) ?>"><?= $isSignup ? 'Log In' : 'Sign Up' ?></a>
    </nav>
</header>

<section class="hero auth-hero">
    <?php
    $heroSlides = config('hero_images');
    if (!is_array($heroSlides) || !$heroSlides) {
        $heroSlides = [config('hero_image')];
    }
    ?>
    <div class="hero-media hero-slideshow" data-hero-slideshow aria-hidden="true">
        <?php foreach ($heroSlides as $i => $slide): ?>
            <?php
            $slideUrl = is_string($slide) && (str_starts_with($slide, 'http://') || str_starts_with($slide, 'https://'))
                ? $slide
                : asset((string) $slide);
            ?>
            <div class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>" style="background-image:url('<?= e($slideUrl) ?>')"></div>
        <?php endforeach; ?>
    </div>
    <div class="hero-overlay"></div>
    <div class="auth-hero-grid">
        <div class="hero-copy auth-hero-copy">
            <p class="eyebrow hero-kicker">FarmWise</p>
            <h1>Precision for the people who run the land.</h1>
            <p class="lede">Log in or create an account to reach your farm workspace — fields, livestock, water, labour and finance in one place.</p>
        </div>

        <div class="glass-auth">
            <a class="glass-auth-brand" href="<?= e(url()) ?>"><?= brand_logo('brand-logo brand-logo-auth') ?></a>
            <div class="auth-tabs" role="tablist">
                <a class="<?= !$isSignup && !$isForgot && !$isReset ? 'active' : '' ?>" href="<?= e(url('login')) ?>">Log In</a>
                <a class="<?= $isSignup ? 'active' : '' ?>" href="<?= e(url('register')) ?>">Sign Up</a>
            </div>

            <?php if ($msg = flash('error')): ?><div class="alert danger"><?= e($msg) ?></div><?php endif; ?>
            <?php if ($msg = flash('success')): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

            <?php if ($isForgot): ?>
                <h2 class="auth-title">Forgot your password?</h2>
                <p class="muted auth-lede">Enter your email address and we'll help you reset your password.</p>
                <form method="post" action="<?= e(url('forgot-password')) ?>">
                    <?= Csrf::field() ?>
                    <label>Email
                        <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required>
                    </label>
                    <button class="btn btn-gold btn-block" type="submit">Send Reset Link</button>
                </form>
                <p class="muted auth-switch">Remembered it? <a href="<?= e(url('login')) ?>">Log In</a></p>
            <?php elseif ($isReset): ?>
                <h2 class="auth-title">Create a new password</h2>
                <p class="muted auth-lede">Choose a strong password for your Farm Management System account.</p>
                <form method="post" action="<?= e(url('reset-password')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="token" value="<?= e($resetToken) ?>">
                    <label>New password
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
                        <span class="field-msg" data-password-hint hidden></span>
                    </label>
                    <label>Confirm password
                        <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm']) ?>
                        <span class="field-msg" data-password-match-hint hidden></span>
                    </label>
                    <button class="btn btn-gold btn-block" type="submit">Save New Password</button>
                </form>
                <p class="muted auth-switch">Back to <a href="<?= e(url('login')) ?>">Log In</a></p>
            <?php elseif ($isSignup): ?>
                <h2 class="auth-title">Create your account</h2>
                <p class="muted auth-lede">New to FarmWise? Register to start managing the farm.</p>
                <form method="post" action="<?= e(url('register')) ?>">
                    <?= Csrf::field() ?>
                    <div class="form-grid">
                        <label>First Name
                            <input name="first_name" value="<?= e(old('first_name')) ?>" autocomplete="given-name" required>
                        </label>
                        <label>Last Name
                            <input name="last_name" value="<?= e(old('last_name')) ?>" autocomplete="family-name" required>
                        </label>
                        <label class="wide">Email
                            <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required>
                        </label>
                        <label class="wide">Phone Number
                            <input name="phone" value="<?= e(old('phone')) ?>" autocomplete="tel" placeholder="+260..." required>
                        </label>
                        <label class="wide">Account Type
                            <select name="role" required data-signup-role>
                                <?php foreach ($accountTypes as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= old('role', 'owner') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="wide" data-join-code-field hidden>
                            Farm join code
                            <input name="join_code" value="<?= e(old('join_code')) ?>" placeholder="Code from the farm owner" autocomplete="off">
                            <small class="muted">Managers and workers join an existing farm with this code. It does not grant ownership.</small>
                        </label>
                        <label class="wide">Password
                            <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-input']) ?>
                            <span class="field-msg" data-password-hint hidden></span>
                        </label>
                        <label class="wide">Confirm Password
                            <?= password_input(['name' => 'password_confirm', 'id' => 'password_confirm', 'required' => true, 'autocomplete' => 'new-password', 'extra' => 'data-password-confirm']) ?>
                            <span class="field-msg" data-password-match-hint hidden></span>
                        </label>
                    </div>
                    <button class="btn btn-gold btn-block" type="submit">Create Account</button>
                </form>
                <p class="muted auth-switch">Already have an account? <a href="<?= e(url('login')) ?>">Log In</a></p>
            <?php else: ?>
                <h2 class="auth-title">Welcome Back</h2>
                <p class="muted auth-lede">Log in to access your Farm Management System.</p>
                <form method="post" action="<?= e(url('login')) ?>">
                    <?= Csrf::field() ?>
                    <label>Email / Username
                        <input name="email" value="<?= e(old('email')) ?>" autocomplete="username" required>
                    </label>
                    <label>Password
                        <?= password_input(['name' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
                    </label>
                    <div class="auth-inline">
                        <label class="auth-check">
                            <input type="checkbox" name="remember" value="1">
                            Remember me
                        </label>
                        <a class="auth-forgot" href="<?= e(url('forgot-password')) ?>">Forgot Password?</a>
                    </div>
                    <button class="btn btn-gold btn-block" type="submit">Log In</button>
                </form>
                <p class="muted auth-switch">Don't have an account? <a href="<?= e(url('register')) ?>">Sign Up</a></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<script src="<?= e(asset('js/app.js')) ?>?v=p4"></script>
</body>
</html>
