<?php

class AppPagesController
{
    public static function weather(): void
    {
        Auth::authorize('weather', 'view');
        $farm = self::currentFarm();
        $forecast = null;
        $locateNeeded = false;
        $lat = input('lat');
        $lon = input('lon');
        $hasQueryCoords = is_numeric($lat) && is_numeric($lon);

        try {
            if ($farm && !$hasQueryCoords) {
                $forecast = WeatherService::fetchForFarm($farm);
                // Farm exists but has no pin — fall through to device GPS
                if (!empty($forecast['meta']['error']) && ($forecast['meta']['error'] === 'missing_location')) {
                    $locateNeeded = true;
                }
            } elseif ($hasQueryCoords) {
                $forecast = WeatherService::fetchAt((float) $lat, (float) $lon, [
                    'label' => (string) input('label', ''),
                    'precision' => 'confirmed',
                ]);
            } else {
                $locateNeeded = true;
            }
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

        $history = $farm
            ? db()->select('SELECT * FROM weather_logs WHERE farm_id = ? ORDER BY log_date DESC LIMIT 14', [$farm['id']])
            : [];
        $tips = ($forecast && !empty($forecast['current']))
            ? WeatherService::recommendations($forecast, $farm ?: ['name' => 'Your location'])
            : [];
        view('layouts/app', [
            'title' => 'Weather',
            'content' => render('weather', compact('farm', 'forecast', 'history', 'tips', 'locateNeeded')),
        ]);
    }

    public static function notifications(): void
    {
        Auth::authorize('notifications', 'view');
        NotificationService::syncAlerts(true);
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
            'title' => 'Alerts',
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

    public static function dismissNotification($id): void
    {
        Auth::authorize('notifications', 'view');
        Csrf::verify();
        $row = self::ownedNotification($id);
        db()->delete('notifications', $row['id']);
        flash('success', 'Alert dismissed.');
        redirect('notifications' . (input('filter') ? ('?filter=' . urlencode((string) input('filter'))) : ''));
    }

    private static function notificationWhere(): array
    {
        $sql = '1=1';
        $params = [];
        if (Auth::isAdmin()) {
            $sql .= ' AND (user_id IS NULL OR user_id = ?)';
            $params[] = Auth::id();
        } elseif (Auth::farmId()) {
            // Always show alerts aimed at me, plus farm-wide alerts for my farm.
            $sql .= ' AND (user_id = ? OR (user_id IS NULL AND (farm_id = ? OR farm_id IS NULL)))';
            $params[] = Auth::id();
            $params[] = Auth::farmId();
        } else {
            $sql .= ' AND user_id = ?';
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
        Schema::ensureMarketAndAgriSchema(db());

        if (is_post() && Auth::isAdmin()) {
            Csrf::check();
            $action = (string) input('action');
            try {
                if ($action === 'sync_hdx') {
                    $months = max(6, min(120, (int) (input('months_back') ?: 36)));
                    $local = null;
                    if (!empty($_FILES['hdx_file']['tmp_name']) && is_uploaded_file($_FILES['hdx_file']['tmp_name'])) {
                        $ext = strtolower(pathinfo((string) $_FILES['hdx_file']['name'], PATHINFO_EXTENSION));
                        if ($ext !== 'csv') {
                            throw new RuntimeException('Upload the HDX .csv file.');
                        }
                        $dest = dirname(__DIR__, 2) . '/storage/uploads/hdx-' . date('YmdHis') . '.csv';
                        @mkdir(dirname($dest), 0775, true);
                        if (!move_uploaded_file($_FILES['hdx_file']['tmp_name'], $dest)) {
                            throw new RuntimeException('HDX upload failed.');
                        }
                        $local = $dest;
                    }
                    $result = MarketPriceSync::syncHdx($local, $months);
                    flash('success', $result['message']);
                } elseif ($action === 'import_bulletin') {
                    $row = [
                        'crop_name' => input('crop_name'),
                        'market' => input('market'),
                        'price_date' => input('price_date'),
                        'price' => input('price'),
                        'unit' => input('unit') ?: 'KG',
                        'province' => input('province'),
                        'district' => input('district'),
                        'demand_level' => input('demand_level') ?: 'medium',
                        'notes' => input('notes'),
                    ];
                    $result = MarketPriceSync::importBulletinRows([$row], Auth::id());
                    flash('success', $result['message']);
                }
            } catch (Throwable $e) {
                flash('error', 'Import failed: ' . $e->getMessage());
            }
            redirect('market-insights');
        }

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

        $filterCrop = trim((string) input('crop'));
        $filterSource = trim((string) input('source'));
        $filterProvince = trim((string) input('province'));
        if ($filterCrop !== '') {
            $where .= ' AND lower(crop_name) LIKE ?';
            $params[] = '%' . mb_strtolower($filterCrop) . '%';
        }
        if ($filterSource !== '') {
            $where .= ' AND source = ?';
            $params[] = $filterSource;
        }
        if ($filterProvince !== '') {
            $where .= ' AND lower(COALESCE(province,\'\')) LIKE ?';
            $params[] = '%' . mb_strtolower($filterProvince) . '%';
        }

        $prices = db()->select(
            "SELECT * FROM market_prices WHERE {$where} ORDER BY price_date DESC, id DESC LIMIT 80",
            $params
        );
        $crops = [];
        foreach (db()->select(
            "SELECT crop_name, AVG(NULLIF(price_per_kg,0)) AS avg_price, MAX(price_per_kg) AS high_price, MIN(NULLIF(price_per_kg,0)) AS low_price FROM market_prices WHERE {$where} GROUP BY crop_name ORDER BY crop_name LIMIT 40",
            $params
        ) as $row) {
            $latestParams = $params;
            $latestSql = "SELECT * FROM market_prices WHERE crop_name = ? AND {$where} ORDER BY price_date DESC, id DESC";
            array_unshift($latestParams, $row['crop_name']);
            $latest = db()->first($latestSql, $latestParams);
            $mom = null;
            if ($latest && !empty($latest['price_date'])) {
                $prior = db()->first(
                    "SELECT price_per_kg, price_date FROM market_prices
                     WHERE crop_name = ? AND {$where} AND price_date < ? AND price_per_kg IS NOT NULL AND price_per_kg > 0
                     ORDER BY price_date DESC LIMIT 1",
                    array_merge([$row['crop_name']], $params, [$latest['price_date']])
                );
                if ($prior && (float) ($prior['price_per_kg'] ?? 0) > 0 && (float) ($latest['price_per_kg'] ?? 0) > 0) {
                    $mom = round((((float) $latest['price_per_kg'] - (float) $prior['price_per_kg']) / (float) $prior['price_per_kg']) * 100, 1);
                }
            }
            $crops[] = array_merge($row, ['latest' => $latest, 'mom_pct' => $mom]);
        }

        $trendCrop = $filterCrop !== '' ? $filterCrop : (string) ($crops[0]['crop_name'] ?? 'Maize');
        $trendRows = db()->select(
            "SELECT price_date, AVG(NULLIF(price_per_kg,0)) AS avg_price
             FROM market_prices WHERE {$where} AND lower(crop_name) LIKE ?
             GROUP BY price_date ORDER BY price_date ASC LIMIT 48",
            array_merge($params, ['%' . mb_strtolower($trendCrop) . '%'])
        );
        $chart = [
            'labels' => array_map(static fn ($r) => $r['price_date'], $trendRows),
            'values' => array_map(static fn ($r) => $r['avg_price'] !== null ? (float) $r['avg_price'] : null, $trendRows),
            'crop' => $trendCrop,
        ];
        $sources = db()->select("SELECT DISTINCT source FROM market_prices WHERE source IS NOT NULL AND source <> '' ORDER BY source");
        $isAdmin = Auth::isAdmin();

        view('layouts/app', [
            'title' => 'Market Insights',
            'content' => render('market_insights', compact('prices', 'crops', 'chart', 'sources', 'filterCrop', 'filterSource', 'filterProvince', 'isAdmin')),
        ]);
    }

    public static function agriHistory(): void
    {
        Auth::authorize('agri_history', 'view');
        Schema::ensureMarketAndAgriSchema(db());

        if (is_post() && Auth::isAdmin()) {
            Csrf::check();
            $action = (string) input('action');
            try {
                if ($action === 'import_cfs') {
                    $path = null;
                    if (!empty($_FILES['cfs_file']['tmp_name']) && is_uploaded_file($_FILES['cfs_file']['tmp_name'])) {
                        $ext = strtolower(pathinfo((string) $_FILES['cfs_file']['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, ['xlsx', 'csv'], true)) {
                            throw new RuntimeException('Upload a published CFS .xlsx or aggregate .csv only.');
                        }
                        $dest = dirname(__DIR__, 2) . '/storage/uploads/cfs-' . date('YmdHis') . '.' . $ext;
                        @mkdir(dirname($dest), 0775, true);
                        if (!move_uploaded_file($_FILES['cfs_file']['tmp_name'], $dest)) {
                            throw new RuntimeException('Upload failed.');
                        }
                        $path = $dest;
                    } elseif (input('use_repo_file')) {
                        $path = dirname(__DIR__, 2) . '/Crop-Forecast-Survey-2025_2026.xlsx';
                    }
                    if (!$path) {
                        throw new RuntimeException('Choose a CFS file or use the repository Excel.');
                    }
                    $kind = (string) (input('kind') ?: 'crop');
                    $meta = [
                        'title' => input('title') ?: null,
                        'season_label' => input('season_label') ?: null,
                        'source_url' => input('source_url') ?: 'https://www.zamstats.gov.zm/agriculture-and-environment/',
                        'imported_by' => Auth::id(),
                        'kind' => $kind,
                    ];
                    $result = ZamstatsAgriImport::importFile($path, $meta);
                    flash('success', $result['message']);
                }
            } catch (Throwable $e) {
                flash('error', 'CFS import failed: ' . $e->getMessage());
            }
            redirect('agri-history');
        }

        $crop = trim((string) input('crop')) ?: 'Maize (for grain)';
        $province = trim((string) input('province'));
        $season = trim((string) input('season'));
        $farm = self::currentFarm();
        if ($province === '' && $farm) {
            $province = (string) ($farm['province'] ?? '');
        }
        $provinceNorm = $province !== '' ? ZamstatsAgriImport::normalizeProvince($province) : null;

        $trend = AgriPatternService::cropTrend($crop, $provinceNorm && !in_array($provinceNorm, ['National', 'Total'], true) ? $provinceNorm : null);
        $comparison = AgriPatternService::provincialComparison($crop, $season !== '' ? $season : null);
        $recommendations = AgriPatternService::recommendForProvince($provinceNorm);
        $farmCompare = $farm ? AgriPatternService::farmVersusProvince((int) $farm['id']) : [];
        $datasets = db()->select('SELECT * FROM zamstats_datasets ORDER BY id DESC LIMIT 20');
        $cropOptions = AgriPatternService::crops();
        $provinceOptions = AgriPatternService::provinces();
        $seasonOptions = AgriPatternService::seasons();
        $rainfallCount = (int) db()->value('SELECT COUNT(*) FROM zamstats_rainfall');
        $livestockCount = (int) db()->value('SELECT COUNT(*) FROM zamstats_livestock');
        $statCount = (int) db()->value('SELECT COUNT(*) FROM zamstats_crop_stats');
        $isAdmin = Auth::isAdmin();

        view('layouts/app', [
            'title' => 'Agricultural History',
            'content' => render('agri_history', compact(
                'crop', 'province', 'season', 'trend', 'comparison', 'recommendations', 'farmCompare',
                'datasets', 'cropOptions', 'provinceOptions', 'seasonOptions',
                'rainfallCount', 'livestockCount', 'statCount', 'isAdmin', 'farm'
            )),
        ]);
    }

    public static function users(): void
    {
        Auth::authorize('users', 'view');
        $sql = 'SELECT u.*, f.name AS farm_name FROM users u LEFT JOIN farms f ON f.id = u.farm_id WHERE 1=1';
        $params = [];
        // Admins see every account (dashboard active-user count). Non-admins stay farm-scoped.
        if (!Auth::isAdmin()) {
            $sql .= ' AND u.role != ? AND u.status != ?';
            $params[] = 'admin';
            $params[] = 'rejected';
            if (Auth::farmId()) {
                $sql .= ' AND u.farm_id = ?';
                $params[] = Auth::farmId();
            } else {
                $sql .= ' AND 1=0';
            }
        }
        $q = trim((string) input('q'));
        $filterRole = trim((string) input('role'));
        $filterStatus = trim((string) input('status'));
        if ($q !== '') {
            $sql .= ' AND (lower(u.name) LIKE ? OR lower(u.email) LIKE ? OR lower(COALESCE(u.username, \'\')) LIKE ?)';
            $like = '%' . mb_strtolower($q) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($filterRole !== '' && array_key_exists($filterRole, Auth::roles())) {
            $sql .= ' AND u.role = ?';
            $params[] = $filterRole;
        }
        if ($filterStatus !== '' && in_array($filterStatus, ['pending', 'approved', 'active', 'rejected', 'suspended', 'inactive'], true)) {
            $sql .= ' AND u.status = ?';
            $params[] = $filterStatus;
        }
        $rows = db()->select(
            str_replace(
                'SELECT u.*, f.name AS farm_name',
                'SELECT u.id, u.name, u.username, u.email, u.role, u.requested_role, u.status, u.last_login, u.created_at, u.farm_id, u.must_change_password, f.name AS farm_name',
                $sql
            ) . ' ORDER BY CASE u.status WHEN \'pending\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END, u.id DESC',
            $params
        );
        $farms = farm_options();
        $pendingSql = "SELECT id, name, username, email, role, requested_role, status, farm_id, created_at, must_change_password FROM users WHERE status = 'pending'";
        $pendingParams = [];
        if (!Auth::isAdmin()) {
            $pendingSql .= ' AND role != ?';
            $pendingParams[] = 'admin';
            if (Auth::farmId()) {
                $pendingSql .= ' AND farm_id = ?';
                $pendingParams[] = Auth::farmId();
            } else {
                $pendingSql .= ' AND 1=0';
            }
        }
        $pendingSql .= ' ORDER BY created_at ASC, id ASC LIMIT 20';
        $pendingUsers = db()->select($pendingSql, $pendingParams);
        $pendingCount = count($pendingUsers);
        if (Auth::isAdmin()) {
            $pendingCount = (int) db()->value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        } elseif (Auth::farmId()) {
            $pendingCount = (int) db()->value(
                "SELECT COUNT(*) FROM users WHERE status = 'pending' AND role != 'admin' AND farm_id = ?",
                [Auth::farmId()]
            );
        } else {
            $pendingCount = 0;
        }
        $directory = fe_user_directory_summary();
        $perms = require dirname(__DIR__) . '/config/permissions.php';
        $moduleTitles = [];
        foreach (all_modules() as $key => $moduleDef) {
            $moduleTitles[$key] = $moduleDef['title'] ?? $key;
        }
        view('layouts/app', [
            'title' => 'User Management',
            'content' => render('admin/users', compact('rows', 'farms', 'pendingCount', 'pendingUsers', 'directory', 'perms', 'moduleTitles')),
        ]);
    }

    public static function pendingRegistration($id): void
    {
        Auth::authorize('users', 'edit');
        $pendingUser = db()->find('users', $id);
        if (!$pendingUser || !RegistrationService::canReview($pendingUser)) {
            abort(403, 'You cannot review this registration request.');
        }
        if (!$pendingUser || !in_array($pendingUser['status'], ['pending', 'approved', 'rejected', 'active'], true)) {
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
        $redirectTo = trim((string) input('redirect_to')) ?: 'users';
        $role = (string) input('role');
        $farmIds = input('farm_ids');
        if (!is_array($farmIds)) {
            $farmIds = input('farm_id') ? [input('farm_id')] : [];
        }
        $createFarm = (string) input('create_owner_farm') === '1';
        $result = RegistrationService::approve((int) $id, $role, $farmIds, $createFarm, (string) input('notes'));
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Approval failed.'));
            redirect(str_starts_with($redirectTo, 'dashboard') ? $redirectTo : ('users/pending/' . $id));
        }
        $emailResult = is_array($result['email_result'] ?? null) ? $result['email_result'] : [];
        if (!empty($emailResult['skipped'])) {
            $emailNote = 'No credential email was sent (only farm owners receive account emails). Staff sign in with their password and the farm owner’s Farm Code.';
        } elseif (!empty($result['activated'])) {
            $emailNote = !empty($emailResult['ok'])
                ? 'They can sign in with the username or email and password from registration. Approval email sent (password is never emailed).'
                : ('The account is active — they can sign in with the password they created. Approval email was not delivered: ' . ($emailResult['error'] ?? 'email not configured') . '.');
        } else {
            $emailNote = !empty($emailResult['ok'])
                ? 'Activation email queued via ' . (($emailResult['channel'] ?? 'email')) . '.'
                : ('The account is approved. Activation email was not delivered: ' . ($emailResult['error'] ?? 'email not configured') . '.');
        }
        flash('success', 'Account approved. ' . $emailNote);
        redirect($redirectTo);
    }

    public static function resendRegistrationEmail($id): void
    {
        Auth::authorize('users', 'edit');
        Csrf::verify();
        $redirectTo = trim((string) input('redirect_to')) ?: ('users/pending/' . $id);
        $result = RegistrationService::resendOwnerApprovalEmail((int) $id);
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Could not resend email.'));
            redirect($redirectTo);
        }
        flash('success', 'Approval email resent to the farm owner (password is never included).');
        redirect($redirectTo);
    }

    public static function rejectRegistration($id): void
    {
        Auth::authorize('users', 'edit');
        Csrf::verify();
        $redirectTo = trim((string) input('redirect_to')) ?: 'users';
        $result = RegistrationService::reject((int) $id, (string) input('rejection_reason'));
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Rejection failed.'));
            redirect(str_starts_with($redirectTo, 'dashboard') ? $redirectTo : ('users/pending/' . $id));
        }
        $emailNote = !empty($result['email_result']['ok'])
            ? 'Applicant notified by email.'
            : ('Email not sent: ' . ($result['email_result']['error'] ?? 'not configured') . '.');
        flash('success', 'Registration rejected. ' . $emailNote);
        redirect($redirectTo);
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
        $rows = db()->select(
            str_replace(
                'SELECT u.*, f.name AS farm_name',
                'SELECT u.id, u.name, u.username, u.email, u.role, u.requested_role, u.status, u.last_login, u.created_at, u.farm_id, u.must_change_password, f.name AS farm_name',
                $sql
            ) . ' ORDER BY u.id DESC',
            $params
        );
        $farms = farm_options();
        $pendingCount = 0;
        $pendingUsers = [];
        view('layouts/app', [
            'title' => 'Edit user',
            'content' => render('admin/users', compact('rows', 'farms', 'editUser', 'pendingCount', 'pendingUsers')),
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
            flash('error', 'Invalid account status.');
            redirect($failTo);
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
        // Admin create: if username left blank, derive one from the email local-part.
        if (!$id && $username === '') {
            $local = strtolower((string) strtok($email, '@'));
            $username = RegistrationService::normalizeUsername($local !== '' ? $local : 'user');
            if ($username === '') {
                $username = 'user';
            }
            $base = $username;
            $n = 1;
            while (db()->first('SELECT id FROM users WHERE lower(username) = ?', [$username])) {
                $username = $base . $n;
                $n++;
                if ($n > 99) {
                    break;
                }
            }
            $usernameRaw = $username;
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

    public static function setUserStatus($id): void
    {
        Auth::authorize('users', 'edit');
        Csrf::verify();
        $redirectTo = trim((string) input('redirect_to')) ?: 'users';
        if ((int) $id === (int) Auth::id()) {
            flash('error', 'You cannot change the status of your own administrator account.');
            redirect($redirectTo);
        }
        $target = db()->find('users', $id);
        if (!$target || !Auth::canManageUser($target)) {
            abort(403, 'You cannot manage this user.');
        }
        $status = (string) input('status');
        $allowed = ['active', 'suspended', 'inactive'];
        if (!in_array($status, $allowed, true)) {
            flash('error', 'Invalid account status.');
            redirect($redirectTo);
        }
        $current = (string) ($target['status'] ?? '');
        if (in_array($current, ['pending', 'approved', 'rejected'], true)) {
            flash('error', 'Use Approve or Reject for registration requests.');
            redirect($redirectTo);
        }
        if ($current === $status) {
            flash('success', 'Account status is already ' . $status . '.');
            redirect($redirectTo);
        }
        db()->update('users', [
            'status' => $status,
            'session_version' => (int) ($target['session_version'] ?? 1) + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $id);
        Audit::log('update', 'users', (int) $id, 'Status set to ' . $status);
        $labels = ['active' => 'reactivated', 'suspended' => 'suspended', 'inactive' => 'deactivated'];
        flash('success', 'Account ' . ($labels[$status] ?? 'updated') . '.');
        redirect($redirectTo);
    }

    public static function deleteUser($id): void
    {
        Auth::authorize('users', 'delete');
        Csrf::verify();
        $redirectTo = trim((string) input('redirect_to')) ?: 'users';
        if ((int) $id === (int) Auth::id()) {
            flash('error', 'You cannot delete your own account.');
            redirect($redirectTo);
        }
        $target = db()->find('users', $id);
        if (!$target || !Auth::canManageUser($target)) {
            abort(403, 'You cannot delete this user.');
        }
        $userId = (int) $id;
        $status = (string) ($target['status'] ?? '');
        $hasAudit = (int) db()->value('SELECT COUNT(*) FROM audit_logs WHERE user_id = ?', [$userId]) > 0;
        $ownsFarm = table_exists('farms')
            && (int) db()->value('SELECT COUNT(*) FROM farms WHERE owner_id = ?', [$userId]) > 0;
        $safeHardDelete = in_array($status, ['pending', 'rejected'], true) && !$hasAudit && !$ownsFarm;
        if ($safeHardDelete) {
            try {
                db()->query('DELETE FROM user_farms WHERE user_id = ?', [$userId]);
            } catch (Throwable $e) {
            }
            try {
                db()->query(
                    "UPDATE notifications SET is_read = 1 WHERE category = 'registration' AND link = ?",
                    ['users/pending/' . $userId]
                );
            } catch (Throwable $e) {
            }
            db()->delete('users', $userId);
            Audit::log('delete', 'users', $userId, 'Hard-deleted pending/rejected registration');
            flash('success', 'Registration request removed.');
            redirect($redirectTo);
        }
        // Preserve farm records, transactions and history — deactivate instead of erase.
        db()->update('users', [
            'status' => 'inactive',
            'session_version' => (int) ($target['session_version'] ?? 1) + 1,
            'activation_token_hash' => null,
            'activation_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $userId);
        try {
            db()->query(
                "UPDATE notifications SET is_read = 1 WHERE category = 'registration' AND link = ?",
                ['users/pending/' . $userId]
            );
        } catch (Throwable $e) {
        }
        Audit::log('delete', 'users', $userId, 'Soft-deleted (deactivated) to preserve related records');
        flash('success', 'Account deactivated. Farm and business records were kept.');
        redirect($redirectTo);
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
            'SELECT * FROM fields WHERE ' . $farmClause . ' ORDER BY name ASC',
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

    public static function saveMapLocation(): void
    {
        Auth::authorize('farms', 'edit');
        Csrf::verify();
        $farmId = (int) input('farm_id', Auth::farmId() ?: 0);
        if ($farmId <= 0 || (!Auth::isAdmin() && !Auth::canAccessFarm($farmId))) {
            flash('error', 'Choose a farm before saving a location.');
            redirect('map');
        }
        $lat = input('latitude');
        $lng = input('longitude');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            flash('error', 'Pick a location on the map first.');
            redirect('map');
        }
        $lat = round((float) $lat, 7);
        $lng = round((float) $lng, 7);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            flash('error', 'Those coordinates are invalid.');
            redirect('map');
        }
        $label = trim((string) input('location_label', ''));
        $farm = db()->find('farms', $farmId);
        if (!$farm) {
            flash('error', 'Farm not found.');
            redirect('map');
        }
        $location = $label !== '' ? $label : trim((string) (($farm['town'] ?? '') . (($farm['district'] ?? '') ? ', ' . $farm['district'] : '')));
        db()->update('farms', [
            'latitude' => $lat,
            'longitude' => $lng,
            'location_precision' => 'confirmed',
            'location_label' => $label !== '' ? $label : ($farm['location_label'] ?? null),
            'location' => $location !== '' ? $location : ($farm['location'] ?? null),
        ], $farmId);
        Audit::log('update', 'farms', $farmId, 'Saved farm map location');
        flash('success', 'Farm location saved.');
        redirect('map');
    }

    public static function fieldsPlanner(): void
    {
        Auth::authorize('fields', 'view');
        $farm = self::currentFarm();
        $fields = [];
        if ($farm) {
            $fields = db()->select(
                'SELECT f.*, (
                    SELECT COUNT(*) FROM plantings p WHERE p.field_id = f.id
                 ) AS planting_count
                 FROM fields f
                 WHERE f.farm_id = ?
                 ORDER BY f.name ASC',
                [$farm['id']]
            );
        }
        view('layouts/app', [
            'title' => 'Fields & Plots',
            'content' => render('fields_planner', compact('farm', 'fields')),
        ]);
    }

    public static function fieldsOptions(): void
    {
        Auth::requireAuth();
        if (!Auth::can('fields', 'view') && !Auth::can('plantings', 'view') && !Auth::can('soil_records', 'view')) {
            json_response(['ok' => false, 'error' => 'Not allowed.', 'fields' => []], 403);
        }
        $farmId = (int) input('farm_id', Auth::farmId() ?: 0);
        if ($farmId <= 0) {
            $accessible = Auth::accessibleFarms();
            if (count($accessible) === 1) {
                $farmId = (int) $accessible[0]['id'];
            }
        }
        if ($farmId <= 0) {
            json_response(['ok' => true, 'fields' => [], 'message' => 'No farm selected.']);
        }
        if (!Auth::canAccessFarm($farmId) && !Auth::isAdmin()) {
            json_response(['ok' => false, 'error' => 'Farm not accessible.', 'fields' => []], 403);
        }
        $includeInactive = (string) input('include_inactive', '') === '1';
        $sql = 'SELECT id, name, status, size_hectares, farm_id FROM fields WHERE farm_id = ?';
        $params = [$farmId];
        if (!$includeInactive) {
            $sql .= " AND COALESCE(status, 'active') IN ('active', 'fallow', 'reserved')";
        }
        $sql .= ' ORDER BY name ASC';
        $rows = db()->select($sql, $params);
        $fields = [];
        foreach ($rows as $r) {
            $fields[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'status' => (string) ($r['status'] ?? 'active'),
                'size_hectares' => (float) ($r['size_hectares'] ?? 0),
                'farm_id' => (int) $r['farm_id'],
            ];
        }
        json_response([
            'ok' => true,
            'fields' => $fields,
            'empty_message' => $fields
                ? null
                : 'No fields available. Please create fields in Fields & Plots first.',
            'create_url' => url('fields-planner'),
        ]);
    }

    public static function saveFieldJson(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json; charset=utf-8');
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $csrf = (string) ($body['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($csrf === '' || !hash_equals(Csrf::token(), $csrf)) {
            json_response(['ok' => false, 'error' => 'Security token expired. Refresh and try again.'], 419);
        }
        $farm = self::currentFarm();
        if (!$farm) {
            json_response(['ok' => false, 'error' => 'Register a farm before creating fields.'], 400);
        }
        $farmId = (int) $farm['id'];
        $id = (int) ($body['id'] ?? 0);
        $isUpdate = $id > 0;
        if ($isUpdate && !Auth::can('fields', 'edit')) {
            json_response(['ok' => false, 'error' => 'You do not have permission to edit fields.'], 403);
        }
        if (!$isUpdate && !Auth::can('fields', 'create')) {
            json_response(['ok' => false, 'error' => 'You do not have permission to create fields.'], 403);
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['ok' => false, 'error' => 'Field name is required.'], 422);
        }
        $name = mb_substr($name, 0, 120);
        $dup = db()->first(
            'SELECT id FROM fields WHERE farm_id = ? AND LOWER(name) = LOWER(?) AND id <> ? LIMIT 1',
            [$farmId, $name, $id]
        );
        if ($dup) {
            json_response(['ok' => false, 'error' => 'That field name is already used on this farm.'], 422);
        }
        $geo = $body['geometry'] ?? null;
        if (!is_array($geo)) {
            json_response(['ok' => false, 'error' => 'Field boundary geometry is required.'], 422);
        }
        $status = (string) ($body['status'] ?? 'active');
        if (!in_array($status, ['active', 'fallow', 'reserved'], true)) {
            $status = 'active';
        }
        $size = isset($body['size_hectares']) && is_numeric($body['size_hectares'])
            ? round((float) $body['size_hectares'], 2)
            : 0.0;
        $lat = isset($body['latitude']) && is_numeric($body['latitude']) ? round((float) $body['latitude'], 7) : null;
        $lng = isset($body['longitude']) && is_numeric($body['longitude']) ? round((float) $body['longitude'], 7) : null;
        $description = mb_substr(trim((string) ($body['description'] ?? '')), 0, 2000);
        $notes = '[[GEOJSON]]' . json_encode($geo);
        $payload = [
            'farm_id' => $farmId,
            'name' => $name,
            'size_hectares' => $size,
            'status' => $status,
            'latitude' => $lat,
            'longitude' => $lng,
            'boundary_notes' => $notes,
            'description' => $description !== '' ? $description : null,
        ];
        // land_use kept in sync lightly for older list views
        if ($description !== '') {
            $payload['land_use'] = mb_substr($description, 0, 120);
        }
        try {
            if ($isUpdate) {
                $existing = db()->find('fields', $id);
                if (!$existing || (int) $existing['farm_id'] !== $farmId) {
                    json_response(['ok' => false, 'error' => 'Field not found on this farm.'], 404);
                }
                db()->update('fields', $payload, $id);
            } else {
                $id = db()->insert('fields', $payload);
            }
        } catch (Throwable $e) {
            log_internal_error('fields_save', $e);
            json_response(['ok' => false, 'error' => 'Could not save field.'], 500);
        }
        Audit::log($isUpdate ? 'update' : 'create', 'fields', $id, 'Field saved from Fields & Plots map');
        json_response([
            'ok' => true,
            'field' => [
                'id' => (int) $id,
                'name' => $name,
                'status' => $status,
                'size_hectares' => $size,
                'description' => $description,
                'farm_id' => $farmId,
                'latitude' => $lat,
                'longitude' => $lng,
            ],
        ]);
    }

    public static function deleteFieldJson(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json; charset=utf-8');
        if (!Auth::can('fields', 'delete')) {
            json_response(['ok' => false, 'error' => 'You do not have permission to delete fields.'], 403);
        }
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $csrf = (string) ($body['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($csrf === '' || !hash_equals(Csrf::token(), $csrf)) {
            json_response(['ok' => false, 'error' => 'Security token expired. Refresh and try again.'], 419);
        }
        $farm = self::currentFarm();
        if (!$farm) {
            json_response(['ok' => false, 'error' => 'No active farm.'], 400);
        }
        $id = (int) ($body['id'] ?? 0);
        $row = $id > 0 ? db()->find('fields', $id) : null;
        if (!$row || (int) $row['farm_id'] !== (int) $farm['id']) {
            json_response(['ok' => false, 'error' => 'Field not found.'], 404);
        }
        $plantingCount = (int) db()->value('SELECT COUNT(*) FROM plantings WHERE field_id = ?', [$id]);
        if ($plantingCount > 0) {
            json_response([
                'ok' => false,
                'error' => 'This field has planting history and cannot be deleted. Mark it vacant instead.',
                'planting_count' => $plantingCount,
            ], 409);
        }
        $related = (int) db()->value('SELECT COUNT(*) FROM soil_records WHERE field_id = ?', [$id]);
        $related += (int) db()->value('SELECT COUNT(*) FROM crop_rotations WHERE field_id = ?', [$id]);
        if ($related > 0) {
            json_response([
                'ok' => false,
                'error' => 'This field has related records and cannot be deleted. Mark it vacant instead.',
            ], 409);
        }
        try {
            db()->delete('fields', $id);
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => 'Could not delete field because related records still depend on it.'], 409);
        }
        Audit::log('delete', 'fields', $id, 'Field deleted from Fields & Plots map');
        json_response(['ok' => true]);
    }

    public static function cropRotationMap(): void
    {
        Auth::authorize('crop_rotations', 'view');
        $farm = self::currentFarm();
        $mapData = CropRotationMapService::build($farm);
        view('layouts/app', [
            'title' => 'Crop Rotation',
            'content' => render('crop_rotation_map', compact('farm', 'mapData')),
        ]);
    }

    public static function cropRotationFieldReport($id): void
    {
        Auth::authorize('crop_rotations', 'view');
        $report = CropRotationMapService::fieldReport((int) $id);
        if (!$report) {
            flash('error', 'Field not found.');
            redirect('crop-rotation');
        }
        $bytes = CropRotationReports::fieldPdf($report);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . CropRotationReports::fieldFilename($report['field']) . '"');
        echo $bytes;
        exit;
    }

    public static function cropRotationFarmReport(): void
    {
        Auth::authorize('crop_rotations', 'view');
        $farm = self::currentFarm();
        $report = CropRotationMapService::farmReport($farm);
        if (!$report) {
            flash('error', 'Register a farm first.');
            redirect('crop-rotation');
        }
        $bytes = CropRotationReports::farmPdf($report);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . CropRotationReports::farmFilename($report['farm']) . '"');
        echo $bytes;
        exit;
    }

    public static function saveFieldsPlanner(): void
    {
        Auth::authorize('fields', 'edit');
        Csrf::verify();
        $farm = self::currentFarm();
        if (!$farm) {
            flash('error', 'Register a farm before drawing fields.');
            redirect('module/farms/create');
        }
        $farmId = (int) $farm['id'];
        $boundary = trim((string) input('boundary_geojson', ''));
        $fieldsJson = (string) input('fields_geojson', '[]');
        $decodedFields = json_decode($fieldsJson, true);
        if (!is_array($decodedFields)) {
            flash('error', 'Could not read field shapes.');
            redirect('fields-planner');
        }
        if ($boundary !== '') {
            $b = json_decode($boundary, true);
            if (!is_array($b)) {
                flash('error', 'Farm boundary is invalid.');
                redirect('fields-planner');
            }
            db()->update('farms', ['boundary_geojson' => json_encode($b)], $farmId);
        }
        $keptIds = [];
        foreach ($decodedFields as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ('Field #' . ($i + 1))));
            if ($name === '') {
                $name = 'Field #' . ($i + 1);
            }
            $geo = $item['geometry'] ?? null;
            if (!is_array($geo)) {
                continue;
            }
            $size = isset($item['size_hectares']) && is_numeric($item['size_hectares'])
                ? round((float) $item['size_hectares'], 2)
                : 0;
            $status = in_array(($item['status'] ?? 'active'), ['active', 'fallow', 'reserved'], true)
                ? $item['status']
                : 'active';
            $lat = isset($item['latitude']) && is_numeric($item['latitude']) ? round((float) $item['latitude'], 7) : null;
            $lng = isset($item['longitude']) && is_numeric($item['longitude']) ? round((float) $item['longitude'], 7) : null;
            $description = mb_substr(trim((string) ($item['description'] ?? '')), 0, 2000);
            $notes = "[[GEOJSON]]" . json_encode($geo);
            $id = (int) ($item['id'] ?? 0);
            $payload = [
                'farm_id' => $farmId,
                'name' => mb_substr($name, 0, 120),
                'size_hectares' => $size,
                'status' => $status,
                'latitude' => $lat,
                'longitude' => $lng,
                'boundary_notes' => $notes,
                'description' => $description !== '' ? $description : null,
            ];
            if ($description !== '') {
                $payload['land_use'] = mb_substr($description, 0, 120);
            }
            if ($id > 0) {
                $existing = db()->find('fields', $id);
                if ($existing && (int) $existing['farm_id'] === $farmId) {
                    db()->update('fields', $payload, $id);
                    $keptIds[] = $id;
                    continue;
                }
            }
            if (!Auth::can('fields', 'create')) {
                continue;
            }
            $keptIds[] = db()->insert('fields', $payload);
        }
        // Optional deletes when client sends delete_ids — never wipe planting history
        $deleteIds = json_decode((string) input('delete_ids', '[]'), true);
        if (is_array($deleteIds) && Auth::can('fields', 'delete')) {
            foreach ($deleteIds as $delId) {
                $delId = (int) $delId;
                if ($delId <= 0) {
                    continue;
                }
                $row = db()->find('fields', $delId);
                if (!$row || (int) $row['farm_id'] !== $farmId) {
                    continue;
                }
                $hasHistory = (int) db()->value('SELECT COUNT(*) FROM plantings WHERE field_id = ?', [$delId]) > 0
                    || (int) db()->value('SELECT COUNT(*) FROM soil_records WHERE field_id = ?', [$delId]) > 0;
                if ($hasHistory) {
                    db()->update('fields', ['status' => 'fallow'], $delId);
                    continue;
                }
                try {
                    db()->delete('fields', $delId);
                } catch (Throwable $e) {
                    db()->update('fields', ['status' => 'fallow'], $delId);
                }
            }
        }
        Audit::log('update', 'fields', $farmId, 'Saved field divisions (' . count($keptIds) . ')');
        flash('success', 'Farm boundary and fields saved.');
        redirect('fields-planner');
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
        }
        if (Auth::isAdmin()) {
            $farm = db()->first('SELECT * FROM farms ORDER BY id ASC');
            if ($farm) {
                Auth::setActiveFarm((int) $farm['id']);
                return $farm;
            }
            return null;
        }
        if (Auth::id() && table_exists('user_farms')) {
            $farm = db()->first(
                'SELECT f.* FROM farms f
                 INNER JOIN user_farms uf ON uf.farm_id = f.id
                 WHERE uf.user_id = ?
                 ORDER BY uf.is_primary DESC, f.id ASC',
                [Auth::id()]
            );
            if ($farm) {
                Auth::setActiveFarm((int) $farm['id']);
                return $farm;
            }
        }
        return null;
    }
}
