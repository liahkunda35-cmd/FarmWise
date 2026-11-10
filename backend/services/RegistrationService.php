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

    public static function canReview(array $user): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        if (Auth::role() !== 'owner') {
            return false;
        }
        $farmId = (int) ($user['farm_id'] ?? 0);
        if ($farmId <= 0 || !Auth::canAccessFarm($farmId)) {
            return false;
        }
        $farm = db()->find('farms', $farmId);
        return $farm && (int) ($farm['owner_id'] ?? 0) === (int) Auth::id();
    }

    private static function usesFarmRegistration(array $input): bool
    {
        foreach (['password', 'farm_code', 'farm_name', 'name'] as $key) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Owner creates a farm and receives its code. Other farm roles join with that code and stay pending.
     * @return array{ok:bool,error?:string,user_id?:int,email_result?:array,status?:string,farm_code?:string,farm_name?:string,farm_id?:int}
     */
    private static function registerWithFarmCode(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $usernameRaw = (string) ($input['username'] ?? '');
        $username = self::normalizeUsername($usernameRaw);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $requested = trim((string) ($input['role'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        $farmCode = normalize_farm_code((string) ($input['farm_code'] ?? ''));
        $farmName = trim((string) ($input['farm_name'] ?? ''));
        $farmLocation = trim((string) ($input['farm_location'] ?? ''));
        $allowed = array_keys(self::requestableRoles());
        $isOwner = $requested === 'owner';

        $errors = [];
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors[] = 'Full name is required.';
        }
        if ($userErr = self::usernameError($usernameRaw, true)) {
            $errors[] = $userErr;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($phoneRaw === '' || !valid_phone($phoneRaw)) {
            $errors[] = phone_validation_message();
        }
        if ($requested === '' || !in_array($requested, $allowed, true)) {
            $errors[] = 'Choose a valid account type.';
        }
        $errors = array_merge($errors, PasswordPolicy::errors($password));
        if ($password !== $confirm) {
            $errors[] = 'Password and confirm password must match.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && db()->first('SELECT id FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with this email already exists.';
        }
        if ($username !== '' && self::usernameError($usernameRaw, true) === null
            && db()->first('SELECT id FROM users WHERE lower(username) = ?', [$username])) {
            $errors[] = 'That username is already taken.';
        }

        $farm = null;
        if ($isOwner) {
            if ($farmName === '' || mb_strlen($farmName) > 160) {
                $errors[] = 'Farm name is required.';
            }
        } else {
            if ($farmCode === '') {
                $errors[] = 'Farm Code is required.';
            } else {
                $farm = find_farm_by_code($farmCode);
                if (!$farm || ($farm['status'] ?? '') !== 'active') {
                    $errors[] = 'The Farm Code you entered is invalid. Please check the code provided by your Farm Owner.';
                }
            }
        }
        if ($errors) {
            return ['ok' => false, 'error' => implode(' ', array_values(array_unique($errors)))];
        }

        $phone = normalize_phone($phoneRaw);
        $now = date('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->beginTransaction();
        try {
            if ($isOwner) {
                $userId = db()->insert('users', [
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'password' => $hash,
                    'role' => 'owner',
                    'requested_role' => 'owner',
                    'phone' => $phone,
                    'farm_id' => null,
                    'status' => 'active',
                    'must_change_password' => 0,
                    'session_version' => 1,
                    'created_at' => $now,
                ]);
                $code = generate_join_code($farmName);
                $farmId = db()->insert('farms', [
                    'name' => $farmName,
                    'location' => $farmLocation,
                    'total_area' => 0,
                    'status' => 'active',
                    'join_code' => $code,
                    'owner_id' => $userId,
                    'created_at' => $now,
                ]);
                db()->update('users', ['farm_id' => $farmId], $userId);
                self::syncFarmAssignments($userId, [$farmId], $farmId);
                $farm = db()->find('farms', $farmId);
            } else {
                $farmId = (int) $farm['id'];
                $userId = db()->insert('users', [
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'password' => $hash,
                    'role' => $requested,
                    'requested_role' => $requested,
                    'phone' => $phone,
                    'farm_id' => $farmId,
                    'status' => 'pending',
                    'must_change_password' => 0,
                    'session_version' => 1,
                    'created_at' => $now,
                ]);
                self::syncFarmAssignments($userId, [$farmId], $farmId);
                $code = (string) ($farm['join_code'] ?? '');
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            log_internal_error('registration', $e);
            return ['ok' => false, 'error' => 'We couldn\'t complete your request. Please try again later.'];
        }

        $user = db()->find('users', $userId);
        Audit::log(
            'register',
            'users',
            $userId,
            $isOwner
                ? 'Farm owner registered farm ' . ($farm['name'] ?? '')
                : 'Registration request pending approval for farm ' . ($farm['name'] ?? '')
        );
        try {
            self::notifyAdminsOfRequest($userId, $name, $email, $requested, $farm);
        } catch (Throwable $e) {
            log_internal_error('registration-notify', $e);
        }
        try {
            $emailResult = AccountMail::registrationReceived($user, $farm);
        } catch (Throwable $e) {
            log_internal_error('registration-mail', $e);
            $emailResult = ['ok' => false, 'error' => 'Email could not be queued.'];
        }

        return [
            'ok' => true,
            'user_id' => $userId,
            'status' => (string) ($user['status'] ?? ''),
            'farm_code' => (string) ($farm['join_code'] ?? $code ?? ''),
            'farm_name' => (string) ($farm['name'] ?? ''),
            'farm_id' => (int) ($farm['id'] ?? 0),
            'email_result' => $emailResult,
        ];
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

        $name = trim((string) ($input['name'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors[] = 'Full name is required.';
        }
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
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        foreach (PasswordPolicy::errors($password) as $passwordError) {
            $errors[] = $passwordError;
        }
        if ($password !== $confirm) {
            $errors[] = 'Password and confirm password must match.';
        }
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && db()->first('SELECT id FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with this email already exists.';
        }
        if ($username !== '' && self::usernameError($usernameRaw, true) === null
            && db()->first('SELECT id FROM users WHERE lower(username) = ?', [$username])) {
            $errors[] = 'That username is already taken.';
        }
        $isOwner = $requested === 'owner';
        $farm = null;
        if ($requested !== '' && !$isOwner) {
            $farmCode = normalize_farm_code((string) ($input['farm_code'] ?? ''));
            if ($farmCode === '') {
                $errors[] = 'Farm Code is required.';
            } else {
                $farm = find_farm_by_code($farmCode);
                if (!$farm || ($farm['status'] ?? '') !== 'active') {
                    $errors[] = 'Invalid Farm Code. Please check the code provided by your farm owner.';
                }
            }
        }
        if ($errors) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }
        $phone = normalize_phone($phoneRaw);
        $farmId = $farm ? (int) $farm['id'] : null;

        $userId = db()->insert('users', [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $requested,
            'requested_role' => $requested,
            'phone' => $phone,
            'farm_id' => $farmId,
            'status' => $isOwner ? 'pending' : 'active',
            'must_change_password' => 0,
            'session_version' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($farmId) {
            self::syncFarmAssignments($userId, [$farmId], $farmId);
        }

        if ($isOwner) {
            Audit::log('register', 'users', $userId, 'Farm owner registration pending approval');
            self::notifyAdminsOfRequest($userId, $name, $email, $requested, null);
        } else {
            Audit::log('register', 'users', $userId, 'Joined farm ' . ($farm['name'] ?? '') . ' with a farm code');
            $ownerId = (int) ($farm['owner_id'] ?? 0);
            if ($ownerId > 0) {
                NotificationService::createForced([
                    'farm_id' => $farmId,
                    'user_id' => $ownerId,
                    'category' => 'registration',
                    'title' => $name . ' joined ' . ($farm['name'] ?? 'the farm'),
                    'message' => $name . ' (' . $email . ') registered as ' . (Auth::roles()[$requested] ?? $requested) . '.',
                    'severity' => 'info',
                    'link' => 'users',
                ]);
            }
        }

        $user = db()->find('users', $userId);
        // Only farm owners get account emails. Staff join with a Farm Code and sign in
        // with the password they chose — FarmWise never emails passwords.
        $emailResult = ['ok' => true, 'skipped' => true, 'channel' => 'none'];
        if ($isOwner) {
            $emailResult = self::safeMail(static fn (): array => AccountMail::registrationReceived($user, $farm));
        }

        return [
            'ok' => true,
            'user_id' => $userId,
            'status' => (string) ($user['status'] ?? ''),
            'farm_name' => (string) ($farm['name'] ?? ''),
            'farm_id' => (int) ($farmId ?? 0),
            'email_result' => $emailResult,
        ];
    }

    private static function notifyAdminsOfRequest(int $userId, string $name, string $email, string $role, ?array $farm): void
    {
        $roleLabel = Auth::roles()[$role] ?? $role;
        $admins = db()->select("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $farmName = trim((string) ($farm['name'] ?? ''));
        $title = 'Registration request: ' . $name;
        $message = $name . ' (' . $email . ') requested access as ' . $roleLabel
            . ($farmName !== '' ? ' for ' . $farmName : '')
            . '. Review and approve or reject.';
        $link = 'users/pending/' . $userId;
        $farmId = (int) ($farm['id'] ?? 0) ?: null;
        $ownerId = (int) ($farm['owner_id'] ?? 0);
        if ($ownerId > 0 && $role !== 'owner') {
            $owner = db()->find('users', $ownerId);
            if ($owner && ($owner['status'] ?? '') === 'active' && ($owner['role'] ?? '') === 'owner') {
                NotificationService::createForced([
                    'farm_id' => $farmId,
                    'user_id' => $ownerId,
                    'category' => 'registration',
                    'title' => $title,
                    'message' => $message,
                    'severity' => 'info',
                    'link' => $link,
                ]);
            }
        }
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
        $user = db()->find('users', $userId);
        if (!$user || !self::canReview($user)) {
            return ['ok' => false, 'error' => 'You cannot approve this registration.'];
        }
        if (!in_array(($user['status'] ?? ''), ['pending', 'rejected', 'approved'], true)) {
            return ['ok' => false, 'error' => 'This account is not awaiting approval.'];
        }
        if (!Auth::isAdmin()) {
            $finalRole = (string) ($user['requested_role'] ?: $user['role']);
            $farmIds = [(int) ($user['farm_id'] ?? 0)];
            $createOwnerFarm = false;
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
                'join_code' => generate_join_code($user['name'] . "'s Farm"),
                'owner_id' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $farmIds = [$farmId];
        }
        if ($finalRole !== 'admin' && $finalRole !== 'owner' && $farmIds === []) {
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

        $primary = $farmIds[0] ?? null;
        $chosenPassword = (int) ($user['must_change_password'] ?? 1) === 0;
        $token = $chosenPassword ? '' : bin2hex(random_bytes(32));
        db()->update('users', [
            'role' => $finalRole,
            'status' => $chosenPassword ? 'active' : 'approved',
            'farm_id' => $primary,
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => Auth::id(),
            'rejection_reason' => null,
            'must_change_password' => $chosenPassword ? 0 : 1,
            'activation_token_hash' => $chosenPassword ? null : hash('sha256', $token),
            'activation_expires_at' => $chosenPassword ? null : date('Y-m-d H:i:s', time() + 48 * 3600),
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
        $farm = $primary ? db()->find('farms', $primary) : null;
        // Credential / approval emails go to farm owners only. Other roles use Farm Code.
        $emailResult = ['ok' => true, 'skipped' => true, 'channel' => 'none'];
        if ($finalRole === 'owner') {
            if ($chosenPassword) {
                $emailResult = self::safeMail(static fn (): array => AccountMail::accountApprovedReady($user, $farm));
            } else {
                $activationUrl = absolute_url('activate') . '?token=' . urlencode($token);
                $emailResult = self::safeMail(static fn (): array => AccountMail::accountApproved($user, $activationUrl));
            }
        }

        // Mark related admin notifications read
        try {
            db()->query(
                "UPDATE notifications SET is_read = 1 WHERE category = 'registration' AND link = ?",
                ['users/pending/' . $userId]
            );
        } catch (Throwable $e) {
        }

        return [
            'ok' => true,
            'email_result' => $emailResult,
            'activation_token' => $token,
            'activated' => $chosenPassword,
        ];
    }

    /**
     * Resend the owner approval / ready-to-sign-in email (never includes a password).
     * @return array{ok:bool,error?:string,email_result?:array}
     */
    public static function resendOwnerApprovalEmail(int $userId): array
    {
        $user = db()->find('users', $userId);
        if (!$user || !self::canReview($user)) {
            return ['ok' => false, 'error' => 'You cannot email this account.'];
        }
        if (($user['role'] ?? '') !== 'owner' && ($user['requested_role'] ?? '') !== 'owner') {
            return ['ok' => false, 'error' => 'Only farm owners receive account emails. Other users sign in with a Farm Code.'];
        }
        $status = (string) ($user['status'] ?? '');
        if (!in_array($status, ['active', 'approved'], true)) {
            return ['ok' => false, 'error' => 'Approve the account before resending the email.'];
        }
        $farm = !empty($user['farm_id']) ? db()->find('farms', $user['farm_id']) : null;
        if ($status === 'active' || (int) ($user['must_change_password'] ?? 0) === 0) {
            $emailResult = self::safeMail(static fn (): array => AccountMail::accountApprovedReady($user, $farm));
        } else {
            $token = bin2hex(random_bytes(32));
            db()->update('users', [
                'activation_token_hash' => hash('sha256', $token),
                'activation_expires_at' => date('Y-m-d H:i:s', time() + 48 * 3600),
                'updated_at' => date('Y-m-d H:i:s'),
            ], $userId);
            $activationUrl = absolute_url('activate') . '?token=' . urlencode($token);
            $emailResult = self::safeMail(static fn (): array => AccountMail::accountApproved($user, $activationUrl));
        }
        if (empty($emailResult['ok'])) {
            return ['ok' => false, 'error' => (string) ($emailResult['error'] ?? 'Email could not be sent.'), 'email_result' => $emailResult];
        }
        Audit::log('email', 'users', $userId, 'Resent owner approval email');
        return ['ok' => true, 'email_result' => $emailResult];
    }

    /** @return array{ok:bool,error?:string,email_result?:array} */
    public static function reject(int $userId, string $reason = ''): array
    {
        $user = db()->find('users', $userId);
        if (!$user || !self::canReview($user) || ($user['status'] ?? '') !== 'pending') {
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
        $emailResult = self::safeMail(static fn (): array => AccountMail::accountRejected($user, $reason));
        try {
            db()->query(
                "UPDATE notifications SET is_read = 1 WHERE category = 'registration' AND link = ?",
                ['users/pending/' . $userId]
            );
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'email_result' => $emailResult];
    }

    /** @return array{ok:bool,error?:string,channel?:string} */
    private static function safeMail(callable $send): array
    {
        try {
            $result = $send();
            return is_array($result) ? $result : ['ok' => false, 'error' => 'Email could not be sent.'];
        } catch (Throwable $e) {
            log_internal_error('account_mail', $e);
            return ['ok' => false, 'error' => 'Email could not be sent.'];
        }
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
