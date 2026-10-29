<?php

/**
 * Pending registration, admin approval/rejection, activation tokens, farm assignments.
 */
class RegistrationService
{
    /** Roles applicants may request (never admin). */
    public static function requestableRoles(): array
    {
        $all = Auth::roles();
        unset($all['admin']);
        return $all;
    }

    /** Trim + lowercase only. Does not rewrite spaces or strip characters. */
    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    /**
     * Username rules: 3–30 chars; letters, numbers, underscores, periods, hyphens; no spaces.
     * @return string|null Error message, or null when valid (empty allowed only when $required is false)
     */
    public static function usernameError(string $username, bool $required = true): ?string
    {
        $username = self::normalizeUsername($username);
        if ($username === '') {
            return $required ? 'Username is required.' : null;
        }
        if (preg_match('/\s/u', $username)) {
            return 'Usernames cannot contain spaces.';
        }
        $len = mb_strlen($username);
        if ($len < 3 || $len > 30) {
            return 'Username must be 3–30 characters: letters, numbers, underscores, periods, or hyphens only (no spaces).';
        }
        if (!preg_match('/^[a-z0-9._-]+$/u', $username)) {
            return 'Username can only contain letters, numbers, underscores, periods, and hyphens.';
        }
        return null;
    }

    /**
     * @return array{ok:bool,error?:string,user_id?:int,email_result?:array}
     */
    public static function submitRequest(array $input): array
    {
        $usernameRaw = (string) ($input['username'] ?? '');
        $username = self::normalizeUsername($usernameRaw);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';
        $requested = trim((string) ($input['role'] ?? ''));
        $allowed = array_keys(self::requestableRoles());

        $errors = [];
        if ($userErr = self::usernameError($usernameRaw, true)) {
            $errors[] = $userErr;
        }
        if ($email === '') {
            $errors[] = 'A valid email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($phoneRaw === '' || !valid_phone($phoneRaw)) {
            $errors[] = phone_validation_message();
        }
        if ($requested === '' || !in_array($requested, $allowed, true)) {
            $errors[] = 'Choose a valid account type.';
        }
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && db()->first('SELECT id FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with this email already exists.';
        }
        if ($username !== '' && self::usernameError($usernameRaw, true) === null
            && db()->first('SELECT id FROM users WHERE lower(username) = ?', [$username])) {
            $errors[] = 'That username is already taken.';
        }
        if ($errors) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }
        $phone = normalize_phone($phoneRaw);

        // Display name mirrors username (no separate first/last name on registration).
        $name = $username;
        // Unusable random hash until activation — never emailed
        $placeholder = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $userId = db()->insert('users', [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $placeholder,
            'role' => $requested, // provisional; admin may change on approval
            'requested_role' => $requested,
            'phone' => $phone,
            'farm_id' => null,
            'status' => 'pending',
            'must_change_password' => 1,
            'session_version' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('register', 'users', $userId, 'Registration request pending approval (role: ' . $requested . ')');

        self::notifyAdminsOfRequest($userId, $name, $email, $requested);

        $user = db()->find('users', $userId);
        $emailResult = AccountMail::registrationReceived($user);

        return [
            'ok' => true,
            'user_id' => $userId,
            'email_result' => $emailResult,
        ];
    }

    private static function notifyAdminsOfRequest(int $userId, string $name, string $email, string $role): void
    {
        $roleLabel = Auth::roles()[$role] ?? $role;
        $admins = db()->select("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $title = 'Registration request: ' . $name;
        $message = $name . ' (' . $email . ') requested access as ' . $roleLabel . '. Review and approve or reject.';
        $link = 'users/pending/' . $userId;
        if (!$admins) {
            // Fallback farm-wide admin-visible notification
            NotificationService::createForced([
                'farm_id' => null,
                'user_id' => null,
                'category' => 'registration',
                'title' => $title,
                'message' => $message,
                'severity' => 'info',
                'link' => $link,
            ]);
            return;
        }
        foreach ($admins as $admin) {
            NotificationService::createForced([
                'farm_id' => null,
                'user_id' => (int) $admin['id'],
                'category' => 'registration',
                'title' => $title,
                'message' => $message,
                'severity' => 'info',
                'link' => $link,
            ]);
        }
    }

    /**
     * @param list<int> $farmIds
     * @return array{ok:bool,error?:string,email_result?:array}
     */
    public static function approve(int $userId, string $finalRole, array $farmIds, bool $createOwnerFarm = false, ?string $notes = null): array
    {
        if (!Auth::isAdmin()) {
            return ['ok' => false, 'error' => 'Only administrators can approve registrations.'];
        }
        $user = db()->find('users', $userId);
        if (!$user) {
            return ['ok' => false, 'error' => 'Registration not found.'];
        }
        if (!in_array(($user['status'] ?? ''), ['pending', 'rejected', 'approved'], true)) {
            return ['ok' => false, 'error' => 'This account is not awaiting approval.'];
        }
        $roles = self::requestableRoles();
        if (!isset($roles[$finalRole])) {
            return ['ok' => false, 'error' => 'Invalid role. Administrator cannot be granted via approval.'];
        }

        $farmIds = array_values(array_unique(array_filter(array_map('intval', $farmIds))));
        if ($createOwnerFarm && $finalRole === 'owner' && $farmIds === []) {
            $farmId = db()->insert('farms', [
                'name' => $user['name'] . "'s Farm",
                'location' => '',
                'total_area' => 0,
                'status' => 'active',
                'join_code' => generate_join_code(),
                'owner_id' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $farmIds = [$farmId];
        }
        if ($finalRole !== 'admin' && $farmIds === []) {
            return ['ok' => false, 'error' => 'Assign at least one farm, or create a farm workspace for an owner.'];
        }
        foreach ($farmIds as $fid) {
            if (!Auth::canAccessFarm($fid) && !Auth::isAdmin()) {
                return ['ok' => false, 'error' => 'Invalid farm assignment.'];
            }
            if (!db()->find('farms', $fid)) {
                return ['ok' => false, 'error' => 'Farm #' . $fid . ' was not found.'];
            }
        }

        $token = bin2hex(random_bytes(32));
        $primary = $farmIds[0] ?? null;
        db()->update('users', [
            'role' => $finalRole,
            'status' => 'approved',
            'farm_id' => $primary,
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => Auth::id(),
            'rejection_reason' => null,
            'must_change_password' => 1,
            'activation_token_hash' => hash('sha256', $token),
            'activation_expires_at' => date('Y-m-d H:i:s', time() + 48 * 3600),
            'session_version' => (int) ($user['session_version'] ?? 1) + 1,
            'notes' => $notes,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $userId);

        self::syncFarmAssignments($userId, $farmIds, $primary);
        if ($finalRole === 'owner' && $primary) {
            db()->update('farms', ['owner_id' => $userId], $primary);
        }

        Audit::log('approve', 'users', $userId, 'Approved as ' . $finalRole . '; farms: ' . implode(',', $farmIds));

        $user = db()->find('users', $userId);
        $activationUrl = absolute_url('activate') . '?token=' . urlencode($token);
        $emailResult = AccountMail::accountApproved($user, $activationUrl);

        // Mark related admin notifications read
        try {
            db()->query(
                "UPDATE notifications SET is_read = 1 WHERE category = 'registration' AND link = ?",
                ['users/pending/' . $userId]
            );
        } catch (Throwable $e) {
        }

        return ['ok' => true, 'email_result' => $emailResult, 'activation_token' => $token];
    }

    /** @return array{ok:bool,error?:string,email_result?:array} */
    public static function reject(int $userId, string $reason = ''): array
    {
        if (!Auth::isAdmin()) {
            return ['ok' => false, 'error' => 'Only administrators can reject registrations.'];
        }
        $user = db()->find('users', $userId);
        if (!$user || ($user['status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => 'Pending registration not found.'];
        }
        db()->update('users', [
            'status' => 'rejected',
            'rejection_reason' => mb_substr(trim($reason), 0, 1000),
            'activation_token_hash' => null,
            'activation_expires_at' => null,
            'session_version' => (int) ($user['session_version'] ?? 1) + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $userId);
        Audit::log('reject', 'users', $userId, 'Registration rejected' . ($reason !== '' ? ': ' . $reason : ''));
        $user = db()->find('users', $userId);
        $emailResult = AccountMail::accountRejected($user, $reason);
        try {
            db()->query(
                "UPDATE notifications SET is_read = 1 WHERE category = 'registration' AND link = ?",
                ['users/pending/' . $userId]
            );
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'email_result' => $emailResult];
    }

    /** @param list<int> $farmIds */
    public static function syncFarmAssignments(int $userId, array $farmIds, ?int $primaryId = null): void
    {
        $farmIds = array_values(array_unique(array_filter(array_map('intval', $farmIds))));
        if (!table_exists('user_farms')) {
            return;
        }
        db()->query('DELETE FROM user_farms WHERE user_id = ?', [$userId]);
        if ($primaryId === null && $farmIds) {
            $primaryId = $farmIds[0];
        }
        foreach ($farmIds as $fid) {
            db()->insert('user_farms', [
                'user_id' => $userId,
                'farm_id' => $fid,
                'is_primary' => ($primaryId && $fid === $primaryId) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if ($primaryId) {
            db()->update('users', ['farm_id' => $primaryId], $userId);
        }
    }

    public static function farmIdsForUser(int $userId): array
    {
        if (!table_exists('user_farms')) {
            $u = db()->find('users', $userId);
            return !empty($u['farm_id']) ? [(int) $u['farm_id']] : [];
        }
        $rows = db()->select('SELECT farm_id FROM user_farms WHERE user_id = ? ORDER BY is_primary DESC, id ASC', [$userId]);
        return array_map(static fn ($r) => (int) $r['farm_id'], $rows);
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public static function activate(string $token, string $password, string $confirm): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'Invalid or expired activation link.'];
        }
        $errors = PasswordPolicy::errors($password);
        if ($password !== $confirm) {
            $errors[] = 'Password and confirm password must match.';
        }
        if ($errors) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }
        $user = db()->first(
            'SELECT * FROM users WHERE activation_token_hash = ? AND activation_expires_at > ? AND status = ?',
            [hash('sha256', $token), date('Y-m-d H:i:s'), 'approved']
        );
        if (!$user) {
            return ['ok' => false, 'error' => 'This activation link is invalid or has expired. Ask an administrator to resend approval.'];
        }
        db()->update('users', [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active',
            'must_change_password' => 0,
            'activation_token_hash' => null,
            'activation_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $user['id']);
        Audit::log('activate', 'users', (int) $user['id'], 'Account activated via email link');
        return ['ok' => true];
    }

    public static function bumpSession(int $userId): void
    {
        $user = db()->find('users', $userId);
        if (!$user) {
            return;
        }
        db()->update('users', [
            'session_version' => (int) ($user['session_version'] ?? 1) + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $userId);
    }
}
