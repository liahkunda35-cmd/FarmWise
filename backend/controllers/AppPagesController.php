<?php

class AppPagesController
{
    public static function weather(): void
    {
        Auth::authorize('weather', 'view');
        $farm = self::currentFarm();
        $forecast = null;
        if ($farm) {
            try {
                $forecast = WeatherService::fetchForFarm($farm);
            } catch (Throwable $e) {
                $forecast = [
                    'current' => null,
                    'daily' => null,
                    'meta' => [
                        'ok' => false,
                        'error' => 'api_error',
                        'message' => 'Weather could not be loaded right now. Try again shortly.',
                    ],
                ];
            }
        }
        $history = $farm
            ? db()->select('SELECT * FROM weather_logs WHERE farm_id = ? ORDER BY log_date DESC LIMIT 14', [$farm['id']])
            : [];
        $tips = ($forecast && $farm && !empty($forecast['current'])) ? WeatherService::recommendations($forecast, $farm) : [];
        view('layouts/app', [
            'title' => 'Weather & Environment',
            'content' => render('weather', compact('farm', 'forecast', 'history', 'tips')),
        ]);
    }

    public static function notifications(): void
    {
        Auth::authorize('notifications', 'view');
        NotificationService::syncAlerts();
        [$sql, $params] = self::notificationScope();
        $q = input('q');
        if ($q) {
            $sql .= ' AND (title LIKE ? OR message LIKE ? OR category LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        $filter = input('filter', 'all');
        if ($filter === 'unread') {
            $sql .= ' AND is_read = 0';
        } elseif ($filter === 'read') {
            $sql .= ' AND is_read = 1';
        }
        $sql .= ' ORDER BY id DESC LIMIT 80';
        $rows = db()->select($sql, $params);
        view('layouts/app', [
            'title' => 'Notifications & Alerts',
            'content' => render('notifications', compact('rows', 'filter')),
        ]);
    }

    public static function markRead($id): void
    {
        Auth::authorize('notifications', 'view');
        Csrf::verify();
        $row = self::ownedNotification($id);
        db()->update('notifications', ['is_read' => 1], $row['id']);
        redirect('notifications' . (input('filter') ? ('?filter=' . urlencode((string) input('filter'))) : ''));
    }

    public static function markAllRead(): void
    {
        Auth::authorize('notifications', 'view');
        Csrf::verify();
        [$where, $params] = self::notificationWhere();
        db()->query(
            'UPDATE notifications SET is_read = 1, updated_at = ? WHERE is_read = 0 AND ' . $where,
            array_merge([date('Y-m-d H:i:s')], $params)
        );
        flash('success', 'All notifications marked as read.');
        redirect('notifications');
    }

    private static function notificationWhere(): array
    {
        $sql = '1=1';
        $params = [];
        if (Auth::isAdmin()) {
            $sql .= ' AND (user_id IS NULL OR user_id = ?)';
            $params[] = Auth::id();
        } elseif (Auth::farmId()) {
            $sql .= ' AND (user_id = ? OR user_id IS NULL) AND (farm_id = ? OR farm_id IS NULL)';
            $params[] = Auth::id();
            $params[] = Auth::farmId();
        } else {
            $sql .= ' AND user_id = ? AND farm_id IS NULL';
            $params[] = Auth::id();
        }
        return [$sql, $params];
    }

    private static function notificationScope(): array
    {
        [$where, $params] = self::notificationWhere();
        return ['SELECT * FROM notifications WHERE ' . $where, $params];
    }

    private static function ownedNotification($id): array
    {
        $row = db()->find('notifications', $id);
        if (!$row) {
            abort(404, 'Notification not found.');
        }
        if (!Auth::isAdmin()) {
            if (!empty($row['user_id']) && (int) $row['user_id'] !== (int) Auth::id()) {
                abort(403, 'You can only update your own notifications.');
            }
            if (!empty($row['farm_id']) && !Auth::canAccessFarm((int) $row['farm_id'])) {
                abort(403, 'You can only update your own notifications.');
            }
        }
        return $row;
    }

    public static function reports(): void
    {
        Auth::authorize('reports', 'view');
        $types = ReportService::visibleTypes();
        $type = (string) input('type', array_key_first($types) ?: 'performance');
        if (!isset($types[$type])) {
            abort(403, 'You do not have permission to access this module.');
        }
        $filters = [
            'date_from' => input('date_from'),
            'date_to' => input('date_to'),
            'status' => input('status'),
            'farm_id' => input('farm_id'),
        ];
        if ($filters['date_from'] && !is_valid_date($filters['date_from'])) {
            flash('error', 'Start date must be a valid date.');
            redirect('reports?type=' . urlencode($type));
        }
        if ($filters['date_to'] && !is_valid_date($filters['date_to'])) {
            flash('error', 'End date must be a valid date.');
            redirect('reports?type=' . urlencode($type));
        }
        if ($filters['date_from'] && $filters['date_to'] && $filters['date_from'] > $filters['date_to']) {
            flash('error', 'Start date cannot be after end date.');
            redirect('reports?type=' . urlencode($type));
        }
        $data = ReportService::build($type, $filters);
        if (input('export') === 'csv') {
            csv_download($type . '-report.csv', $data['headers'], $data['rows']);
        }
        if (input('export') === 'pdf') {
            $period = trim(($filters['date_from'] ?: 'start') . ' to ' . ($filters['date_to'] ?: 'today'));
            $bytes = Pdf::table($data['title'] ?? ReportService::types()[$type], $data['headers'], $data['rows'], ($data['summary'] ?? '') . ' · Period: ' . $period);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $type . '-report.pdf"');
            echo $bytes;
            exit;
        }
        view('layouts/app', [
            'title' => 'Reports & Analytics',
            'content' => render('reports', [
                'type' => $type,
                'report' => $data,
                'types' => $types,
                'filters' => $filters,
                'farms' => farm_options(),
            ]),
        ]);
    }

    public static function forecasts(): void
    {
        Auth::authorize('yield_forecasts', 'view');
        [$farmClause, $farmParams] = active_farm_constraint('farm_id');
        $scope = ['sql' => 'WHERE ' . $farmClause, 'params' => $farmParams];
        $forecasts = db()->select('SELECT y.*, p.expected_harvest, p.field_id FROM yield_forecasts y LEFT JOIN plantings p ON p.id = y.planting_id ' . str_replace('farm_id', 'y.farm_id', $scope['sql']) . ' ORDER BY y.id DESC', $scope['params']);
        $comparisons = [];
        foreach ($forecasts as $f) {
            $actual = db()->first('SELECT COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(losses),0) AS losses FROM harvests WHERE planting_id = ?', [$f['planting_id']]);
            $comparisons[] = [
                'forecast' => $f,
                'actual' => $actual['qty'] ?? 0,
                'losses' => $actual['losses'] ?? 0,
            ];
        }
        $plantings = db()->select('SELECT p.*, c.name AS crop_name, f.name AS field_name, f.size_hectares FROM plantings p LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id LEFT JOIN fields f ON f.id = p.field_id ' . str_replace('farm_id', 'p.farm_id', $scope['sql']), $scope['params']);
        view('layouts/app', [
            'title' => 'Yield & Production Forecasts',
            'content' => render('forecasts', compact('comparisons', 'plantings')),
        ]);
    }

    public static function generateForecast(): void
    {
        Auth::authorize('yield_forecasts', 'create');
        Csrf::verify();
        $planting = db()->find('plantings', input('planting_id'));
        if (!$planting || !Auth::canAccessRecord($planting)) {
            abort(404, 'Planting not found.');
        }
        $variety = db()->find('crop_varieties', $planting['crop_variety_id']);
        $field = db()->find('fields', $planting['field_id']);
        $ha = (float) ($field['size_hectares'] ?? 1);
        $typical = (float) ($variety['typical_yield_per_ha'] ?? 4000);
        $scouts = db()->select('SELECT severity FROM pest_scouting WHERE planting_id = ? OR field_id = ?', [$planting['id'], $planting['field_id']]);
        $loss = 5;
        foreach ($scouts as $s) {
            $loss += ['low' => 2, 'medium' => 6, 'high' => 12, 'critical' => 20][$s['severity']] ?? 4;
        }
        $loss = min(40, $loss);
        $price = (float) (db()->value('SELECT price_per_kg FROM market_prices WHERE crop_name LIKE ? ORDER BY price_date DESC', ['%' . ($variety['name'] ?? '') . '%']) ?: 8);
        $expected = round($typical * $ha * (1 - $loss / 100), 2);
        db()->insert('yield_forecasts', [
            'farm_id' => $planting['farm_id'],
            'planting_id' => $planting['id'],
            'forecast_date' => date('Y-m-d'),
            'expected_yield' => $expected,
            'expected_yield_per_ha' => $ha ? round($expected / $ha, 2) : $expected,
            'expected_price' => $price,
            'expected_revenue' => round($expected * $price, 2),
            'loss_factor' => $loss,
            'factors' => 'Typical variety yield, field size, pest pressure and current market price.',
            'best_selling_period' => date('M Y', strtotime($planting['expected_harvest'] ?: '+2 months')),
            'market_risk' => $price < 6 ? 'high' : ($price < 10 ? 'medium' : 'low'),
            'notes' => 'Generated from historical farm data and current market prices.',
        ]);
        flash('success', 'Yield forecast generated from field, pest and market data.');
        redirect('forecasts');
    }

    public static function marketInsights(): void
    {
        Auth::authorize('market_insights', 'view');
        if (Auth::isAdmin() && !Auth::farmId()) {
            $where = '1=1';
            $params = [];
        } elseif (Auth::farmId()) {
            $where = '(farm_id = ? OR farm_id IS NULL)';
            $params = [Auth::farmId()];
        } else {
            $where = '1=0';
            $params = [];
        }
        $prices = db()->select(
            "SELECT * FROM market_prices WHERE {$where} ORDER BY price_date DESC, id DESC LIMIT 40",
            $params
        );
        $crops = [];
        foreach (db()->select(
            "SELECT crop_name, AVG(price_per_kg) AS avg_price, MAX(price_per_kg) AS high_price, MIN(price_per_kg) AS low_price FROM market_prices WHERE {$where} GROUP BY crop_name",
            $params
        ) as $row) {
            $latestParams = $params;
            $latestSql = "SELECT * FROM market_prices WHERE crop_name = ? AND {$where} ORDER BY price_date DESC";
            array_unshift($latestParams, $row['crop_name']);
            $latest = db()->first($latestSql, $latestParams);
            $crops[] = array_merge($row, ['latest' => $latest]);
        }
        view('layouts/app', [
            'title' => 'Market Insights',
            'content' => render('market_insights', compact('prices', 'crops')),
        ]);
    }

    public static function users(): void
    {
        Auth::authorize('users', 'view');
        $sql = 'SELECT u.*, f.name AS farm_name FROM users u LEFT JOIN farms f ON f.id = u.farm_id WHERE 1=1';
        $params = [];
        if (!Auth::isAdmin()) {
            $sql .= ' AND u.role != ? AND u.status NOT IN (?, ?)';
            $params[] = 'admin';
            $params[] = 'pending';
            $params[] = 'rejected';
            if (Auth::farmId()) {
                $sql .= ' AND u.farm_id = ?';
                $params[] = Auth::farmId();
            } else {
                $sql .= ' AND 1=0';
            }
        } elseif (Auth::farmId()) {
            $sql .= ' AND (u.farm_id = ? OR u.farm_id IS NULL OR u.status = ?)';
            $params[] = Auth::farmId();
            $params[] = 'pending';
        }
        $rows = db()->select($sql . ' ORDER BY CASE u.status WHEN \'pending\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END, u.id DESC', $params);
        $farms = farm_options();
        $pendingCount = Auth::isAdmin()
            ? (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'pending'")
            : 0;
        $directory = fe_user_directory_summary();
        $perms = require dirname(__DIR__) . '/config/permissions.php';
        $moduleTitles = [];
        foreach (all_modules() as $key => $moduleDef) {
            $moduleTitles[$key] = $moduleDef['title'] ?? $key;
        }
        view('layouts/app', [
            'title' => 'User Management',
            'content' => render('admin/users', compact('rows', 'farms', 'pendingCount', 'directory', 'perms', 'moduleTitles')),
        ]);
    }

    public static function pendingRegistration($id): void
    {
        Auth::authorize('users', 'edit');
        if (!Auth::isAdmin()) {
            abort(403, 'Only administrators can review registration requests.');
        }
        $pendingUser = db()->find('users', $id);
        if (!$pendingUser || !in_array($pendingUser['status'], ['pending', 'approved', 'rejected'], true)) {
            flash('error', 'Registration request not found.');
            redirect('users');
        }
        // Mark related notifications read when opened
        try {
            db()->query(
                "UPDATE notifications SET is_read = 1 WHERE category = 'registration' AND link = ? AND (user_id = ? OR user_id IS NULL)",
                ['users/pending/' . $id, Auth::id()]
            );
        } catch (Throwable $e) {
        }
        $farms = farm_options();
        $assigned = RegistrationService::farmIdsForUser((int) $id);
        view('layouts/app', [
            'title' => 'Registration request',
            'content' => render('admin/registration_review', compact('pendingUser', 'farms', 'assigned')),
        ]);
    }

    public static function approveRegistration($id): void
    {
        Auth::authorize('users', 'edit');
        Csrf::verify();
        if (!Auth::isAdmin()) {
            abort(403, 'Only administrators can approve registrations.');
        }
        $role = (string) input('role');
        $farmIds = input('farm_ids');
        if (!is_array($farmIds)) {
            $farmIds = input('farm_id') ? [input('farm_id')] : [];
        }
        $createFarm = (string) input('create_owner_farm') === '1';
        $result = RegistrationService::approve((int) $id, $role, $farmIds, $createFarm, (string) input('notes'));
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Approval failed.'));
            redirect('users/pending/' . $id);
        }
        $emailNote = !empty($result['email_result']['ok'])
            ? 'Activation email queued via ' . (($result['email_result']['channel'] ?? 'email')) . '.'
            : ('Activation email was not sent: ' . ($result['email_result']['error'] ?? 'email not configured') . '.');
        flash('success', 'Account approved. ' . $emailNote);
        redirect('users');
    }

    public static function rejectRegistration($id): void
    {
        Auth::authorize('users', 'edit');
        Csrf::verify();
        if (!Auth::isAdmin()) {
            abort(403, 'Only administrators can reject registrations.');
        }
        $result = RegistrationService::reject((int) $id, (string) input('rejection_reason'));
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Rejection failed.'));
            redirect('users/pending/' . $id);
        }
        $emailNote = !empty($result['email_result']['ok'])
            ? 'Applicant notified by email.'
            : ('Email not sent: ' . ($result['email_result']['error'] ?? 'not configured') . '.');
        flash('success', 'Registration rejected. ' . $emailNote);
        redirect('users');
    }

    public static function editUser($id): void
    {
        Auth::authorize('users', 'edit');
        $editUser = db()->find('users', $id);
        if (!$editUser || !Auth::canManageUser($editUser)) {
            abort(403, 'You cannot edit this user.');
        }
        $sql = 'SELECT u.*, f.name AS farm_name FROM users u LEFT JOIN farms f ON f.id = u.farm_id WHERE 1=1';
        $params = [];
        if (!Auth::isAdmin()) {
            $sql .= ' AND u.role != ? AND u.farm_id = ?';
            $params[] = 'admin';
            $params[] = Auth::farmId();
        }
        $rows = db()->select($sql . ' ORDER BY u.id DESC', $params);
        $farms = farm_options();
        view('layouts/app', [
            'title' => 'Edit user',
            'content' => render('admin/users', compact('rows', 'farms', 'editUser')),
        ]);
    }

    public static function saveUser($id = null): void
    {
        Auth::authorize('users', $id ? 'edit' : 'create');
        Csrf::verify();
        $existing = $id ? db()->find('users', $id) : null;
        if ($id && (!$existing || !Auth::canManageUser($existing))) {
            abort(403, 'You cannot edit this user.');
        }
        $email = strtolower(trim((string) input('email')));
        $role = (string) input('role');
        $status = (string) input('status', 'active');
        $phoneRaw = trim((string) input('phone'));
        $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';
        $name = trim((string) input('name'));
        $failTo = $id ? 'users/edit/' . $id : 'users';
        if (!array_key_exists($role, Auth::roles()) || ($role === 'admin' && !Auth::isAdmin())) {
            flash('error', 'Invalid role.');
            redirect($failTo);
        }
        if ($id && (int) $id === (int) Auth::id() && $role !== $existing['role']) {
            flash('error', 'You cannot change your own role.');
            redirect($failTo);
        }
        $allowedStatuses = ['active', 'inactive', 'suspended'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'active';
        }
        $farmIds = input('farm_ids');
        if (!is_array($farmIds)) {
            $farmIds = [];
        }
        $farmIds = array_values(array_unique(array_filter(array_map('intval', $farmIds))));
        $farmId = input('farm_id') ?: ($farmIds[0] ?? null);
        if ($farmId && !in_array((int) $farmId, $farmIds, true)) {
            array_unshift($farmIds, (int) $farmId);
        }
        if (!Auth::isAdmin()) {
            $farmId = Auth::farmId();
            $farmIds = $farmId ? [(int) $farmId] : [];
        } else {
            foreach ($farmIds as $fid) {
                if (!db()->find('farms', $fid)) {
                    flash('error', 'You cannot assign an unknown farm.');
                    redirect($failTo);
                }
            }
        }
        if ($role !== 'admin' && !$farmIds) {
            flash('error', 'Assign at least one farm for this role.');
            redirect($failTo);
        }
        $usernameRaw = (string) input('username');
        $username = RegistrationService::normalizeUsername($usernameRaw);
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Name and a valid email are required.');
            redirect($failTo);
        }
        if ($usernameErr = RegistrationService::usernameError($usernameRaw, false)) {
            flash('error', $usernameErr);
            redirect($failTo);
        }
        if ($phoneRaw !== '' && !valid_phone($phoneRaw)) {
            flash('error', phone_validation_message());
            redirect($failTo);
        }
        $exists = db()->first('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists && (int) $exists['id'] !== (int) $id) {
            flash('error', 'Email already in use.');
            redirect($failTo);
        }
        if ($username !== '') {
            $uExists = db()->first('SELECT id FROM users WHERE lower(username) = ?', [$username]);
            if ($uExists && (int) $uExists['id'] !== (int) $id) {
                flash('error', 'Username already in use.');
                redirect($failTo);
            }
        }
        if ($id && (($existing['role'] ?? '') !== $role || ($existing['status'] ?? '') !== $status) && input('confirm_sensitive') !== '1') {
            flash('error', 'Confirm role or account status changes before saving.');
            redirect($failTo);
        }
        $data = [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'phone' => $phone ?: null,
            'farm_id' => $farmId ?: null,
            'status' => $status,
            'username' => $username !== '' ? $username : null,
        ];
        $sensitiveChange = $id && (
            ($existing['role'] ?? '') !== $role
            || ($existing['status'] ?? '') !== $status
            || (string) ($existing['farm_id'] ?? '') !== (string) ($farmId ?? '')
        );
        if ($id) {
            if (input('password')) {
                if ($pwErrors = PasswordPolicy::errors((string) input('password'))) {
                    flash('error', implode(' ', $pwErrors));
                    redirect($failTo);
                }
                $data['password'] = password_hash((string) input('password'), PASSWORD_DEFAULT);
                $data['must_change_password'] = 0;
            }
            if ($sensitiveChange) {
                $data['session_version'] = (int) ($existing['session_version'] ?? 1) + 1;
            }
            db()->update('users', $data, $id);
            if (Auth::isAdmin() || Auth::can('users', 'edit')) {
                RegistrationService::syncFarmAssignments((int) $id, $farmIds, $farmId ? (int) $farmId : null);
            }
            flash('success', 'User updated.');
            Audit::log('update', 'users', (int) $id, 'Role=' . $role . ' status=' . $status . ' farms=' . implode(',', $farmIds));
        } else {
            if ($pwErrors = PasswordPolicy::errors((string) input('password'))) {
                flash('error', implode(' ', $pwErrors));
                redirect($failTo);
            }
            $data['password'] = password_hash((string) input('password'), PASSWORD_DEFAULT);
            $data['must_change_password'] = (string) input('must_change_password') === '1' ? 1 : 0;
            $data['session_version'] = 1;
            $id = db()->insert('users', $data);
            RegistrationService::syncFarmAssignments((int) $id, $farmIds, $farmId ? (int) $farmId : null);
            flash('success', 'User created.');
            Audit::log('create', 'users', (int) $id, 'Admin-created user role=' . $role);
        }
        redirect('users');
    }

    public static function deleteUser($id): void
    {
        Auth::authorize('users', 'delete');
        Csrf::verify();
        if ((int) $id === (int) Auth::id()) {
            flash('error', 'You cannot delete your own account.');
            redirect('users');
        }
        $target = db()->find('users', $id);
        if (!$target || !Auth::canManageUser($target)) {
            abort(403, 'You cannot delete this user.');
        }
        db()->delete('users', $id);
        Audit::log('delete', 'users', $id);
        flash('success', 'User deleted.');
        redirect('users');
    }

    public static function audit(): void
    {
        Auth::authorize('audit_logs', 'view');
        $sql = 'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE 1=1';
        $params = [];
        if (!Auth::isAdmin()) {
            if (Auth::farmId()) {
                $sql .= ' AND a.farm_id = ?';
                $params[] = Auth::farmId();
            } else {
                $sql .= ' AND 1=0';
            }
        }
        $rows = db()->select($sql . ' ORDER BY a.id DESC LIMIT 200', $params);
        view('layouts/app', [
            'title' => 'Audit Logs',
            'content' => render('admin/audit', compact('rows')),
        ]);
    }

    public static function backup(): void
    {
        Auth::authorize('backups', 'view');
        $files = glob(dirname(__DIR__, 2) . '/storage/backups/*') ?: [];
        rsort($files);
        view('layouts/app', [
            'title' => 'Data Backup',
            'content' => render('admin/backup', ['files' => $files]),
        ]);
    }

    public static function runBackup(): void
    {
        Auth::authorize('backups', 'create');
        Csrf::verify();
        $path = self::writeBackup('ifms-backup-' . date('Ymd-His'));
        Audit::log('backup', 'system', null, 'Database backup created: ' . basename($path));
        flash('success', 'Backup created.');
        redirect('backup');
    }

    public static function downloadBackup($name): void
    {
        Auth::authorize('backups', 'view');
        $file = self::backupFile($name);
        if (!$file) {
            flash('error', 'That backup file is unavailable.');
            redirect('backup');
        }
        Audit::log('export', 'backups', null, 'Downloaded ' . basename($file));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public static function restoreBackup($name): void
    {
        Auth::authorize('backups', 'delete');
        Csrf::verify();
        if (input('confirm_restore') !== 'RESTORE') {
            flash('error', 'Type RESTORE in the confirmation field to restore a backup.');
            redirect('backup');
        }
        $file = self::backupFile($name);
        if (!$file) {
            flash('error', 'That backup file is unavailable.');
            redirect('backup');
        }
        $safety = self::writeBackup('ifms-pre-restore-' . date('Ymd-His'));
        try {
            if (str_ends_with($file, '.sqlite')) {
                if (db()->driver() !== 'sqlite') {
                    throw new RuntimeException('This SQLite backup cannot be restored onto the current database driver.');
                }
                $dest = config('sqlite_path');
                $tmp = $dest . '.restore-tmp';
                if (!copy($file, $tmp)) {
                    throw new RuntimeException('Could not stage the backup file.');
                }
                $size = filesize($tmp);
                if ($size < 1024) {
                    @unlink($tmp);
                    throw new RuntimeException('Backup file is too small to be a valid database.');
                }
                $probe = new PDO('sqlite:' . $tmp);
                $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $ok = $probe->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
                $probe = null;
                if (!$ok) {
                    @unlink($tmp);
                    throw new RuntimeException('Backup does not contain a FarmWise users table.');
                }
                Database::close();
                $replaced = @copy($tmp, $dest);
                @unlink($tmp);
                Database::boot();
                if (!$replaced) {
                    throw new RuntimeException('Could not replace the live database file. A safety backup was kept: ' . basename($safety));
                }
            } elseif (str_ends_with($file, '.json')) {
                $payload = json_decode((string) file_get_contents($file), true);
                if (!is_array($payload) || empty($payload['users'])) {
                    throw new RuntimeException('Backup JSON is missing required tables.');
                }
                db()->pdo()->beginTransaction();
                foreach ($payload as $table => $rows) {
                    if (!in_array($table, db()->tables(), true) || !is_array($rows)) {
                        continue;
                    }
                    db()->pdo()->exec('DELETE FROM ' . $table);
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            db()->insert($table, $row);
                        }
                    }
                }
                db()->pdo()->commit();
            } else {
                throw new RuntimeException('Unsupported backup format.');
            }
        } catch (Throwable $e) {
            if (db()->pdo()->inTransaction()) {
                db()->pdo()->rollBack();
            }
            Audit::log('restore', 'backups', null, 'Restore failed: ' . $e->getMessage());
            flash('error', 'Restore failed: ' . $e->getMessage());
            redirect('backup');
        }
        Audit::log('restore', 'backups', null, 'Restored ' . basename($file) . '; safety copy ' . basename($safety));
        flash('success', 'Backup restored. A safety copy of the previous data was saved as ' . basename($safety) . '. Sign in again if your session is invalid.');
        redirect('backup');
    }

    private static function backupDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function backupFile($name): ?string
    {
        $base = basename((string) $name);
        if ($base === '' || $base !== $name || !preg_match('/^ifms-(backup|pre-restore)-\d{8}-\d{6}\.(sqlite|json)$/', $base)) {
            return null;
        }
        $path = self::backupDir() . DIRECTORY_SEPARATOR . $base;
        $realDir = realpath(self::backupDir());
        $realFile = realpath($path);
        if (!$realDir || !$realFile || !is_file($realFile)) {
            return null;
        }
        $prefix = strtolower(rtrim($realDir, '\\/') . DIRECTORY_SEPARATOR);
        if (!str_starts_with(strtolower($realFile), $prefix)) {
            return null;
        }
        return $realFile;
    }

    private static function writeBackup(string $name): string
    {
        $dir = self::backupDir();
        if (db()->driver() === 'sqlite') {
            $path = $dir . DIRECTORY_SEPARATOR . $name . '.sqlite';
            if (is_file($path)) {
                @unlink($path);
            }
            $quoted = str_replace('\\', '/', $path);
            db()->pdo()->exec('VACUUM INTO ' . db()->pdo()->quote($quoted));
            return $path;
        }
        $rows = [];
        foreach (db()->tables() as $table) {
            $rows[$table] = db()->select('SELECT * FROM ' . $table);
        }
        $path = $dir . '/' . $name . '.json';
        file_put_contents($path, json_encode($rows));
        return $path;
    }

    public static function map(): void
    {
        Auth::authorize('fields', 'view');
        [$farmClause, $farmParams] = active_farm_constraint('farm_id');
        $fields = db()->select(
            'SELECT * FROM fields WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND ' . $farmClause,
            $farmParams
        );
        if (Auth::isAdmin() && !Auth::farmId()) {
            $farms = db()->select('SELECT * FROM farms');
        } elseif (Auth::farmId() && (Auth::isAdmin() || Auth::canAccessFarm((int) Auth::farmId()))) {
            $one = db()->find('farms', Auth::farmId());
            $farms = $one ? [$one] : [];
        } else {
            $farms = [];
        }
        view('layouts/app', [
            'title' => 'Farm Map',
            'content' => render('map', compact('fields', 'farms')),
        ]);
    }

    public static function geocode(): void
    {
        Auth::requireAuth();
        // Map placement is for users who may edit farm or field locations — not every signed-in role.
        if (!Auth::can('fields', 'edit') && !Auth::can('farms', 'edit')) {
            abort(403, 'You do not have permission to look up farm locations.');
        }
        $lat = input('lat');
        $lon = input('lon');
        if ($lat !== null && $lat !== '' && $lon !== null && $lon !== '') {
            $url = 'https://nominatim.openstreetmap.org/reverse?format=json&addressdetails=1&lat='
                . urlencode((string) $lat) . '&lon=' . urlencode((string) $lon);
            $raw = json_decode(ApiController::nominatim($url), true);
            json_response(['ok' => true, 'results' => $raw ? [$raw] : []]);
            return;
        }

        $q = trim((string) input('q', ''));
        $town = trim((string) input('town', ''));
        $district = trim((string) input('district', ''));
        $province = trim((string) input('province', ''));
        $landmark = trim((string) input('landmark', ''));
        $country = trim((string) input('country', 'Zambia'));
        if ($country === '' || $country === 'Other') {
            $country = 'Zambia';
        }

        if ($q === '') {
            $parts = array_filter([$landmark, $town, $district, $province, $country], static fn ($p) => $p !== '');
            $q = implode(', ', $parts);
        } elseif ($country && !str_contains(mb_strtolower($q), mb_strtolower($country))) {
            $q .= ', ' . $country;
        }

        if (mb_strlen($q) < 2) {
            json_response(['ok' => false, 'error' => 'Enter a town, village or landmark to search.', 'results' => []]);
            return;
        }

        $countryCodes = [
            'Zambia' => 'zm', 'Zimbabwe' => 'zw', 'Malawi' => 'mw', 'Botswana' => 'bw',
            'Namibia' => 'na', 'South Africa' => 'za', 'Tanzania' => 'tz', 'Mozambique' => 'mz',
        ];
        $cc = $countryCodes[$country] ?? 'zm';
        $url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=8'
            . '&countrycodes=' . urlencode($cc)
            . '&q=' . urlencode($q);
        $list = json_decode(ApiController::nominatim($url), true);
        if (!is_array($list)) {
            $list = [];
        }
        // Friendly labels for farmers (short place name first)
        $results = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
            $short = $addr['village'] ?? $addr['town'] ?? $addr['city'] ?? $addr['suburb'] ?? $addr['county'] ?? null;
            $row['short_name'] = $short ?: (explode(',', (string) ($row['display_name'] ?? ''))[0] ?? 'Place');
            $row['farmer_label'] = trim($row['short_name'] . (isset($addr['state']) ? ', ' . $addr['state'] : '') . ', ' . ($addr['country'] ?? $country));
            $results[] = $row;
        }
        json_response([
            'ok' => true,
            'query' => $q,
            'results' => $results,
            'message' => $results ? null : 'No matching place found. Try a nearby town name, or place a pin on the map.',
        ]);
    }

    public static function integrations(): void
    {
        Auth::authorize('integrations', 'view');
        MessageDispatcher::flush();
        view('layouts/app', [
            'title' => 'Integrations',
            'content' => render('admin/integrations', ['settings' => Settings::all()]),
        ]);
    }

    public static function saveIntegrations(): void
    {
        Auth::authorize('integrations', 'edit');
        Csrf::verify();
        $keys = [
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'smtp_from_name', 'smtp_encryption',
            'sms_provider', 'sms_user', 'sms_api_key', 'sms_sender', 'sms_url',
            'notify_email', 'notify_sms',
        ];
        foreach ($keys as $key) {
            Settings::put($key, input($key, ''));
        }
        if (input('smtp_pass') !== '') {
            Settings::put('smtp_pass', input('smtp_pass'));
        }
        Audit::log('update', 'integrations', null, 'Integration settings saved');
        flash('success', 'Integration settings saved.');
        redirect('integrations');
    }

    public static function testEmail(): void
    {
        Auth::authorize('integrations', 'edit');
        Csrf::verify();
        $to = strtolower(trim((string) (input('test_email') ?: (Auth::user()['email'] ?? ''))));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
            redirect('integrations');
        }
        $result = MessageDispatcher::testEmail($to);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Test email sent to ' . $to . '.' : $result['error']);
        redirect('integrations');
    }

    public static function testSms(): void
    {
        Auth::authorize('integrations', 'edit');
        Csrf::verify();
        $raw = trim((string) (input('test_sms') ?: (Auth::user()['phone'] ?? '')));
        $to = normalize_phone($raw);
        if ($raw === '' || !valid_phone($to)) {
            flash('error', phone_validation_message());
            redirect('integrations');
        }
        $result = MessageDispatcher::testSms($to);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Test SMS sent to ' . $to . '.' : $result['error']);
        redirect('integrations');
    }

    public static function messages(): void
    {
        Auth::authorize('messages', 'view');
        MessageDispatcher::flush();
        $sql = 'SELECT * FROM outbound_messages WHERE 1=1';
        $params = [];
        if (!Auth::isAdmin()) {
            if (Auth::farmId()) {
                $sql .= ' AND (farm_id = ? OR farm_id IS NULL)';
                $params[] = Auth::farmId();
            } else {
                $sql .= ' AND 1=0';
            }
        }
        $rows = db()->select($sql . ' ORDER BY id DESC LIMIT 120', $params);
        view('layouts/app', [
            'title' => 'Outbound messages',
            'content' => render('admin/messages', compact('rows')),
        ]);
    }

    private static function currentFarm(): ?array
    {
        if (Auth::farmId()) {
            $farm = db()->find('farms', Auth::farmId());
            if ($farm && (Auth::isAdmin() || Auth::canAccessFarm((int) $farm['id']))) {
                return $farm;
            }
            return null;
        }
        if (Auth::isAdmin()) {
            return db()->first('SELECT * FROM farms ORDER BY id');
        }
        return null;
    }
}
