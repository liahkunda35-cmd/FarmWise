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
        [$taskSql, $taskParams] = TaskAccess::listConstraint();
        $statsRow = db()->first(
            "SELECT
                (SELECT COUNT(*) FROM farms {$fs}) AS farms,
                (SELECT COALESCE(SUM(total_area),0) FROM farms {$fs}) AS acreage,
                (SELECT COUNT(*) FROM fields {$ps}) AS fields,
                (SELECT COUNT(*) FROM plantings {$ps} AND status IN ('planted','growing')) AS active_crops,
                (SELECT COUNT(*) FROM plantings {$ps} AND CAST(created_at AS DATE) >= CAST(? AS DATE)) AS crops_week,
                (SELECT COUNT(*) FROM animals {$ps} AND status = 'active') AS livestock,
                (SELECT COUNT(*) FROM animals {$ps} AND CAST(created_at AS DATE) >= CAST(? AS DATE)) AS livestock_week,
                (SELECT COUNT(*) FROM tasks {$ps}{$taskSql} AND status != 'completed') AS pending_tasks,
                (SELECT COUNT(*) FROM tasks {$ps}{$taskSql}) AS tasks_total,
                (SELECT COUNT(*) FROM tasks {$ps}{$taskSql} AND status != 'completed' AND CAST(created_at AS DATE) <= CAST(? AS DATE)) AS tasks_yesterday,
                (SELECT COUNT(*) FROM irrigation_systems {$ps}) AS irrigation_systems,
                (SELECT COUNT(*) FROM irrigation_systems {$ps} AND status = 'active') AS irrigation_active,
                (SELECT MAX(updated_at) FROM irrigation_systems {$ps}) AS irrigation_updated",
            array_merge(
                $fp, $fp,
                $pp, $pp,
                $pp, [$weekAgo],
                $pp,
                $pp, [$weekAgo],
                $pp, $taskParams,
                $pp, $taskParams,
                $pp, $taskParams, [$yesterday],
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
            'SELECT * FROM tasks ' . $scope['plain']['sql'] . $taskSql . " AND status != 'completed' AND (due_date IS NULL OR due_date <= ?) ORDER BY due_date ASC, id ASC LIMIT 6",
            array_merge($scope['plain']['params'], $taskParams, [$today])
        );
        $alerts = self::visibleAlerts(NotificationService::latest(8));
        $focus = self::focus($scope, $stats, $taskSql, $taskParams);
        $weather = $farm
            ? db()->first('SELECT * FROM weather_logs WHERE farm_id = ? ORDER BY log_date DESC, id DESC', [$farm['id']])
            : null;
        $daily = $forecast['daily'] ?? [];
        $currentWx = $forecast['current'] ?? [];
        $wxMeta = is_array($forecast['meta'] ?? null) ? $forecast['meta'] : [];
        $tips = ($forecast && $farm && !empty($forecast['current'])) ? WeatherService::recommendations($forecast, $farm) : [];

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
        $health = !empty($focus['show_crops'])
            ? self::cropHealthSeries($scope, $healthDays)
            : ['title' => '', 'labels' => [], 'series' => [], 'insufficient' => true, 'note' => '', 'href' => url('dashboard')];
        $farmPhoto = self::farmPhoto($farm);
        if (!$activity) {
            $activity = self::operationalActivity($scope, $taskSql, $taskParams);
        }
        $wxUpdated = $currentWx['time'] ?? null;
        if (!$wxUpdated && is_array($weather)) {
            $wxUpdated = $weather['updated_at'] ?? $weather['created_at'] ?? null;
        }

        view('layouts/app', [
            'title' => 'Farm Dashboard',
            'content' => render('dashboard', compact(
                'stats', 'livestockMix', 'todayTasks', 'alerts', 'weather', 'daily', 'currentWx', 'wxMeta',
                'tips', 'farm', 'activity', 'health', 'irrigationUpdated', 'farmPhoto', 'healthDays', 'wxUpdated',
                'focus'
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
        if (!TaskAccess::workerCanAccessTask($task)) {
            abort(403, 'You can only update tasks assigned to you.');
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
        // Data-driven scores from recorded health_status only (no fabricated constants as "facts")
        $score = ['healthy' => 100, 'stressed' => 50, 'diseased' => 20];
        $byCrop = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row['health_status'] ?? '')));
            if ($status === '' || !isset($score[$status])) {
                continue; // skip unknown — do not invent a mid-point
            }
            $crop = $row['crop_name'] ?: 'Crop';
            $date = $row['record_date'];
            $value = $score[$status];
            // Optional height as secondary signal only when status is known
            if (!empty($row['height_cm']) && $status === 'healthy') {
                $value = min(100, $value);
            }
            $byCrop[$crop][$date] = $value;
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
                foreach ($labels as $day) {
                    // null = no observation that day (chart may gap); do not carry-forward invented values
                    $data[] = $points[$day] ?? null;
                }
                $series[] = ['label' => $crop, 'data' => $data];
                $i++;
            }
            return [
                'title' => 'Crop health (from field observations)',
                'unit' => 'Score (100 healthy · 50 stressed · 20 diseased)',
                'labels' => $labels,
                'series' => $series,
                'type' => 'line',
                'href' => url('module/crop_growth'),
                'note' => 'Only days with a logged health status are scored. Gaps mean no observation.',
            ];
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
        if ($stages) {
            return [
                'title' => 'Current crop stages (counts, not a health score)',
                'unit' => 'Plantings',
                'labels' => array_map(fn($r) => $r['label'] ?: 'n/a', $stages),
                'series' => [['label' => 'Plantings', 'data' => array_map(fn($r) => (int) $r['total'], $stages)]],
                'type' => 'bar',
                'href' => url('module/plantings'),
                'note' => 'Insufficient recent health observations — showing planting stage counts instead.',
            ];
        }
        return [
            'title' => 'Crop health',
            'unit' => '',
            'labels' => [],
            'series' => [],
            'type' => 'line',
            'href' => url('module/crop_growth'),
            'note' => 'Insufficient data: log crop growth health status to see a trend.',
            'insufficient' => true,
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

    private static function operationalActivity(array $scope, string $taskSql = '', array $taskParams = []): array
    {
        $rows = [];
        if (Auth::can('audit_logs', 'view')) {
            foreach (db()->select('SELECT id, action, module, record_id, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 6') as $row) {
                $rows[] = [
                    'module' => (string) ($row['module'] ?? 'audit_logs'),
                    'record_id' => $row['record_id'],
                    'action' => $row['action'],
                    'details' => (string) ($row['details'] ?: ucfirst((string) $row['action'])),
                    'created_at' => $row['created_at'],
                    'user_name' => '',
                ];
            }
        }
        if (Auth::can('tasks', 'view')) {
        foreach (db()->select('SELECT id, title, created_at, updated_at FROM tasks ' . $scope['plain']['sql'] . $taskSql . ' ORDER BY id DESC LIMIT 4', array_merge($scope['plain']['params'], $taskParams)) as $row) {
            $rows[] = [
                'module' => 'tasks',
                'record_id' => $row['id'],
                'details' => 'Task recorded: ' . $row['title'],
                'created_at' => $row['updated_at'] ?: $row['created_at'],
                'user_name' => '',
            ];
        }
        }
        if (Auth::can('plantings', 'view')) {
        foreach (db()->select('SELECT id, created_at FROM plantings ' . $scope['plain']['sql'] . ' ORDER BY id DESC LIMIT 3', $scope['plain']['params']) as $row) {
            $rows[] = [
                'module' => 'plantings',
                'record_id' => $row['id'],
                'details' => 'Planting record updated',
                'created_at' => $row['created_at'],
                'user_name' => '',
            ];
        }
        }
        if (Auth::can('transactions', 'view')) {
        foreach (db()->select('SELECT id, txn_date, type, amount, category, created_at FROM transactions ' . $scope['plain']['sql'] . ' ORDER BY id DESC LIMIT 4', $scope['plain']['params']) as $row) {
            $rows[] = [
                'module' => 'transactions',
                'record_id' => $row['id'],
                'details' => ucfirst((string) $row['type']) . ' · ' . ($row['category'] ?: 'ledger') . ' · ' . money($row['amount']),
                'created_at' => $row['created_at'] ?: $row['txn_date'],
                'user_name' => '',
            ];
        }
        }
        if (Auth::can('invoices', 'view')) {
        foreach (db()->select('SELECT id, invoice_number, status, amount, created_at FROM invoices ' . $scope['plain']['sql'] . ' ORDER BY id DESC LIMIT 3', $scope['plain']['params']) as $row) {
            $rows[] = [
                'module' => 'invoices',
                'record_id' => $row['id'],
                'details' => 'Invoice ' . ($row['invoice_number'] ?: ('#' . $row['id'])) . ' · ' . ($row['status'] ?: 'open'),
                'created_at' => $row['created_at'],
                'user_name' => '',
            ];
        }
        }
        if (Auth::can('harvests', 'view')) {
        foreach (db()->select('SELECT id, harvest_date, created_at FROM harvests ' . $scope['plain']['sql'] . ' ORDER BY id DESC LIMIT 3', $scope['plain']['params']) as $row) {
            $rows[] = [
                'module' => 'harvests',
                'record_id' => $row['id'],
                'details' => 'Harvest recorded',
                'created_at' => $row['created_at'] ?: $row['harvest_date'],
                'user_name' => '',
            ];
        }
        }
        usort($rows, static fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
        return array_slice($rows, 0, 6);
    }

    /** @param array<string,mixed> $scope @param array<string,int|float|string> $stats @param list<mixed> $taskParams @return array<string,mixed> */
    private static function focus(array $scope, array $stats, string $taskSql, array $taskParams): array
    {
        $role = (string) Auth::role();
        $ps = $scope['plain']['sql'];
        $pp = $scope['plain']['params'];
        $card = static function (string $href, string $icon, string $label, string $value): array {
            return ['href' => $href, 'icon' => $icon, 'label' => $label, 'value' => $value];
        };
        $focus = [
            'line1' => 'Healthy Crops,',
            'line2' => 'Greater Harvests',
            'lede' => 'Track, manage and grow your farm with smart insights and real-time data.',
            'banner_href' => Auth::can('fields', 'view') ? url('map') : url('dashboard'),
            'banner_label' => Auth::can('fields', 'view') ? 'Explore your farm →' : 'Continue →',
            'show_weather' => Auth::can('weather', 'view') && $role !== 'admin',
            'show_crops' => in_array($role, ['owner', 'manager', 'agronomist'], true),
            'show_map' => Auth::can('fields', 'view') && $role !== 'worker',
            'cards' => [],
        ];
        if ($role === 'admin') {
            $pending = (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
            $activeUsers = (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'active'");
            $focus['line1'] = 'Platform';
            $focus['line2'] = 'Administration';
            $focus['lede'] = 'Review account approvals, farm access and recent system activity.';
            $focus['banner_href'] = url('users');
            $focus['banner_label'] = 'Review accounts →';
            $focus['show_map'] = false;
            $focus['cards'] = [
                $card(url('users'), 'user-check', 'Accounts awaiting approval', (string) $pending),
                $card(url('users'), 'users', 'Active accounts', (string) $activeUsers),
                $card(url('module/farms'), 'land-plot', 'Farms on the platform', (string) (int) $stats['farms']),
                $card(url('audit'), 'scroll-text', 'Audit log', 'Review'),
            ];
            return $focus;
        }
        if ($role === 'worker') {
            $due = (int) db()->value(
                'SELECT COUNT(*) FROM tasks ' . $ps . $taskSql . " AND status != 'completed' AND due_date IS NOT NULL AND due_date <= ?",
                array_merge($pp, $taskParams, [date('Y-m-d')])
            );
            $focus['line1'] = 'Your tasks,';
            $focus['line2'] = 'Ready for today';
            $focus['lede'] = 'Only work assigned to you is shown here.';
            $focus['banner_href'] = url('module/tasks');
            $focus['banner_label'] = 'Open my tasks →';
            $focus['cards'] = [
                $card(url('module/tasks'), 'clipboard-list', 'My open tasks', (string) (int) $stats['pending_tasks']),
                $card(url('module/tasks'), 'calendar-days', 'Due today or overdue', (string) $due),
                $card(url('module/attendance'), 'clipboard-check', 'Attendance', 'Record'),
            ];
            if (Auth::can('pest_scouting', 'create')) {
                $focus['cards'][] = $card(url('module/pest_scouting/create'), 'bug', 'Pest observation', 'Add');
            }
            return $focus;
        }
        if ($role === 'accountant') {
            $month = date('Y-m-01');
            $incomeRows = (int) db()->value("SELECT COUNT(*) FROM transactions {$ps} AND type = 'income' AND txn_date >= ?", array_merge($pp, [$month]));
            $expenseRows = (int) db()->value("SELECT COUNT(*) FROM transactions {$ps} AND type = 'expense' AND txn_date >= ?", array_merge($pp, [$month]));
            $income = $incomeRows
                ? money((float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions {$ps} AND type = 'income' AND txn_date >= ?", array_merge($pp, [$month])))
                : 'No rows';
            $expense = $expenseRows
                ? money((float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions {$ps} AND type = 'expense' AND txn_date >= ?", array_merge($pp, [$month])))
                : 'No rows';
            $unpaid = (int) db()->value("SELECT COUNT(*) FROM invoices {$ps} AND status IN ('unpaid','partial','overdue')", $pp);
            $focus['line1'] = 'Cash position,';
            $focus['line2'] = 'This month';
            $focus['lede'] = 'Income and expenses are posted cash records. "No rows" means nothing was recorded, not a confirmed zero.';
            $focus['banner_href'] = url('reports?type=budget');
            $focus['banner_label'] = 'Budget versus actual →';
            $focus['cards'] = [
                $card(url('module/transactions'), 'trending-up', 'Income this month', $income),
                $card(url('module/transactions'), 'trending-down', 'Expenses this month', $expense),
                $card(url('module/invoices'), 'file-text', 'Unpaid invoices', (string) $unpaid),
                $card(url('reports?type=budget'), 'scale', 'Budget versus actual', 'Open'),
            ];
            return $focus;
        }
        if ($role === 'agronomist') {
            $openScout = (int) db()->value(
                "SELECT COUNT(*) FROM pest_scouting {$ps} AND severity IN ('high','critical') AND COALESCE(status,'') <> 'resolved'",
                $pp
            );
            $focus['line1'] = 'Crop health,';
            $focus['line2'] = 'Field by field';
            $focus['lede'] = 'Scouting, planting progress and weather for your authorised farm.';
            $focus['banner_href'] = url('module/pest_scouting');
            $focus['banner_label'] = 'Open scouting →';
            $focus['cards'] = [
                $card(url('module/pest_scouting'), 'bug', 'High or critical scouting', (string) $openScout),
                $card(url('module/plantings'), 'leaf', 'Active crops', (string) (int) $stats['active_crops']),
                $card(url('module/fields'), 'sprout', 'Fields', (string) (int) $stats['fields']),
                $card(url('forecasts'), 'line-chart', 'Yield forecasts', 'Open'),
            ];
            return $focus;
        }
        if ($role === 'manager') {
            $lowStock = (int) db()->value(
                "SELECT COUNT(*) FROM inventory_items {$ps} AND reorder_level IS NOT NULL AND reorder_level > 0 AND quantity <= reorder_level",
                $pp
            );
            $irrigation = (int) db()->value(
                "SELECT COUNT(*) FROM irrigation_schedules {$ps} AND status = 'scheduled' AND scheduled_date >= ?",
                array_merge($pp, [date('Y-m-d')])
            );
            $focus['line1'] = "Today's";
            $focus['line2'] = 'Farm operations';
            $focus['lede'] = 'Tasks, labour, inventory, planting and irrigation for this farm.';
            $focus['banner_href'] = url('module/tasks');
            $focus['banner_label'] = 'Review tasks →';
            $focus['cards'] = [
                $card(url('module/tasks'), 'clipboard-list', 'Open tasks', (string) (int) $stats['pending_tasks']),
                $card(url('module/inventory_items'), 'package', 'Items at reorder level', (string) $lowStock),
                $card(url('module/plantings'), 'leaf', 'Active crops', (string) (int) $stats['active_crops']),
                $card(url('module/irrigation_schedules'), 'droplets', 'Irrigation still scheduled', (string) $irrigation),
            ];
            return $focus;
        }
        $month = date('Y-m-01');
        $incomeRows = (int) db()->value("SELECT COUNT(*) FROM transactions {$ps} AND type = 'income' AND txn_date >= ?", array_merge($pp, [$month]));
        $expenseRows = (int) db()->value("SELECT COUNT(*) FROM transactions {$ps} AND type = 'expense' AND txn_date >= ?", array_merge($pp, [$month]));
        $income = $incomeRows
            ? money((float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions {$ps} AND type = 'income' AND txn_date >= ?", array_merge($pp, [$month])))
            : 'No rows';
        $expense = $expenseRows
            ? money((float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions {$ps} AND type = 'expense' AND txn_date >= ?", array_merge($pp, [$month])))
            : 'No rows';
        $focus['cards'] = [
            $card(url('module/fields'), 'sprout', 'Fields', (string) (int) $stats['fields']),
            $card(url('module/plantings'), 'leaf', 'Active crops', (string) (int) $stats['active_crops']),
            $card(url('module/transactions'), 'trending-up', 'Income this month', $income),
            $card(url('module/transactions'), 'trending-down', 'Expenses this month', $expense),
        ];
        return $focus;
    }

    /** @param list<array<string,mixed>> $alerts @return list<array<string,mixed>> */
    private static function visibleAlerts(array $alerts): array
    {
        $out = [];
        foreach ($alerts as $alert) {
            $link = (string) ($alert['link'] ?? '');
            if (preg_match('#module/([a-z0-9_]+)#', $link, $match) && !Auth::can($match[1], 'view')) {
                continue;
            }
            if ($link !== '' && !str_contains($link, 'module/') && (str_starts_with($link, 'audit') || str_starts_with($link, 'backup') || str_starts_with($link, 'integrations')) && !Auth::can('audit_logs', 'view') && !Auth::isAdmin()) {
                continue;
            }
            $out[] = $alert;
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
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
