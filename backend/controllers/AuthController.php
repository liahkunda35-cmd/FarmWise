<?php

class AuthController
{
    public static function landing(): void
    {
        if (Auth::check()) {
            redirect(Auth::dashboardPathForRole(Auth::role()));
        }
        view('landing');
    }

    public static function loginForm(): void
    {
        self::show(input('tab') === 'signup' ? 'signup' : 'login');
    }

    public static function login(): void
    {
        Csrf::verify();
        $identity = trim((string) input('email'));
        $password = (string) input('password');
        $remember = (string) input('remember') !== '';
        if ($identity === '' || $password === '') {
            flash('error', 'Please complete all required fields.');
            remember_old(['email' => $identity]);
            redirect('login');
        }
        try {
            $result = Auth::attemptLogin($identity, $password, $remember);
        } catch (Throwable $e) {
            log_internal_error('login', $e);
            flash('error', 'We couldn\'t complete your request. Please try again later.');
            remember_old(['email' => $identity]);
            redirect('login');
        }
        if (empty($result['ok'])) {
            $raw = (string) ($result['error'] ?? '');
            $friendly = match (true) {
                str_contains($raw, 'awaiting administrator') => 'Your registration is still awaiting administrator approval.',
                str_contains($raw, 'activation link') => 'Your account was approved. Open the activation link in your email to set your password before signing in.',
                str_contains($raw, 'not approved') => 'Your registration was not approved. Contact your administrator.',
                str_contains($raw, 'suspended') => 'Your account is suspended. Contact your administrator.',
                default => 'Invalid username/email or password.',
            };
            flash('error', user_safe_message($friendly, 'Invalid username/email or password.'));
            remember_old(['email' => $identity]);
            redirect('login');
        }
        clear_old();
        if (!empty($result['must_change_password'])) {
            flash('success', 'Login successful! Please set a new password to continue.');
            redirect('profile');
        }
        flash('success', 'Login successful!');
        redirect(Auth::dashboardPathForRole(Auth::role()));
    }

    public static function registerForm(): void
    {
        self::show('signup');
    }

    public static function register(): void
    {
        Csrf::verify();
        $payload = [
            'username' => input('username'),
            'email' => input('email'),
            'phone' => input('phone'),
            'role' => input('role', 'worker'),
        ];
        try {
            $result = RegistrationService::submitRequest($payload);
        } catch (Throwable $e) {
            log_internal_error('registration', $e);
            flash('error', 'We couldn\'t complete your request. Please try again later.');
            remember_old($payload);
            redirect('register');
        }
        if (empty($result['ok'])) {
            flash('error', user_safe_message(
                (string) ($result['error'] ?? 'Registration failed.'),
                'Something went wrong. Please try again later.'
            ));
            remember_old($payload);
            redirect('register');
        }
        clear_old();
        // Never surface mail-provider / API diagnostics to applicants.
        if (empty($result['email_result']['ok'])) {
            error_log('FarmWise registration mail failed: ' . redact_for_log((string) ($result['email_result']['error'] ?? 'unknown')));
        }
        flash('success', 'Registration successful!');
        redirect('login');
    }

    public static function activateForm(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }
        $token = (string) (input('token') ?: '');
        if ($token === '') {
            flash('error', 'This activation link is invalid or has expired.');
            redirect('login');
        }
        view('auth/auth', ['authTab' => 'activate', 'activateToken' => $token]);
    }

    public static function activate(): void
    {
        Csrf::verify();
        $token = (string) input('token');
        $result = RegistrationService::activate($token, (string) input('password'), (string) input('password_confirm'));
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Activation failed.'));
            redirect('activate' . ($token !== '' ? ('?token=' . urlencode($token)) : ''));
        }
        flash('success', 'Your account is active. Sign in with your email or username and the password you just created.');
        redirect('login');
    }

    public static function forgotForm(): void
    {
        self::show('forgot');
    }

    public static function forgot(): void
    {
        Csrf::verify();
        $email = strtolower(trim((string) input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
            remember_old(['email' => $email]);
            redirect('forgot-password');
        }
        $user = db()->first("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
        $raw = bin2hex(random_bytes(32));
        if ($user) {
            db()->query('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [
                date('Y-m-d H:i:s'),
                $user['id'],
            ]);
            db()->insert('password_resets', [
                'user_id' => $user['id'],
                'token_hash' => hash('sha256', $raw),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            ]);
            $link = absolute_url('reset-password') . '?token=' . urlencode($raw);
            $mail = AccountMail::passwordReset($user, $link);
            $_SESSION['password_reset_token'] = $raw;
            if (empty($mail['ok'])) {
                // Still allow local reset flow; surface diagnostic without claiming success falsely for email.
                flash('success', 'If an account exists for that email, you can choose a new password below. Email delivery note: ' . ($mail['error'] ?? 'not sent'));
                redirect('reset-password');
            }
        } else {
            $_SESSION['password_reset_token'] = bin2hex(random_bytes(32));
        }
        clear_old();
        flash('success', 'If an account exists for that email, we sent a reset link. You can choose a new password below.');
        redirect('reset-password');
    }

    public static function resetForm(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }
        $token = (string) (input('token') ?: ($_SESSION['password_reset_token'] ?? ''));
        if ($token === '') {
            flash('error', 'This reset link is invalid or has expired. Request a new one.');
            redirect('forgot-password');
        }
        view('auth/auth', ['authTab' => 'reset', 'resetToken' => $token]);
    }

    public static function reset(): void
    {
        Csrf::verify();
        $token = (string) (input('token') ?: ($_SESSION['password_reset_token'] ?? ''));
        $password = (string) input('password');
        $confirm = (string) input('password_confirm');
        if ($token === '') {
            flash('error', 'This reset request is invalid or has expired.');
            redirect('forgot-password');
        }
        $errors = PasswordPolicy::errors($password);
        if ($password !== $confirm) {
            $errors[] = 'Password and confirm password must match.';
        }
        $row = db()->first(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), date('Y-m-d H:i:s')]
        );
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('reset-password' . (input('token') ? ('?token=' . urlencode($token)) : ''));
        }
        if (!$row) {
            unset($_SESSION['password_reset_token']);
            flash('error', 'This reset request is invalid or has expired. Request a new one.');
            redirect('forgot-password');
        }
        db()->update('users', [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ], $row['user_id']);
        RegistrationService::bumpSession((int) $row['user_id']);
        db()->update('password_resets', ['used_at' => date('Y-m-d H:i:s')], $row['id']);
        unset($_SESSION['password_reset_token']);
        Audit::log('update', 'auth', (int) $row['user_id'], 'Password reset');
        flash('success', 'Your password has been updated. You can log in with your new password.');
        redirect('login');
    }

    public static function logout(): void
    {
        Auth::logout();
        flash('success', 'You have been signed out.');
        redirect('login');
    }

    private static function show(string $tab): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }
        view('auth/auth', ['authTab' => $tab]);
    }
}
