<?php

class DashboardController
{
    public static function index(): void
    {
        Auth::requireAuth();
        NotificationService::syncAlerts();
        $farm = self::farm();
        $forecast = null;
        if ($farm) {
            try {
                $forecast = WeatherService::fetchForFarm($farm);
            } catch (Throwable $e) {
                $forecast = null;
            }
        }
        $scope = self::scope();
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');

        // One round-trip for all KPI counts (Neon latency)
        $fs = $scope['farms']['sql'];
        $fp = $scope['farms']['params'];
        $ps = $scope['plain']['sql'];
        $pp = $scope['plain']['params'];
        $statsRow = db()->first(
            "SELECT
                (SELECT COUNT(*) FROM farms {$fs}) AS farms,
                (SELECT COALESCE(SUM(total_area),0) FROM farms {$fs}) AS acreage,
                (SELECT COUNT(*) FROM fields {$ps}) AS fields,
                (SELECT COUNT(*) FROM plantings {$ps} AND status IN ('planted','growing')) AS active_crops,
                (SELECT COUNT(*) FROM plantings {$ps} AND CAST(created_at AS DATE) >= CAST(? AS DATE)) AS crops_week,
                (SELECT COUNT(*) FROM animals {$ps} AND status = 'active') AS livestock,
                (SELECT COUNT(*) FROM animals {$ps} AND CAST(created_at AS DATE) >= CAST(? AS DATE)) AS livestock_week,
                (SELECT COUNT(*) FROM tasks {$ps} AND status != 'completed') AS pending_tasks,
                (SELECT COUNT(*) FROM tasks {$ps}) AS tasks_total,
                (SELECT COUNT(*) FROM tasks {$ps} AND status != 'completed' AND CAST(created_at AS DATE) <= CAST(? AS DATE)) AS tasks_yesterday,
                (SELECT COUNT(*) FROM irrigation_systems {$ps}) AS irrigation_systems,
                (SELECT COUNT(*) FROM irrigation_systems {$ps} AND status = 'active') AS irrigation_active,
                (SELECT MAX(updated_at) FROM irrigation_systems {$ps}) AS irrigation_updated",
            array_merge(
                $fp, $fp,
                $pp, $pp,
                $pp, [$weekAgo],
                $pp,
                $pp, [$weekAgo],
                $pp, $pp,
                $pp, [$yesterday],
                $pp, $pp, $pp
            )
        ) ?: [];
        $stats = [
            'farms' => (int) ($statsRow['farms'] ?? 0),
            'acreage' => (float) ($statsRow['acreage'] ?? 0),
            'fields' => (int) ($statsRow['fields'] ?? 0),
            'active_crops' => (int) ($statsRow['active_crops'] ?? 0),
            'crops_week' => (int) ($statsRow['crops_week'] ?? 0),
            'livestock' => (int) ($statsRow['livestock'] ?? 0),
            'livestock_week' => (int) ($statsRow['livestock_week'] ?? 0),
            'pending_tasks' => (int) ($statsRow['pending_tasks'] ?? 0),
            'tasks_total' => (int) ($statsRow['tasks_total'] ?? 0),
            'tasks_yesterday' => (int) ($statsRow['tasks_yesterday'] ?? 0),
            'irrigation_systems' => (int) ($statsRow['irrigation_systems'] ?? 0),
            'irrigation_active' => (int) ($statsRow['irrigation_active'] ?? 0),
        ];
        $irrigationUpdated = $statsRow['irrigation_updated'] ?? null;
        $livestockMix = db()->select("SELECT species, COUNT(*) AS total FROM animals " . $scope['plain']['sql'] . " AND status = 'active' GROUP BY species ORDER BY total DESC LIMIT 4", $scope['plain']['params']);

        $todayTasks = db()->select(
            'SELECT * FROM tasks ' . $scope['plain']['sql'] . " AND status != 'completed' AND (due_date IS NULL OR due_date <= ?) ORDER BY due_date ASC, id ASC LIMIT 6",
            array_merge($scope['plain']['params'], [$today])
        );
        $alerts = NotificationService::latest(5);
        $weather = $farm
            ? db()->first('SELECT * FROM weather_logs WHERE farm_id = ? ORDER BY log_date DESC, id DESC', [$farm['id']])
            : null;
        $daily = $forecast['daily'] ?? [];
        $currentWx = $forecast['current'] ?? [];
        $tips = ($forecast && $farm) ? WeatherService::recommendations($forecast, $farm) : [];

        $activity = [];
        if (Auth::can('audit_logs', 'view')) {
            $sql = 'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE 1=1';
            $params = [];
            if (Auth::farmId()) {
                $sql .= ' AND (a.farm_id = ? OR a.farm_id IS NULL)';
                $params[] = Auth::farmId();
            }
            $activity = db()->select($sql . ' ORDER BY a.id DESC LIMIT 6', $params);
        }

        $healthDays = (int) ($_GET['days'] ?? 7);
        if (!in_array($healthDays, [7, 14, 30], true)) {
            $healthDays = 7;
        }
        $health = self::cropHealthSeries($scope, $healthDays);
        $farmPhoto = self::farmPhoto($farm);
        if (!$activity) {
            $activity = self::operationalActivity($scope);
        }
        $wxUpdated = $currentWx['time'] ?? null;
        if (!$wxUpdated && is_array($weather)) {
            $wxUpdated = $weather['updated_at'] ?? $weather['created_at'] ?? null;
        }

        view('layouts/app', [
            'title' => 'Farm Dashboard',
            'content' => render('dashboard', compact(
                'stats', 'livestockMix', 'todayTasks', 'alerts', 'weather', 'daily', 'currentWx',
                'tips', 'farm', 'activity', 'health', 'irrigationUpdated', 'farmPhoto', 'healthDays', 'wxUpdated'
            )),
        ]);
    }

    public static function completeTask($id): void
    {
        Auth::authorize('tasks', 'edit');
        Csrf::verify();
        $task = db()->find('tasks', $id);
        if (!$task || !Auth::canAccessRecord($task)) {
            abort(404, 'Task not found.');
        }
        $done = (string) input('done') === '1';
        db()->update('tasks', ['status' => $done ? 'completed' : 'pending'], $id);
        Audit::log('update', 'tasks', (int) $id, $done ? 'Task marked complete from dashboard' : 'Task reopened from dashboard');
        redirect(safe_return_path('dashboard'));
    }

    private static function cropHealthSeries(array $scope, int $days = 7): array
    {
        $from = date('Y-m-d', strtotime('-' . $days . ' days'));
        $farmAnd = preg_replace('/^WHERE /', 'AND ', (string) $scope['plain']['sql']);
        $farmAnd = str_replace('farm_id', 'g.farm_id', $farmAnd);
        try {
            $rows = db()->select(
                "SELECT g.record_date, c.name AS crop_name, g.health_status, g.height_cm
                 FROM crop_growth g
                 LEFT JOIN plantings p ON p.id = g.planting_id
                 LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
                 WHERE g.record_date >= ? {$farmAnd}
                 ORDER BY g.record_date ASC",
                array_merge([$from], $scope['plain']['params'])
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        $score = ['healthy' => 86, 'stressed' => 58, 'diseased' => 32];
        $byCrop = [];
        $dates = [];
        foreach ($rows as $row) {
            $crop = $row['crop_name'] ?: 'Crop';
            $date = $row['record_date'];
            $dates[$date] = true;
            $value = $score[$row['health_status'] ?? ''] ?? 70;
            if (!empty($row['height_cm'])) {
                $value = min(100, $value + min(12, (float) $row['height_cm'] / 20));
            }
            $byCrop[$crop][$date] = round($value, 1);
        }
        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = date('Y-m-d', strtotime("-{$i} days"));
        }
        if ($byCrop) {
            $series = [];
            $i = 0;
            foreach ($byCrop as $crop => $points) {
                if ($i >= 3) {
                    break;
                }
                $data = [];
                $last = null;
                foreach ($labels as $day) {
                    if (isset($points[$day])) {
                        $last = $points[$day];
                    }
                    $data[] = $last;
                }
                $series[] = ['label' => $crop, 'data' => $data];
                $i++;
            }
            return ['title' => 'Crop health trend', 'unit' => 'Health index', 'labels' => $labels, 'series' => $series, 'type' => 'line', 'href' => url('module/crop_growth')];
        }

        $farmAndH = preg_replace('/^WHERE /', 'AND ', (string) $scope['plain']['sql']);
        $farmAndH = str_replace('farm_id', 'h.farm_id', $farmAndH);
        try {
            $harvests = db()->select(
                "SELECT h.harvest_date, COALESCE(c.name, 'Harvest') AS crop_name, SUM(h.quantity) AS qty
                 FROM harvests h
                 LEFT JOIN plantings p ON p.id = h.planting_id
                 LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
                 WHERE h.harvest_date >= ? {$farmAndH}
                 GROUP BY h.harvest_date, c.name
                 ORDER BY h.harvest_date",
                array_merge([$from], $scope['plain']['params'])
            );
        } catch (Throwable $e) {
            $harvests = [];
        }
        if ($harvests) {
            $names = [];
            foreach ($harvests as $row) {
                $crop = $row['crop_name'] ?: 'Harvest';
                $names[$crop][$row['harvest_date']] = (float) $row['qty'];
            }
            $series = [];
            $i = 0;
            foreach ($names as $crop => $points) {
                if ($i >= 3) {
                    break;
                }
                $data = [];
                foreach ($labels as $day) {
                    $data[] = $points[$day] ?? 0;
                }
                $series[] = ['label' => $crop, 'data' => $data];
                $i++;
            }
            return ['title' => 'Harvest volume', 'unit' => 'Quantity', 'labels' => $labels, 'series' => $series, 'type' => 'line', 'href' => url('module/harvests')];
        }

        $stages = db()->select("SELECT growth_stage AS label, COUNT(*) AS total FROM plantings " . $scope['plain']['sql'] . " GROUP BY growth_stage", $scope['plain']['params']);
        return [
            'title' => 'Current crop stages',
            'unit' => 'Plantings',
            'labels' => array_map(fn($r) => $r['label'] ?: 'n/a', $stages),
            'series' => [['label' => 'Plantings', 'data' => array_map(fn($r) => (int) $r['total'], $stages)]],
            'type' => 'bar',
            'href' => url('module/plantings'),
        ];
    }

    private static function farmPhoto(?array $farm): string
    {
        if ($farm) {
            foreach (AttachmentService::for('farms', (int) $farm['id']) as $att) {
                if (AttachmentService::isImage($att)) {
                    return url('module/farms/file/' . $att['id']);
                }
            }
        }
        $fallback = (string) config('farm_photo');
        if ($fallback === '') {
            return asset('img/farm-overview.jpg');
        }
        if (str_starts_with($fallback, 'http://') || str_starts_with($fallback, 'https://')) {
            return $fallback;
        }
        return asset($fallback);
    }

    private static function operationalActivity(array $scope): array
    {
        $rows = [];
        foreach (db()->select('SELECT id, title, created_at, updated_at FROM tasks ' . $scope['plain']['sql'] . ' ORDER BY id DESC LIMIT 4', $scope['plain']['params']) as $row) {
            $rows[] = [
                'module' => 'tasks',
                'record_id' => $row['id'],
                'details' => 'Task recorded: ' . $row['title'],
                'created_at' => $row['updated_at'] ?: $row['created_at'],
                'user_name' => '',
            ];
        }
        foreach (db()->select('SELECT id, created_at FROM plantings ' . $scope['plain']['sql'] . ' ORDER BY id DESC LIMIT 3', $scope['plain']['params']) as $row) {
            $rows[] = [
                'module' => 'plantings',
                'record_id' => $row['id'],
                'details' => 'Planting record updated',
                'created_at' => $row['created_at'],
                'user_name' => '',
            ];
        }
        foreach (db()->select('SELECT id, harvest_date, created_at FROM harvests ' . $scope['plain']['sql'] . ' ORDER BY id DESC LIMIT 3', $scope['plain']['params']) as $row) {
            $rows[] = [
                'module' => 'harvests',
                'record_id' => $row['id'],
                'details' => 'Harvest recorded',
                'created_at' => $row['created_at'] ?: $row['harvest_date'],
                'user_name' => '',
            ];
        }
        usort($rows, static fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
        return array_slice($rows, 0, 6);
    }

    private static function farm(): ?array
    {
        if (Auth::farmId()) {
            return db()->find('farms', Auth::farmId());
        }
        if (Auth::isAdmin()) {
            return db()->first('SELECT * FROM farms ORDER BY id ASC');
        }
        return null;
    }

    private static function scope(): array
    {
        if (!Auth::farmId()) {
            if (Auth::isAdmin()) {
                return [
                    'farms' => ['sql' => 'WHERE 1=1', 'params' => []],
                    'plain' => ['sql' => 'WHERE 1=1', 'params' => []],
                ];
            }
            return [
                'farms' => ['sql' => 'WHERE 1=0', 'params' => []],
                'plain' => ['sql' => 'WHERE 1=0', 'params' => []],
            ];
        }
        $id = Auth::farmId();
        return [
            'farms' => ['sql' => 'WHERE id = ?', 'params' => [$id]],
            'plain' => ['sql' => 'WHERE farm_id = ?', 'params' => [$id]],
        ];
    }
}
