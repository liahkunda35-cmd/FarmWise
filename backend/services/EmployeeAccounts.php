<?php

/**
 * Create / reset FarmWise login accounts linked to employee records.
 * Temporary passwords are shown once via flash session — never stored in plaintext.
 */
class EmployeeAccounts
{
    public static function generateTemporaryPassword(int $length = 12): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%&*';
        $all = $upper . $lower . $digits . $special;
        $password = $upper[random_int(0, strlen($upper) - 1)]
            . $lower[random_int(0, strlen($lower) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)]
            . $special[random_int(0, strlen($special) - 1)];
        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        return str_shuffle($password);
    }

    public static function mapSystemRole(?string $roleTitle): string
    {
        $t = strtolower(trim((string) $roleTitle));
        if (str_contains($t, 'manager') || str_contains($t, 'supervisor')) {
            return 'manager';
        }
        if (str_contains($t, 'agronom')) {
            return 'agronomist';
        }
        if (str_contains($t, 'account')) {
            return 'accountant';
        }
        return 'worker';
    }

    /**
     * @return array{ok:bool,user_id?:int,username?:string,email?:string,temp_password?:string,error?:string}
     */
    public static function createForEmployee(array $employee, array $opts = []): array
    {
        $name = trim((string) ($employee['name'] ?? ''));
        $email = strtolower(trim((string) ($opts['email'] ?? $employee['email'] ?? '')));
        $username = strtolower(trim((string) ($opts['username'] ?? '')));
        $phone = trim((string) ($employee['phone'] ?? ''));
        $farmId = !empty($employee['farm_id']) ? (int) $employee['farm_id'] : null;

        if ($name === '') {
            return ['ok' => false, 'error' => 'Employee name is required to create a login.'];
        }
        if ($email === '' && $username === '') {
            return ['ok' => false, 'error' => 'Provide an email or username for the login account.'];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address for the login account.'];
        }
        if ($username === '' && $email !== '') {
            $username = self::usernameFromEmail($email);
        }
        if ($username === '') {
            $username = self::usernameFromName($name);
        }
        $username = self::uniqueUsername($username);

        if ($email !== '') {
            $exists = db()->first('SELECT id FROM users WHERE lower(email) = ?', [$email]);
            if ($exists) {
                return ['ok' => false, 'error' => 'That email is already used by another login account.'];
            }
        } else {
            // Users.email is NOT NULL — synthesize a private placeholder never used for mail
            $email = $username . '@employees.farmwise.local';
            $n = 1;
            while (db()->first('SELECT id FROM users WHERE lower(email) = ?', [$email])) {
                $email = $username . $n . '@employees.farmwise.local';
                $n++;
            }
        }

        $temp = (string) ($opts['temp_password'] ?? '');
        if ($temp === '') {
            $temp = self::generateTemporaryPassword();
        }
        if ($errs = PasswordPolicy::errors($temp)) {
            return ['ok' => false, 'error' => implode(' ', $errs)];
        }

        $role = self::mapSystemRole($employee['role_title'] ?? null);
        if (!Auth::isKnownRole($role)) {
            $role = 'worker';
        }

        $userId = db()->insert('users', [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => password_hash($temp, PASSWORD_DEFAULT),
            'role' => $role,
            'phone' => $phone !== '' ? $phone : null,
            'farm_id' => $farmId,
            'status' => 'active',
            'must_change_password' => 1,
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => Auth::id(),
            'session_version' => 1,
            'notes' => 'Created from Employees & Workers registration.',
        ]);

        if ($farmId) {
            try {
                RegistrationService::syncFarmAssignments($userId, [$farmId], $farmId);
            } catch (Throwable $e) {
                // Non-fatal: employee row can still link
            }
        }

        Audit::log('create', 'users', $userId, 'Employee login created for EMP-' . ($employee['id'] ?? '?'));

        return [
            'ok' => true,
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'temp_password' => $temp,
        ];
    }

    /**
     * @return array{ok:bool,temp_password?:string,username?:string,email?:string,error?:string}
     */
    public static function resetTemporaryPassword(int $userId): array
    {
        $user = db()->find('users', $userId);
        if (!$user) {
            return ['ok' => false, 'error' => 'Login account not found.'];
        }
        $temp = self::generateTemporaryPassword();
        db()->update('users', [
            'password' => password_hash($temp, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'session_version' => (int) ($user['session_version'] ?? 1) + 1,
        ], $userId);
        Audit::log('update', 'users', $userId, 'Temporary password re-issued for employee login');
        return [
            'ok' => true,
            'temp_password' => $temp,
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ];
    }

    public static function usernameFromEmail(string $email): string
    {
        $local = explode('@', $email)[0] ?? 'user';
        $local = preg_replace('/[^a-z0-9._-]/', '', strtolower($local)) ?: 'user';
        return substr($local, 0, 30);
    }

    public static function usernameFromName(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '.', strtolower(trim($name))) ?: 'employee';
        $base = trim($base, '.');
        return substr($base, 0, 24);
    }

    public static function uniqueUsername(string $base): string
    {
        $candidate = substr($base, 0, 30);
        $n = 0;
        while (db()->first('SELECT id FROM users WHERE lower(username) = ?', [strtolower($candidate)])) {
            $n++;
            $suffix = (string) $n;
            $candidate = substr($base, 0, 30 - strlen($suffix)) . $suffix;
        }
        return $candidate;
    }
}
