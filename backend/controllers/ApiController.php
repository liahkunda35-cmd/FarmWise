<?php

class ApiController
{
    public static function dispatch(array $parts): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        if (request_method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        $action = $parts[1] ?? '';
        try {
            if ($action === 'login' && is_post()) {
                self::login();
                return;
            }
            $user = self::requireToken();
            Auth::setOverride($user);
            match ($action) {
                'me' => self::ok(['user' => self::publicUser($user)]),
                'dashboard' => self::dashboard(),
                'weather' => self::weather(),
                'notifications' => self::notifications(),
                'geocode' => self::geocode(),
                'module' => self::module($parts[2] ?? '', $parts[3] ?? null),
                default => self::fail(404, 'Unknown API route. Use /api/login, /api/me, /api/dashboard, /api/weather, /api/notifications, /api/geocode, /api/module/{name}.'),
            };
        } catch (Throwable $e) {
            log_internal_error('api', $e);
            self::fail(500, 'The request could not be completed.');
        }
    }

    private static function login(): void
    {
        $body = self::json();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $user = db()->first('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            self::fail(401, 'Invalid credentials.');
        }
        $token = Jwt::encode([
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'farm_id' => $user['farm_id'],
        ]);
        Audit::log('api_login', 'auth', $user['id'], 'JWT issued');
        self::ok(['token' => $token, 'token_type' => 'Bearer', 'expires_in' => 86400, 'user' => self::publicUser($user)]);
    }

    private static function requireToken(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$header && function_exists('apache_request_headers')) {
            $hdrs = apache_request_headers();
            $header = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
        }
        if (!preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            self::fail(401, 'Missing Bearer token.');
        }
        $payload = Jwt::decode($m[1]);
        if (!$payload || empty($payload['sub'])) {
            self::fail(401, 'Invalid or expired token.');
        }
        $user = db()->find('users', $payload['sub']);
        if (!$user || $user['status'] !== 'active') {
            self::fail(401, 'Account is not active.');
        }
        return $user;
    }

    private static function dashboard(): void
    {
        Auth::authorize('dashboard', 'view');
        if (Auth::farmId()) {
            $farmSql = 'farm_id = ' . (int) Auth::farmId();
            $farmWhere = ' WHERE id=' . (int) Auth::farmId();
        } elseif (Auth::isAdmin()) {
            $farmSql = '1=1';
            $farmWhere = '';
        } else {
            $farmSql = '1=0';
            $farmWhere = ' WHERE 1=0';
        }
        $payload = [
            'acreage' => (float) db()->value('SELECT COALESCE(SUM(total_area),0) FROM farms' . $farmWhere),
            'active_crops' => (int) db()->value("SELECT COUNT(*) FROM plantings WHERE {$farmSql} AND status IN ('planted','growing')"),
            'livestock' => (int) db()->value("SELECT COUNT(*) FROM animals WHERE {$farmSql} AND status='active'"),
            'alerts' => (int) db()->value("SELECT COUNT(*) FROM notifications WHERE is_read=0 AND ({$farmSql} OR farm_id IS NULL)"),
        ];
        if (Auth::can('transactions', 'view')) {
            $payload['revenue'] = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} AND type='income'");
            $payload['expenses'] = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} AND type='expense'");
        }
        self::ok($payload);
    }

    private static function weather(): void
    {
        Auth::authorize('weather', 'view');
        $farm = null;
        if (Auth::farmId()) {
            $candidate = db()->find('farms', Auth::farmId());
            if ($candidate && (Auth::isAdmin() || Auth::canAccessFarm((int) $candidate['id']))) {
                $farm = $candidate;
            }
        } elseif (Auth::isAdmin()) {
            $farm = db()->first('SELECT * FROM farms ORDER BY id');
        }
        $forecast = null;
        if ($farm) {
            try {
                $forecast = WeatherService::fetchForFarm($farm);
            } catch (Throwable $e) {
                $forecast = [
                    'current' => null,
                    'daily' => null,
                    'meta' => ['ok' => false, 'error' => 'api_error', 'message' => 'Weather could not be loaded.'],
                ];
            }
        }
        // Never include API credentials — only normalized weather fields + status meta.
        self::ok([
            'farm' => $farm['name'] ?? null,
            'current' => $forecast['current'] ?? null,
            'daily' => $forecast['daily'] ?? null,
            'meta' => $forecast['meta'] ?? null,
        ]);
    }

    private static function notifications(): void
    {
        Auth::authorize('notifications', 'view');
        NotificationService::syncAlerts();
        $sql = 'SELECT id, category, title, message, severity, is_read, created_at FROM notifications WHERE 1=1';
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
        self::ok(['items' => db()->select($sql . ' ORDER BY id DESC LIMIT 50', $params)]);
    }

    private static function geocode(): void
    {
        if (!Auth::can('fields', 'edit') && !Auth::can('farms', 'edit')) {
            self::fail(403, 'You do not have permission to look up farm locations.');
        }
        $q = input('q', '');
        $lat = input('lat');
        $lon = input('lon');
        $url = $q
            ? 'https://nominatim.openstreetmap.org/search?format=json&limit=6&q=' . urlencode($q)
            : 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' . urlencode((string) $lat) . '&lon=' . urlencode((string) $lon);
        $json = self::nominatim($url);
        self::ok(['results' => json_decode($json, true) ?: []]);
    }

    public static function nominatim(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: IFMS-Farm-Management/1.0\r\n",
                'timeout' => 8,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        return $res !== false ? $res : '[]';
    }

    private static function module(string $key, $id): void
    {
        $def = module_def($key);
        if (!$def) {
            self::fail(404, 'Unknown module.');
        }
        $action = is_post() ? ($id ? 'edit' : 'create') : 'view';
        Auth::authorize($key, $action);
        $table = $def['table'];
        if (request_method() === 'GET' && !$id) {
            $where = '1=1';
            $params = [];
            if ($table === 'farms') {
                [$clause, $clauseParams] = farm_directory_constraint();
                $where .= ' AND ' . $clause;
                $params = array_merge($params, $clauseParams);
            } elseif (!empty($def['farm_scoped'])) {
                [$clause, $clauseParams] = active_farm_constraint('farm_id');
                $where .= ' AND ' . $clause;
                $params = array_merge($params, $clauseParams);
            }
            if ($table === 'tasks') {
                [$taskSql, $taskParams] = TaskAccess::listConstraint();
                $where .= $taskSql;
                $params = array_merge($params, $taskParams);
            }
            if ($table === 'attendance' && Auth::role() === 'worker') {
                $empId = TaskAccess::employeeIdForUser();
                if ($empId) {
                    $where .= ' AND employee_id = ?';
                    $params[] = $empId;
                } else {
                    $where .= ' AND 1=0';
                }
            }
            $items = db()->select('SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 100', $params);
            if (!Auth::can('transactions', 'view')) {
                $items = array_map([self::class, 'stripValuation'], $items);
            }
            self::ok(['items' => $items]);
        }
        if (request_method() === 'GET') {
            $row = db()->find($table, $id);
            if (!$row || !Auth::canAccessRecord($row)) {
                self::fail(404, 'Record not found.');
            }
            if ($table === 'farms' && !Auth::isAdmin() && !Auth::canAccessFarm((int) $row['id'])) {
                self::fail(404, 'Record not found.');
            }
            if ($table === 'tasks' && !TaskAccess::workerCanAccessTask($row)) {
                self::fail(403, 'You can only view tasks assigned to you.');
            }
            if ($table === 'attendance' && Auth::role() === 'worker') {
                $empId = TaskAccess::employeeIdForUser();
                if (!$empId || (int) ($row['employee_id'] ?? 0) !== $empId) {
                    self::fail(403, 'You can only view your own attendance.');
                }
            }
            if (!Auth::can('transactions', 'view')) {
                $row = self::stripValuation($row);
            }
            self::ok(['item' => $row]);
        }
        if (is_post()) {
            $payload = self::json();
            // Only accept known module fields
            $clean = [];
            foreach ($def['fields'] as $name => $field) {
                if (array_key_exists($name, $payload)) {
                    $clean[$name] = is_string($payload[$name]) ? trim($payload[$name]) : $payload[$name];
                    if ($clean[$name] === '') {
                        $clean[$name] = null;
                    }
                }
            }
            if (!empty($def['farm_scoped']) && isset($def['fields']['farm_id']) && !Auth::isAdmin()) {
                if (!Auth::farmId() || !Auth::canAccessFarm((int) Auth::farmId())) {
                    self::fail(403, 'Select a farm you can access before changing records.');
                }
                $clean['farm_id'] = Auth::farmId();
            }
            if ($table === 'tasks' && Auth::role() === 'worker') {
                if (!$id) {
                    self::fail(403, 'You can only update tasks assigned to you.');
                }
                $existingTask = db()->find('tasks', $id);
                if (!$existingTask || !Auth::canAccessRecord($existingTask) || !TaskAccess::workerCanAccessTask($existingTask)) {
                    self::fail(403, 'You can only update tasks assigned to you.');
                }
                $clean['farm_id'] = $existingTask['farm_id'];
                $clean['assigned_to'] = $existingTask['assigned_to'];
                $clean['title'] = $existingTask['title'] ?? ($clean['title'] ?? null);
                $clean['field_id'] = $existingTask['field_id'] ?? null;
                $clean['labour_cost'] = $existingTask['labour_cost'] ?? null;
                $clean['related_module'] = $existingTask['related_module'] ?? null;
                $clean['related_id'] = $existingTask['related_id'] ?? null;
            }
            if ($table === 'crop_growth' && !$id && Auth::id()) {
                $clean['recorded_by'] = Auth::id();
            }
            $clean = WorkerRecords::sanitizePayload($key, $clean);
            $workerErr = WorkerRecords::saveError($key, $clean, $id ? db()->find($table, $id) : null);
            if ($workerErr !== null) {
                self::fail(403, $workerErr);
            }
            if ($table === 'tasks' && (string) ($clean['related_module'] ?? '') === 'pest_scouting') {
                $scout = db()->find('pest_scouting', (int) ($clean['related_id'] ?? 0));
                if ($scout && empty($clean['field_id']) && !empty($scout['field_id'])) {
                    $clean['field_id'] = (int) $scout['field_id'];
                }
                $linkError = WorkflowService::scoutLinkError($clean, $id ? (int) $id : null);
                if ($linkError) {
                    $existingTaskId = WorkflowService::findScoutFollowUp((int) ($clean['related_id'] ?? 0));
                    if (!$id && $existingTaskId && $linkError === 'A follow-up task already exists for this scouting record.') {
                        self::ok(['item' => db()->find('tasks', $existingTaskId)]);
                    }
                    self::fail(422, $linkError);
                }
            }
            $errors = Validator::validate($clean, $def['fields']);
            if ($table === 'stock_movements' && empty($errors)) {
                if ($msg = OperationHooks::validateStockOut($clean, $id ? (int) $id : null)) {
                    $errors['quantity'] = $msg;
                }
            }
            $usageTables = ['fertilizer_applications', 'pest_treatments', 'livestock_feed', 'spray_schedules'];
            if (in_array($table, $usageTables, true) && empty($errors)) {
                $prevUsage = $id ? db()->find($table, $id) : null;
                if ($msg = OperationHooks::validateInventoryUsage($table, $clean, $prevUsage ?: null)) {
                    $errors['quantity'] = $msg;
                }
            }
            if ($table === 'dispatches' && empty($errors)) {
                if ($msg = OperationHooks::validateDispatch($clean)) {
                    $errors['quantity'] = $msg;
                }
            }
            if ($table === 'sales_orders' && empty($errors)) {
                if ($msg = OperationHooks::validateSalesOrder($clean)) {
                    $errors['quantity'] = $msg;
                }
            }
            if ($table === 'storage_lots' && empty($errors)) {
                if ($msg = OperationHooks::validateStorageLot($clean, $id ? (int) $id : null)) {
                    $errors['harvest_id'] = $msg;
                }
            }
            if ($table === 'payments' && empty($errors) && !$id) {
                if ($msg = OperationHooks::validatePayment($clean)) {
                    $errors['amount'] = $msg;
                }
            }
            if ($errors) {
                self::fail(422, implode(' ', $errors));
            }
            if ($id) {
                $row = db()->find($table, $id);
                if (!$row || !Auth::canAccessRecord($row)) {
                    self::fail(404, 'Record not found.');
                }
                if ($table === 'farms' && !Auth::isAdmin() && !Auth::canAccessFarm((int) $row['id'])) {
                    self::fail(404, 'Record not found.');
                }
                if ($table === 'tasks' && !TaskAccess::workerCanAccessTask($row)) {
                    self::fail(403, 'You can only update tasks assigned to you.');
                }
                db()->beginTransaction();
                try {
                    db()->update($table, $clean, $id);
                    OperationHooks::afterSave($def, $clean, (int) $id, false, $row);
                    db()->commit();
                } catch (Throwable $e) {
                    db()->rollBack();
                    self::fail(500, 'Could not update record.');
                }
                Audit::log('update', $key, (int) $id, 'API update');
                self::ok(['item' => db()->find($table, $id)]);
            }
            $newId = 0;
            db()->beginTransaction();
            try {
                if ($table === 'tasks' && (string) ($clean['related_module'] ?? '') === 'pest_scouting') {
                    $scoutId = (int) ($clean['related_id'] ?? 0);
                    WorkflowService::lockScoutFollowUp($scoutId);
                    $existingTask = WorkflowService::findScoutFollowUp($scoutId);
                    if ($existingTask) {
                        db()->rollBack();
                        self::ok(['item' => db()->find('tasks', $existingTask)]);
                    }
                }
                $newId = (int) db()->insert($table, $clean);
                OperationHooks::afterSave($def, $clean, $newId, true, null);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                if ($table === 'tasks' && str_contains(strtolower($e->getMessage()), 'idx_tasks_scout_followup')) {
                    $existingTask = WorkflowService::findScoutFollowUp((int) ($clean['related_id'] ?? 0));
                    if ($existingTask) {
                        self::ok(['item' => db()->find('tasks', $existingTask)]);
                    }
                }
                self::fail(500, 'Could not create record.');
            }
            Audit::log('create', $key, $newId, 'API create');
            self::ok(['item' => db()->find($table, $newId)], 201);
        }
        self::fail(405, 'Method not allowed.');
    }

    /** @param array<string,mixed> $row */
    private static function stripValuation(array $row): array
    {
        foreach (['unit_cost', 'purchase_cost', 'cost', 'labour_cost'] as $field) {
            unset($row[$field]);
        }
        return $row;
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'farm_id' => $user['farm_id'],
            'phone' => $user['phone'],
        ];
    }

    private static function ok($data, int $code = 200): void
    {
        if ((string) (getenv('IFMS_TEST_CAPTURE') ?: '') === '1') {
            $GLOBALS['ifms_test_exit'] = ['type' => 'json', 'code' => $code, 'data' => ['ok' => true, 'data' => $data]];
            throw new RuntimeException('IFMS_TEST_EXIT');
        }
        http_response_code($code);
        echo json_encode(['ok' => true, 'data' => $data]);
        exit;
    }

    private static function fail(int $code, string $message): void
    {
        if ((string) (getenv('IFMS_TEST_CAPTURE') ?: '') === '1') {
            $GLOBALS['ifms_test_exit'] = ['type' => 'json', 'code' => $code, 'data' => ['ok' => false, 'error' => $message]];
            throw new RuntimeException('IFMS_TEST_EXIT');
        }
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
}
