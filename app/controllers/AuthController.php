<?php

class AuthController
{
    public static function landing(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
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
        if (!$identity || !$password) {
            flash('error', 'Email / username and password are required.');
            remember_old(['email' => $identity]);
            redirect('login');
        }
        if (!Auth::login($identity, $password, $remember)) {
            flash('error', 'Invalid credentials or inactive account.');
            remember_old(['email' => $identity]);
            redirect('login');
        }
        clear_old();
        flash('success', 'Welcome back to FarmWise.');
        redirect('dashboard');
    }

    public static function registerForm(): void
    {
        self::show('signup');
    }

    public static function register(): void
    {
        Csrf::verify();
        $first = trim((string) input('first_name'));
        $last = trim((string) input('last_name'));
        $email = strtolower(trim((string) input('email')));
        $phone = self::normalizePhone((string) input('phone'));
        $password = (string) input('password');
        $confirm = (string) input('password_confirm');
        $role = (string) input('role', 'owner');
        $allowed = ['owner', 'manager', 'worker'];
        if (!in_array($role, $allowed, true)) {
            $role = 'owner';
        }
        $joinCode = strtoupper(trim((string) input('join_code')));
        $errors = [];
        if ($first === '') {
            $errors[] = 'First name is required.';
        }
        if ($last === '') {
            $errors[] = 'Last name is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if (!self::validPhone($phone)) {
            $errors[] = 'Enter a valid phone number (9–15 digits, optional +).';
        }
        if ($pwErrors = PasswordPolicy::errors($password)) {
            $errors = array_merge($errors, $pwErrors);
        }
        if ($password !== $confirm) {
            $errors[] = 'Password and confirm password must match.';
        }
        if ($email && db()->first('SELECT id FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with this email already exists.';
        }
        $joinFarm = null;
        if ($role !== 'owner') {
            if ($joinCode === '') {
                $errors[] = 'Managers and workers must enter a farm join code from the farm owner.';
            } else {
                $joinFarm = db()->first("SELECT * FROM farms WHERE join_code = ? AND status = 'active'", [$joinCode]);
                if (!$joinFarm) {
                    $errors[] = 'That farm join code is invalid or the farm is not active.';
                }
            }
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            remember_old([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => input('phone'),
                'role' => $role,
                'join_code' => $joinCode,
            ]);
            redirect('register');
        }
        $name = trim($first . ' ' . $last);
        $farmId = null;
        if ($role === 'owner') {
            $farmId = db()->insert('farms', [
                'name' => $name . "'s Farm",
                'location' => '',
                'total_area' => 0,
                'status' => 'active',
                'join_code' => generate_join_code(),
            ]);
        } else {
            $farmId = (int) $joinFarm['id'];
        }
        $userId = db()->insert('users', [
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'phone' => $phone,
            'farm_id' => $farmId,
            'status' => 'active',
        ]);
        if ($farmId && $role === 'owner') {
            db()->update('farms', ['owner_id' => $userId], $farmId);
        }
        Audit::log('create', 'users', $userId, 'Self-registration');
        clear_old();
        Auth::login($email, $password);
        if ($role === 'owner') {
            flash('success', 'Account created. Your farm workspace is ready. Share the farm join code from the Farms page so managers and workers can join.');
        } else {
            flash('success', 'Account created and assigned to ' . ($joinFarm['name'] ?? 'the selected farm') . '.');
        }
        redirect('dashboard');
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
            $subject = 'Reset your FarmWise password';
            $body = "We received a request to reset your FarmWise password.\n\n"
                . "Open this link to choose a new password:\n{$link}\n\n"
                . "This link expires in 1 hour. If you did not request this, you can ignore this message.";
            MessageDispatcher::queue([
                'farm_id' => $user['farm_id'] ?: null,
                'user_id' => $user['id'],
                'channel' => 'email',
                'recipient' => $user['email'],
                'subject' => $subject,
                'body' => $body,
            ]);
            MessageDispatcher::flush();
            $_SESSION['password_reset_token'] = $raw;
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
        db()->update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], $row['user_id']);
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

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?? '';
    }

    private static function validPhone(string $phone): bool
    {
        return (bool) preg_match('/^\+?\d{9,15}$/', $phone);
    }
}
