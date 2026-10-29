<?php

class WorkspaceController
{
    public static function profile(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $farm = $user['farm_id'] ? db()->find('farms', $user['farm_id']) : null;
        view('layouts/app', [
            'title' => 'My Profile',
            'content' => render('account/profile', compact('user', 'farm')),
        ]);
    }

    public static function saveProfile(): void
    {
        Auth::requireAuth();
        Csrf::verify();
        $user = Auth::user();
        $name = trim((string) input('name'));
        $phoneRaw = trim((string) input('phone'));
        $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = $name === '' ? 'Display name is required.' : 'Display name is too long.';
        }
        if ($phoneRaw !== '' && !valid_phone($phoneRaw)) {
            $errors[] = phone_validation_message();
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            remember_old(['name' => $name, 'phone' => input('phone')]);
            redirect('profile');
        }
        db()->update('users', [
            'name' => $name,
            'phone' => $phone ?: null,
        ], $user['id']);
        Auth::setOverride(null);
        Audit::log('update', 'users', (int) $user['id'], 'Updated own profile');
        flash('success', 'Profile changes saved.');
        redirect('profile');
    }

    public static function changePassword(): void
    {
        Auth::requireAuth();
        Csrf::verify();
        $user = db()->find('users', Auth::id());
        $current = (string) input('current_password');
        $password = (string) input('password');
        $confirm = (string) input('password_confirm');
        $errors = [];
        if (!password_verify($current, $user['password'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        }
        $errors = array_merge($errors, PasswordPolicy::errors($password));
        if ($password !== $confirm) {
            $errors[] = 'New password and confirmation must match.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('profile');
        }
        db()->update('users', [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ], $user['id']);
        Audit::log('update', 'auth', (int) $user['id'], 'Changed own password');
        flash('success', 'Password updated.');
        redirect('profile');
    }

    public static function switchFarm(): void
    {
        Auth::requireAuth();
        Csrf::verify();
        $farmId = (int) input('farm_id');
        if ($farmId === 0 && Auth::isAdmin()) {
            Auth::setActiveFarm(null);
            flash('success', 'Showing data for all farms.');
            redirect(safe_return_path());
        }
        if (!$farmId || !Auth::canAccessFarm($farmId)) {
            flash('error', 'You cannot switch to that farm.');
            redirect(safe_return_path());
        }
        Auth::setActiveFarm($farmId);
        $farm = db()->find('farms', $farmId);
        flash('success', 'Now viewing ' . ($farm['name'] ?? 'the selected farm') . '.');
        redirect(safe_return_path());
    }

    public static function joinFarm(): void
    {
        Auth::requireAuth();
        Csrf::verify();
        $code = strtoupper(trim((string) input('join_code')));
        $user = Auth::user();
        if (!in_array($user['role'], ['manager', 'worker', 'agronomist', 'accountant'], true)) {
            flash('error', 'Farm owners create a workspace instead of joining with a code.');
            redirect('dashboard');
        }
        if ($code === '') {
            flash('error', 'Enter the farm join code provided by the owner or administrator.');
            redirect('dashboard');
        }
        $farm = db()->first("SELECT * FROM farms WHERE join_code = ? AND status = 'active'", [$code]);
        if (!$farm) {
            flash('error', 'That join code is invalid or the farm is not active.');
            redirect('dashboard');
        }
        db()->update('users', ['farm_id' => $farm['id']], $user['id']);
        Auth::setActiveFarm((int) $farm['id']);
        Audit::log('update', 'users', (int) $user['id'], 'Joined farm via join code');
        flash('success', 'You now have access to ' . $farm['name'] . '.');
        redirect('dashboard');
    }

    public static function search(): void
    {
        Auth::requireAuth();
        $q = trim((string) input('q', ''));
        $results = [];
        if (mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';
            [$farmClause, $farmParams] = active_farm_constraint('farm_id');
            $farmSql = ' AND ' . $farmClause;
            [$directoryClause, $directoryParams] = farm_directory_constraint();
            [$taskSql, $taskParams] = TaskAccess::listConstraint();
            $add = function (string $module, string $sql, array $params, callable $map) use (&$results) {
                $def = module_def($module);
                if (!Auth::can($module, 'view') || !$def || !table_exists($def['table'] ?? '')) {
                    return;
                }
                foreach (db()->select($sql, $params) as $row) {
                    $mapped = $map($row);
                    if ($mapped) {
                        $results[] = $mapped;
                    }
                }
            };
            if (Auth::can('farms', 'view')) {
                $add('farms',
                    'SELECT id, name, location FROM farms WHERE (name LIKE ? OR location LIKE ?) AND ' . $directoryClause . ' ORDER BY name LIMIT 15',
                    array_merge([$like, $like], $directoryParams),
                    fn ($r) => ['label' => $r['name'], 'meta' => 'Farm · ' . ($r['location'] ?: 'No location'), 'href' => url('module/farms/view/' . $r['id'])]
                );
            }
            $add('fields',
                'SELECT id, name, land_use FROM fields WHERE (name LIKE ? OR land_use LIKE ? OR soil_type LIKE ?)' . $farmSql . ' ORDER BY name LIMIT 15',
                array_merge([$like, $like, $like], $farmParams),
                fn ($r) => ['label' => $r['name'], 'meta' => 'Field · ' . ($r['land_use'] ?: 'Plot'), 'href' => url('module/fields/view/' . $r['id'])]
            );
            $add('plantings',
                'SELECT id, season, status FROM plantings WHERE (season LIKE ? OR status LIKE ? OR growth_stage LIKE ?)' . $farmSql . ' ORDER BY id DESC LIMIT 15',
                array_merge([$like, $like, $like], $farmParams),
                fn ($r) => ['label' => 'Planting #' . $r['id'], 'meta' => 'Crop · ' . ($r['season'] ?: '') . ' · ' . ($r['status'] ?: ''), 'href' => url('module/plantings/view/' . $r['id'])]
            );
            $add('tasks',
                'SELECT id, title, status, due_date FROM tasks WHERE (title LIKE ? OR status LIKE ?)' . $farmSql . $taskSql . ' ORDER BY id DESC LIMIT 15',
                array_merge([$like, $like], $farmParams, $taskParams),
                fn ($r) => ['label' => $r['title'], 'meta' => 'Task · ' . ($r['status'] ?: '') . ($r['due_date'] ? ' · due ' . $r['due_date'] : ''), 'href' => url('module/tasks/view/' . $r['id'])]
            );
            $add('inventory_items',
                'SELECT id, name, category, quantity FROM inventory_items WHERE (name LIKE ? OR sku LIKE ? OR category LIKE ?)' . $farmSql . ' ORDER BY name LIMIT 15',
                array_merge([$like, $like, $like], $farmParams),
                fn ($r) => ['label' => $r['name'], 'meta' => 'Inventory · ' . ($r['category'] ?: '') . ' · qty ' . $r['quantity'], 'href' => url('module/inventory_items/view/' . $r['id'])]
            );
            $add('animals',
                'SELECT id, tag_number, species, status FROM animals WHERE (tag_number LIKE ? OR species LIKE ? OR name LIKE ?)' . $farmSql . ' ORDER BY id DESC LIMIT 15',
                array_merge([$like, $like, $like], $farmParams),
                fn ($r) => ['label' => $r['tag_number'] ?: ('Animal #' . $r['id']), 'meta' => 'Livestock · ' . ($r['species'] ?: ''), 'href' => url('module/animals/view/' . $r['id'])]
            );
        }
        view('layouts/app', [
            'title' => 'Search',
            'content' => render('search', compact('q', 'results')),
        ]);
    }

    public static function settings(): void
    {
        Auth::authorize('settings', 'view');
        view('layouts/app', [
            'title' => 'Workspace settings',
            'content' => render('account/settings', [
                'currency' => app_currency(),
                'symbol' => app_currency_symbol(),
                'timezone' => app_timezone(),
                'currencies' => supported_currencies(),
                'timezones' => supported_timezones(),
            ]),
        ]);
    }

    public static function saveSettings(): void
    {
        Auth::authorize('settings', 'edit');
        Csrf::verify();
        $currency = (string) input('currency');
        $timezone = (string) input('timezone');
        $currencies = supported_currencies();
        if (!isset($currencies[$currency])) {
            flash('error', 'Choose a supported currency.');
            redirect('settings');
        }
        if (!isset(supported_timezones()[$timezone])) {
            flash('error', 'Choose a supported timezone.');
            redirect('settings');
        }
        Settings::put('currency', $currency);
        Settings::put('currency_symbol', $currencies[$currency]['symbol']);
        Settings::put('timezone', $timezone);
        Audit::log('update', 'settings', null, 'Currency and timezone updated');
        flash('success', 'Display currency and timezone saved. Stored amounts were not converted.');
        redirect('settings');
    }

    public static function calendar(): void
    {
        Auth::authorize('calendar', 'view');
        $view = input('view', 'month');
        if (!in_array($view, ['month', 'week', 'day'], true)) {
            $view = 'month';
        }
        $dateStr = input('date') ?: date('Y-m-d');
        if (!is_valid_date($dateStr)) {
            $dateStr = date('Y-m-d');
        }
        $focus = new DateTimeImmutable($dateStr, new DateTimeZone(app_timezone()));
        if ($view === 'month') {
            $start = $focus->modify('first day of this month')->modify('monday this week');
            if ($focus->modify('first day of this month')->format('N') === '1') {
                $start = $focus->modify('first day of this month');
            }
            $monthStart = $focus->modify('first day of this month');
            $start = $monthStart->modify('monday this week');
            $end = $monthStart->modify('last day of this month')->modify('sunday this week');
        } elseif ($view === 'week') {
            $start = $focus->modify('monday this week');
            $end = $focus->modify('sunday this week');
        } else {
            $start = $focus;
            $end = $focus;
        }
        $events = CalendarService::events($start, $end);
        $byDate = [];
        foreach ($events as $event) {
            $byDate[$event['date']][] = $event;
        }
        view('layouts/app', [
            'title' => 'Operations calendar',
            'content' => render('calendar', compact('view', 'focus', 'start', 'end', 'byDate')),
        ]);
    }
}
