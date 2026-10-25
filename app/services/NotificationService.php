<?php

class NotificationService
{
    public static function create(array $data): int
    {
        // Postgres: prefer ::date; SQLite accepts date() — both work with CAST
        $today = date('Y-m-d');
        if (!empty($data['farm_id'])) {
            $exists = db()->first(
                'SELECT id FROM notifications WHERE title = ? AND category = ? AND farm_id = ? AND CAST(created_at AS DATE) = CAST(? AS DATE)',
                [$data['title'], $data['category'], $data['farm_id'], $today]
            );
        } else {
            $exists = db()->first(
                'SELECT id FROM notifications WHERE title = ? AND category = ? AND farm_id IS NULL AND CAST(created_at AS DATE) = CAST(? AS DATE)',
                [$data['title'], $data['category'], $today]
            );
        }
        if ($exists) {
            return (int) $exists['id'];
        }
        $id = db()->insert('notifications', $data);
        try {
            MessageDispatcher::fromNotification($data, $id);
        } catch (Throwable $e) {
            // in-app alert still saved if outbound delivery fails
        }
        return $id;
    }

    public static function unreadCount(?int $userId, ?int $farmId): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE is_read = 0';
        $params = [];
        if ($farmId) {
            $sql .= ' AND (farm_id = ? OR farm_id IS NULL)';
            $params[] = $farmId;
        }
        if ($userId) {
            $sql .= ' AND (user_id = ? OR user_id IS NULL)';
            $params[] = $userId;
        }
        return (int) db()->value($sql, $params);
    }

    public static function latest(int $limit = 8): array
    {
        $sql = 'SELECT * FROM notifications WHERE 1=1';
        $params = [];
        if (!Auth::isAdmin() && Auth::farmId()) {
            $sql .= ' AND (farm_id = ? OR farm_id IS NULL)';
            $params[] = Auth::farmId();
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

        $farmId = Auth::isAdmin() ? null : Auth::farmId();
        $farmFilter = $farmId ? 'farm_id = ' . (int) $farmId : '1=1';

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
    }
}
