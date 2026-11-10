<?php

class NotificationService
{
    /** @var array<string,int>|null */
    private static ?array $todayKeys = null;

    /** @var array<string,list<array<string,mixed>>> */
    private static array $roleRecipients = [];

    public static function create(array $data): int
    {
        self::loadToday();
        $key = self::todayKey($data['title'] ?? '', $data['category'] ?? '', $data['farm_id'] ?? null, $data['user_id'] ?? null);
        if (isset(self::$todayKeys[$key])) {
            return (int) self::$todayKeys[$key];
        }
        $id = self::createForced($data);
        self::$todayKeys[$key] = $id;
        return $id;
    }

    private static function todayKey($title, $category, $farmId, $userId): string
    {
        $user = (int) $userId;
        $farm = (int) $farmId;
        if ($user > 0) {
            return (string) $category . '|' . (string) $title . '|u' . $user;
        }
        if ($farm > 0) {
            return (string) $category . '|' . (string) $title . '|f' . $farm;
        }
        return (string) $category . '|' . (string) $title . '|g';
    }

    private static function loadToday(): void
    {
        if (self::$todayKeys !== null) {
            return;
        }
        self::$todayKeys = [];
        $today = date('Y-m-d');
        foreach (db()->select(
            'SELECT id, title, category, farm_id, user_id FROM notifications WHERE CAST(created_at AS DATE) = CAST(? AS DATE)',
            [$today]
        ) as $row) {
            $key = self::todayKey($row['title'] ?? '', $row['category'] ?? '', $row['farm_id'] ?? null, $row['user_id'] ?? null);
            self::$todayKeys[$key] = (int) $row['id'];
        }
    }

    /** Always insert (used for registration alerts where uniqueness is per request). */
    public static function createForced(array $data): int
    {
        $data['is_read'] = $data['is_read'] ?? 0;
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        if (isset($data['title'])) {
            $data['title'] = mb_substr((string) $data['title'], 0, 160);
        }
        if (isset($data['link']) && $data['link'] !== null) {
            $data['link'] = mb_substr((string) $data['link'], 0, 160);
        }
        $id = db()->insert('notifications', $data);
        // Do not auto-email every forced in-app admin alert (AccountMail handles applicant emails).
        return $id;
    }

    /** Push a calendar event into Alerts so it is visible for the active farm / creator. */
    public static function notifyCalendarEvent(array $event, bool $updated = false): int
    {
        $eventId = (int) ($event['id'] ?? 0);
        $title = trim((string) ($event['title'] ?? 'Event'));
        $date = substr((string) ($event['event_date'] ?? ''), 0, 10);
        $start = substr((string) ($event['start_time'] ?? ''), 0, 5);
        $eventFarm = (int) ($event['farm_id'] ?? 0);
        $activeFarm = (int) (Auth::farmId() ?: 0);
        $farmId = $activeFarm > 0 ? $activeFarm : ($eventFarm > 0 ? $eventFarm : null);
        $userId = (int) (Auth::id() ?: ($event['user_id'] ?? 0)) ?: null;
        $timeBit = $start !== '' ? ' at ' . $start : '';
        $prefix = $updated ? 'Calendar updated: ' : 'Calendar event: ';
        // Personal alert (always listed for the creator) + farm-wide when a farm is set.
        $id = self::createForced([
            'category' => 'calendar',
            'title' => $prefix . $title,
            'message' => ($updated ? 'Updated for ' : 'Scheduled for ') . ($date !== '' ? $date : 'soon') . $timeBit . '.',
            'severity' => 'info',
            'link' => 'calendar?event=' . $eventId . ($date !== '' ? '&date=' . $date : ''),
            'farm_id' => $farmId,
            'user_id' => $userId,
        ]);
        if ($farmId && $userId) {
            self::create([
                'category' => 'calendar',
                'title' => $prefix . $title,
                'message' => ($updated ? 'Updated for ' : 'Scheduled for ') . ($date !== '' ? $date : 'soon') . $timeBit . '.',
                'severity' => 'info',
                'link' => 'calendar?event=' . $eventId . ($date !== '' ? '&date=' . $date : ''),
                'farm_id' => $farmId,
                'user_id' => null,
            ]);
        }
        return $id;
    }

    public static function unreadCount(?int $userId, ?int $farmId): int
    {
        $cacheKey = 'unread:' . (int) ($userId ?: Auth::id()) . ':' . (int) $farmId . ':' . (Auth::isAdmin() ? 'a' : 'u');
        $cached = session_memo_get($cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }
        $sql = 'SELECT COUNT(*) FROM notifications WHERE is_read = 0';
        $params = [];
        if (Auth::check() && Auth::isAdmin()) {
            // Admins see system-wide + personally targeted alerts
            $sql .= ' AND (user_id IS NULL OR user_id = ?)';
            $params[] = $userId ?: Auth::id();
        } elseif ($farmId) {
            $sql .= ' AND (user_id = ? OR (user_id IS NULL AND (farm_id = ? OR farm_id IS NULL)))';
            $params[] = $userId ?: 0;
            $params[] = $farmId;
        } else {
            $sql .= ' AND user_id = ?';
            $params[] = $userId ?: 0;
        }
        $count = (int) db()->value($sql, $params);
        session_memo_set($cacheKey, $count, 15);
        return $count;
    }

    public static function latest(int $limit = 8): array
    {
        $sql = 'SELECT * FROM notifications WHERE 1=1';
        $params = [];
        if (Auth::isAdmin()) {
            $sql .= ' AND (user_id IS NULL OR user_id = ?)';
            $params[] = Auth::id();
        } elseif (Auth::farmId()) {
            $sql .= ' AND (user_id = ? OR (user_id IS NULL AND (farm_id = ? OR farm_id IS NULL)))';
            $params[] = Auth::id();
            $params[] = Auth::farmId();
        } else {
            $sql .= ' AND user_id = ?';
            $params[] = Auth::id();
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
        return db()->select($sql, $params);
    }

    public static function syncAlerts(bool $force = false): void
    {
        // Throttle: full sync is many Neon round-trips; run at most every 5 minutes per session
        if (!$force) {
            $last = (int) ($_SESSION['_alert_sync_at'] ?? 0);
            if ($last > 0 && (time() - $last) < 300) {
                return;
            }
            $_SESSION['_alert_sync_at'] = time();
        }

        // CLI cron and administrators scan farms. A signed-in user with no farm must not scan every farm.
        if (PHP_SAPI !== 'cli' && Auth::check() && !Auth::isAdmin()) {
            $scopedFarm = Auth::farmId();
            if (!$scopedFarm) {
                return;
            }
            $farmFilter = 'farm_id = ' . (int) $scopedFarm;
        } else {
            $farmFilter = '1=1';
        }

        foreach (db()->select("SELECT * FROM inventory_items WHERE {$farmFilter} AND quantity <= reorder_level") as $item) {
            self::create([
                'farm_id' => $item['farm_id'],
                'category' => 'inventory',
                'title' => 'Low stock: ' . $item['name'],
                'message' => $item['name'] . ' is at ' . $item['quantity'] . ' ' . $item['unit'] . ', below reorder level.',
                'severity' => 'warning',
                'link' => 'module/inventory_items',
            ]);
        }

        $soon = date('Y-m-d', strtotime('+14 days'));
        foreach (db()->select("SELECT * FROM inventory_items WHERE {$farmFilter} AND expiry_date IS NOT NULL AND expiry_date <= ?", [$soon]) as $item) {
            self::create([
                'farm_id' => $item['farm_id'],
                'category' => 'inventory',
                'title' => 'Expiring input: ' . $item['name'],
                'message' => $item['name'] . ' expires on ' . $item['expiry_date'] . '.',
                'severity' => 'warning',
                'link' => 'module/inventory_items',
            ]);
        }

        foreach (db()->select("SELECT * FROM spray_schedules WHERE {$farmFilter} AND status = 'scheduled' AND scheduled_date <= ?", [$soon]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'spraying',
                'title' => 'Spraying due: ' . $row['product_name'],
                'message' => 'Scheduled for ' . $row['scheduled_date'] . '.',
                'severity' => 'info',
                'link' => 'module/spray_schedules',
            ]);
        }

        foreach (db()->select("SELECT * FROM irrigation_schedules WHERE {$farmFilter} AND status = 'scheduled' AND scheduled_date <= ?", [date('Y-m-d', strtotime('+3 days'))]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'irrigation',
                'title' => 'Irrigation scheduled',
                'message' => 'Irrigation is due on ' . $row['scheduled_date'] . '.',
                'severity' => 'info',
                'link' => 'module/irrigation_schedules',
            ]);
        }

        foreach (db()->select("SELECT * FROM livestock_health WHERE {$farmFilter} AND next_due IS NOT NULL AND next_due <= ?", [$soon]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'vaccination',
                'title' => 'Health due: ' . $row['title'],
                'message' => 'Follow-up or vaccination is due on ' . $row['next_due'] . '.',
                'severity' => 'warning',
                'link' => 'module/livestock_health',
            ]);
        }

        foreach (db()->select("SELECT * FROM equipment WHERE {$farmFilter} AND next_service IS NOT NULL AND next_service <= ?", [$soon]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'equipment',
                'title' => 'Maintenance due: ' . $row['name'],
                'message' => $row['name'] . ' service is due on ' . $row['next_service'] . '.',
                'severity' => 'warning',
                'link' => 'module/equipment',
            ]);
        }

        foreach (db()->select("SELECT * FROM plantings WHERE {$farmFilter} AND expected_harvest IS NOT NULL AND expected_harvest <= ? AND status != 'harvested'", [$soon]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'harvest',
                'title' => 'Harvest approaching',
                'message' => 'A planting is expected for harvest on ' . $row['expected_harvest'] . '.',
                'severity' => 'info',
                'link' => 'module/plantings',
            ]);
        }

        foreach (db()->select("SELECT * FROM plantings WHERE {$farmFilter} AND planting_date = ?", [date('Y-m-d')]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'planting',
                'title' => 'Planting date today',
                'message' => 'A planting is scheduled for today.',
                'severity' => 'info',
                'link' => 'module/plantings',
            ]);
        }

        foreach (db()->select("SELECT * FROM pest_scouting WHERE {$farmFilter} AND severity IN ('high','critical') AND scout_date >= ?", [date('Y-m-d', strtotime('-7 days'))]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'disease',
                'title' => 'Pest/disease outbreak alert',
                'message' => 'High-severity scouting was recorded on ' . $row['scout_date'] . '.',
                'severity' => 'danger',
                'link' => 'module/pest_scouting',
            ]);
        }

        foreach (db()->select("SELECT * FROM fertilizer_applications WHERE {$farmFilter} AND application_date = ?", [date('Y-m-d')]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'fertilizer',
                'title' => 'Fertilizer application today',
                'message' => $row['product_name'] . ' is scheduled for application today.',
                'severity' => 'info',
                'link' => 'module/fertilizer_applications',
            ]);
        }

        foreach (db()->select("SELECT * FROM water_logs WHERE {$farmFilter} AND issue_flag != 'none' AND log_date >= ?", [date('Y-m-d', strtotime('-3 days'))]) as $row) {
            self::create([
                'farm_id' => $row['farm_id'],
                'category' => 'irrigation',
                'title' => 'Irrigation problem reported',
                'message' => $row['notes'] ?: 'A water-log issue was flagged.',
                'severity' => 'danger',
                'link' => 'module/water_logs',
            ]);
        }

        $today = date('Y-m-d');
        foreach (db()->select(
            "SELECT id, farm_id, title, due_date FROM tasks WHERE {$farmFilter} AND status NOT IN ('completed','cancelled') AND due_date IS NOT NULL AND due_date < ? ORDER BY due_date ASC LIMIT 25",
            [$today]
        ) as $row) {
            self::notifyRoles((int) $row['farm_id'], ['owner', 'manager'], [
                'category' => 'tasks',
                'title' => 'Overdue task: ' . $row['title'],
                'message' => '"' . $row['title'] . '" was due on ' . $row['due_date'] . ' and is not completed.',
                'severity' => 'warning',
                'link' => 'module/tasks/view/' . $row['id'],
            ]);
        }

        foreach (db()->select(
            "SELECT id, farm_id, name, category, planned_amount, period_start, period_end FROM budgets WHERE {$farmFilter} AND period_start <= ? AND period_end >= ? LIMIT 30",
            [$today, $today]
        ) as $budget) {
            $planned = (float) ($budget['planned_amount'] ?? 0);
            if ($planned <= 0) {
                continue;
            }
            $params = [(int) $budget['farm_id'], $budget['period_start'], $budget['period_end']];
            $categorySql = '';
            $category = trim((string) ($budget['category'] ?? ''));
            if ($category !== '') {
                $categorySql = ' AND LOWER(category) = LOWER(?)';
                $params[] = $category;
            }
            $spent = (float) db()->value(
                "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE farm_id = ? AND type = 'expense' AND txn_date >= ? AND txn_date <= ?" . $categorySql,
                $params
            );
            if ($spent <= $planned) {
                continue;
            }
            self::notifyRoles((int) $budget['farm_id'], ['owner', 'accountant'], [
                'category' => 'budget',
                'title' => 'Budget exceeded: ' . $budget['name'],
                'message' => 'Recorded expenses of ' . number_format($spent, 2) . ' are above the planned ' . number_format($planned, 2) . ' for this budget period.',
                'severity' => 'warning',
                'link' => 'reports?type=budget',
            ]);
        }

        foreach (db()->select(
            "SELECT id, farm_id, planting_id, health_status, record_date FROM crop_growth WHERE {$farmFilter} AND health_status IN ('stressed','diseased') AND record_date >= ? ORDER BY record_date DESC LIMIT 25",
            [date('Y-m-d', strtotime('-14 days'))]
        ) as $row) {
            self::notifyRoles((int) $row['farm_id'], ['owner', 'manager', 'agronomist'], [
                'category' => 'crop',
                'title' => 'Crop condition needs review',
                'message' => 'A growth record on ' . $row['record_date'] . ' is ' . $row['health_status'] . '. Review that planting before drawing a yield conclusion. More field records are required for a stronger recommendation.',
                'severity' => $row['health_status'] === 'diseased' ? 'danger' : 'warning',
                'link' => 'module/crop_growth/view/' . $row['id'],
            ]);
        }

        // Calendar events → Alerts (all upcoming / recently saved events)
        if (table_exists('calendar_events')) {
            $reminderWindow = date('Y-m-d', strtotime('+30 days'));
            foreach (db()->select(
                "SELECT * FROM calendar_events WHERE event_date >= ? AND event_date <= ? AND (reminder_sent_at IS NULL) AND {$farmFilter} ORDER BY event_date ASC LIMIT 60",
                [$today, $reminderWindow]
            ) as $row) {
                self::notifyCalendarEvent($row, false);
                try {
                    db()->update('calendar_events', ['reminder_sent_at' => date('Y-m-d H:i:s')], (int) $row['id']);
                } catch (Throwable $e) {
                }
            }
        }

        // Weather warnings from saved weather logs
        if (table_exists('weather_logs')) {
            foreach (db()->select(
                "SELECT * FROM weather_logs WHERE {$farmFilter} AND alert_level IN ('watch','extreme','warning','danger') AND log_date >= ? ORDER BY log_date DESC LIMIT 20",
                [date('Y-m-d', strtotime('-2 days'))]
            ) as $row) {
                self::create([
                    'farm_id' => $row['farm_id'],
                    'category' => 'weather',
                    'title' => 'Weather alert: ' . ($row['alert_level'] ?? 'watch'),
                    'message' => 'Logged conditions on ' . $row['log_date'] . ' may affect outdoor work.',
                    'severity' => (($row['alert_level'] ?? '') === 'extreme' || ($row['alert_level'] ?? '') === 'danger') ? 'danger' : 'warning',
                    'link' => 'weather',
                ]);
            }
        }
    }

    /** @param list<string> $roles @param array<string,mixed> $data */
    private static function notifyRoles(int $farmId, array $roles, array $data): void
    {
        if ($farmId <= 0 || $roles === []) {
            return;
        }
        $recipientKey = $farmId . ':' . implode(',', $roles);
        if (!isset(self::$roleRecipients[$recipientKey])) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $params = $roles;
            $params[] = $farmId;
            if (table_exists('user_farms')) {
                $params[] = $farmId;
                $sql = "SELECT DISTINCT id FROM users WHERE status = 'active' AND role IN ({$placeholders}) AND (farm_id = ? OR id IN (SELECT user_id FROM user_farms WHERE farm_id = ?))";
            } else {
                $sql = "SELECT id FROM users WHERE status = 'active' AND role IN ({$placeholders}) AND farm_id = ?";
            }
            self::$roleRecipients[$recipientKey] = db()->select($sql, $params);
        }
        $users = self::$roleRecipients[$recipientKey];
        if (!$users) {
            self::create($data + ['farm_id' => $farmId]);
            return;
        }
        foreach ($users as $user) {
            self::create($data + ['farm_id' => $farmId, 'user_id' => (int) $user['id']]);
        }
    }
}
