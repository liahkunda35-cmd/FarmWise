<?php

/**
 * Read-only farm snapshot for the authenticated AI assistant.
 * Only includes modules the current user may view, scoped to their farm.
 */
class FarmAssistantContext
{
    public static function snapshot(): string
    {
        if (!Auth::check()) {
            return 'No authenticated user.';
        }

        $lines = [];
        $user = Auth::user();
        $first = trim((string) preg_replace('/\s+/u', ' ', (string) ($user['name'] ?? '')));
        $first = $first !== '' ? (explode(' ', $first)[0] ?? '') : '';
        $lines[] = 'User first name: ' . ($first !== '' ? $first : 'unknown') . ' (role: ' . (Auth::role() ?? 'n/a') . ')';
        $lines[] = 'Email on file: ' . ((!empty($user['email'])) ? 'yes' : 'no');
        // Never include raw email, phone, or passwords in the model prompt.
        $farmId = Auth::farmId();

        $lines[] = 'Useful in-app destinations: /dashboard, /fields, /plantings, /animals, /tasks, /inventory, /harvests, /transactions, /weather, /reports, /map, /calendar, /profile, /settings';

        if (Auth::isAdmin() && !$farmId) {
            $farmCount = (int) db()->value('SELECT COUNT(*) FROM farms');
            $lines[] = 'Scope: administrator with no farm selected (' . $farmCount . ' farms on the platform).';
            $lines[] = 'Ask the user to select a specific farm (farm switcher) before discussing private records. No farm record details are attached.';
            return implode("\n", $lines);
        }

        if (!$farmId) {
            $lines[] = 'No farm is currently selected for this user.';
            $lines[] = 'They may need to join a farm with a join code or create/select a farm workspace.';
            return implode("\n", $lines);
        }

        $farm = db()->find('farms', $farmId);
        if (!$farm || !Auth::canAccessFarm((int) $farmId)) {
            return 'No authorized farm access.';
        }

        if (!Auth::can('farms', 'view') && !Auth::can('dashboard', 'view')) {
            return 'No authorized farm access.';
        }

        $lines[] = 'Active farm: ' . ($farm['name'] ?? 'Farm #' . $farmId);
        $lines[] = 'Location text: ' . (($farm['location'] ?? '') !== '' ? $farm['location'] : 'not set');
        $placeParts = array_filter([
            $farm['town'] ?? null,
            $farm['district'] ?? null,
            $farm['province'] ?? null,
            $farm['country'] ?? null,
        ], static fn ($v) => $v !== null && trim((string) $v) !== '');
        if ($placeParts) {
            $lines[] = 'Place: ' . implode(', ', $placeParts);
        }
        if (!empty($farm['location_label'])) {
            $lines[] = 'Map label: ' . $farm['location_label'];
        }
        $precision = (string) ($farm['location_precision'] ?? 'none');
        $lines[] = 'Location precision: ' . ($precision !== '' ? $precision : 'none');
        if (isset($farm['latitude'], $farm['longitude']) && $farm['latitude'] !== '' && $farm['longitude'] !== '' && $farm['latitude'] !== null && $farm['longitude'] !== null) {
            $lines[] = 'Map coordinates: ' . $farm['latitude'] . ', ' . $farm['longitude'] . ' (use for weather context only; do not invent other farms)';
        } else {
            $lines[] = 'Map coordinates: not set — ask the farmer to place the farm on the map for local weather.';
        }
        if (Auth::can('farms', 'view') || Auth::can('fields', 'view')) {
            $lines[] = 'Total area (ha): ' . ($farm['total_area'] ?? '0');
        }

        if (Auth::can('fields', 'view')) {
            $lines[] = 'Fields: ' . (int) db()->value('SELECT COUNT(*) FROM fields WHERE farm_id = ?', [$farmId]);
        }
        if (Auth::can('plantings', 'view')) {
            $lines[] = 'Active plantings: ' . (int) db()->value(
                "SELECT COUNT(*) FROM plantings WHERE farm_id = ? AND status IN ('planted','growing')",
                [$farmId]
            );
        }
        if (Auth::can('animals', 'view')) {
            $lines[] = 'Active animals: ' . (int) db()->value(
                "SELECT COUNT(*) FROM animals WHERE farm_id = ? AND status = 'active'",
                [$farmId]
            );
        }
        if (Auth::can('inventory_items', 'view')) {
            $lines[] = 'Inventory items: ' . (int) db()->value('SELECT COUNT(*) FROM inventory_items WHERE farm_id = ?', [$farmId]);
        }

        if (Auth::can('tasks', 'view')) {
            [$taskExtra, $taskParams] = TaskAccess::listConstraint();
            $openTasks = (int) db()->value(
                "SELECT COUNT(*) FROM tasks WHERE farm_id = ? AND status != 'completed'" . $taskExtra,
                array_merge([$farmId], $taskParams)
            );
            $lines[] = 'Open tasks (authorised scope): ' . $openTasks;

            $tasks = db()->select(
                "SELECT title, due_date, status, priority FROM tasks
                 WHERE farm_id = ? AND status != 'completed'" . $taskExtra . '
                 ORDER BY due_date IS NULL, due_date ASC, id DESC LIMIT 5',
                array_merge([$farmId], $taskParams)
            );
            if ($tasks) {
                $lines[] = 'Upcoming / open tasks:';
                foreach ($tasks as $t) {
                    $lines[] = '- ' . ($t['title'] ?? 'Task') .
                        ' · due ' . (($t['due_date'] ?? '') !== '' ? $t['due_date'] : 'unset') .
                        ' · ' . ($t['status'] ?? '') .
                        ' · priority ' . ($t['priority'] ?? 'n/a');
                }
            }
        }

        // Financial totals only with explicit transactions permission (not dashboard alone).
        if (Auth::can('transactions', 'view')) {
            $expense = (float) db()->value(
                "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE farm_id = ? AND type = 'expense'",
                [$farmId]
            );
            $income = (float) db()->value(
                "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE farm_id = ? AND type = 'income'",
                [$farmId]
            );
            $lines[] = 'Recorded expenses total: ' . number_format($expense, 2);
            $lines[] = 'Recorded income total: ' . number_format($income, 2);
        }

        if (Auth::can('harvests', 'view')) {
            $harvests = db()->select(
                'SELECT harvest_date, quantity, unit, grade, status FROM harvests WHERE farm_id = ? ORDER BY harvest_date DESC LIMIT 5',
                [$farmId]
            );
            if ($harvests) {
                $lines[] = 'Recent harvests:';
                foreach ($harvests as $h) {
                    $lines[] = '- ' . ($h['harvest_date'] ?? '') . ': ' . ($h['quantity'] ?? '') . ' ' . ($h['unit'] ?? '') .
                        ' (grade ' . ($h['grade'] ?? 'n/a') . ', ' . ($h['status'] ?? '') . ')';
                }
            } else {
                $lines[] = 'Recent harvests: none recorded.';
            }
        }

        if (Auth::can('plantings', 'view')) {
            $plantings = db()->select(
                "SELECT p.season, p.growth_stage, p.status, p.planting_date, c.name AS crop
                 FROM plantings p
                 LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
                 WHERE p.farm_id = ?
                 ORDER BY p.id DESC LIMIT 8",
                [$farmId]
            );
            if ($plantings) {
                $lines[] = 'Recent / active plantings:';
                foreach ($plantings as $p) {
                    $lines[] = '- ' . ($p['crop'] ?: 'Crop') . ' / ' . ($p['season'] ?? '') .
                        ' — stage ' . ($p['growth_stage'] ?? 'n/a') . ', status ' . ($p['status'] ?? '') .
                        ', planted ' . ($p['planting_date'] ?? '');
                }
            } else {
                $lines[] = 'Plantings: none recorded yet.';
            }
        }

        if (Auth::can('animals', 'view')) {
            $animals = db()->select(
                "SELECT species, COUNT(*) AS total
                 FROM animals WHERE farm_id = ? AND status = 'active'
                 GROUP BY species ORDER BY total DESC LIMIT 6",
                [$farmId]
            );
            if ($animals) {
                $lines[] = 'Active livestock (grouped):';
                foreach ($animals as $a) {
                    $lines[] = '- ' . ($a['species'] ?? 'Animal') . ': ' . (int) ($a['total'] ?? 0);
                }
            } else {
                $lines[] = 'Active livestock: none recorded.';
            }
        }

        try {
            if (Auth::can('inventory_items', 'view')) {
                $showCost = Auth::can('transactions', 'view');
                $inv = db()->select(
                    'SELECT name, quantity, unit, category, unit_cost FROM inventory_items WHERE farm_id = ? ORDER BY id DESC LIMIT 8',
                    [$farmId]
                );
                if ($inv) {
                    $lines[] = 'Inventory (sample):';
                    foreach ($inv as $item) {
                        $line = '- ' . ($item['name'] ?? 'Item') .
                            ': ' . ($item['quantity'] ?? '0') . ' ' . ($item['unit'] ?? '') .
                            (($item['category'] ?? '') !== '' ? ' (' . $item['category'] . ')' : '');
                        if ($showCost && isset($item['unit_cost']) && $item['unit_cost'] !== '' && $item['unit_cost'] !== null) {
                            $line .= ' · unit_cost ' . $item['unit_cost'];
                        }
                        $lines[] = $line;
                    }
                }
            }
        } catch (Throwable $e) {
            // table may differ
        }

        try {
            if (Auth::can('irrigation_schedules', 'view')) {
                $irrigation = db()->select(
                    "SELECT scheduled_date, status, water_litres, duration_minutes, recommendation
                     FROM irrigation_schedules
                     WHERE farm_id = ?
                     ORDER BY scheduled_date DESC, id DESC LIMIT 5",
                    [$farmId]
                );
                if ($irrigation) {
                    $lines[] = 'Recent irrigation schedules:';
                    foreach ($irrigation as $row) {
                        $lines[] = '- ' . ($row['scheduled_date'] ?? 'n/a') .
                            ' · ' . ($row['status'] ?? '') .
                            ' · water_litres ' . ($row['water_litres'] ?? 'n/a') .
                            ' · duration_min ' . ($row['duration_minutes'] ?? 'n/a') .
                            (($row['recommendation'] ?? '') !== '' ? ' · ' . $row['recommendation'] : '');
                    }
                }
            }
        } catch (Throwable $e) {
            // optional module
        }

        try {
            if (Auth::can('weather', 'view')) {
                $wx = db()->first(
                    'SELECT temperature, humidity, rainfall, wind_speed, conditions, log_date
                     FROM weather_logs WHERE farm_id = ? ORDER BY log_date DESC, id DESC LIMIT 1',
                    [$farmId]
                );
                if ($wx) {
                    $lines[] = 'Latest stored weather log (' . ($wx['log_date'] ?? 'n/a') . '):';
                    $lines[] = '- Temperature: ' . ($wx['temperature'] ?? 'n/a');
                    $lines[] = '- Conditions: ' . ($wx['conditions'] ?? 'n/a');
                    $lines[] = '- Humidity: ' . ($wx['humidity'] ?? 'n/a');
                    $lines[] = '- Rainfall: ' . ($wx['rainfall'] ?? 'n/a');
                    $lines[] = '- Wind: ' . ($wx['wind_speed'] ?? 'n/a');
                }
            }
        } catch (Throwable $e) {
            $lines[] = 'Stored weather log: not available right now.';
        }

        if (Auth::can('weather', 'view')) {
            $lines = array_merge($lines, self::liveWeatherLines($farm));
        }

        $lines[] = 'Note: figures above are from authorized FarmWise records and the configured weather API only. Do not invent additional numbers. If weather meta.ok is false, say the live forecast is unavailable.';
        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function liveWeatherLines(array $farm): array
    {
        $lines = [];
        try {
            if (!Auth::can('weather', 'view')) {
                $lines[] = 'Live weather: permission not granted for this role.';
                return $lines;
            }
            if (!class_exists('WeatherService')) {
                $lines[] = 'Live weather: WeatherService not loaded.';
                return $lines;
            }

            $forecast = WeatherService::fetchForFarm($farm);
            if ($forecast === null) {
                $lines[] = 'Live weather: no payload returned. Do not invent a forecast.';
                return $lines;
            }

            $meta = $forecast['meta'] ?? [];
            $ok = !empty($meta['ok']);
            $lines[] = 'Live weather block (OpenWeather integration):';
            $lines[] = '- meta.ok: ' . ($ok ? 'true' : 'false');
            $lines[] = '- meta.error: ' . (string) ($meta['error'] ?? 'none');
            $lines[] = '- meta.message: ' . (string) ($meta['message'] ?? '');
            $lines[] = '- location_precision: ' . (string) ($meta['location_precision'] ?? ($farm['location_precision'] ?? 'unknown'));
            $lines[] = '- updated_at: ' . (string) ($meta['updated_at'] ?? 'unknown');
            $lines[] = '- cached: ' . (!empty($meta['cached']) ? 'true' : 'false');

            if (!$ok && empty($forecast['current'])) {
                $lines[] = '- Live observation/forecast unavailable. Do not invent rainfall or temperatures.';
                return $lines;
            }

            $current = $forecast['current'] ?? [];
            if ($current) {
                $lines[] = 'Current observation (not a forecast):';
                $lines[] = '- temperature_c: ' . ($current['temperature_2m'] ?? $current['temp'] ?? 'n/a');
                $lines[] = '- humidity_pct: ' . ($current['relative_humidity_2m'] ?? $current['humidity'] ?? 'n/a');
                $lines[] = '- wind_kmh: ' . ($current['wind_speed_10m'] ?? $current['wind'] ?? 'n/a');
                $lines[] = '- conditions: ' . ($current['weather_description'] ?? $current['conditions'] ?? WeatherService::codeLabel($current['weather_code'] ?? ''));
                if (isset($current['time']) || isset($current['observed_at'])) {
                    $lines[] = '- observed_at: ' . ($current['time'] ?? $current['observed_at']);
                }
            }

            $daily = $forecast['daily'] ?? [];
            if (!empty($daily['time']) && is_array($daily['time'])) {
                $lines[] = 'Forecast (next days — predictions, not observations):';
                $n = min(5, count($daily['time']));
                for ($i = 0; $i < $n; $i++) {
                    $date = $daily['time'][$i] ?? '';
                    $tmax = $daily['temperature_2m_max'][$i] ?? 'n/a';
                    $tmin = $daily['temperature_2m_min'][$i] ?? 'n/a';
                    $rain = $daily['precipitation_sum'][$i] ?? 'n/a';
                    $pop = $daily['precipitation_probability_max'][$i] ?? null;
                    $wind = $daily['wind_speed_10m_max'][$i] ?? 'n/a';
                    $line = '- ' . $date . ': tmax ' . $tmax . 'C, tmin ' . $tmin . 'C, precip_mm ' . $rain . ', wind_max ' . $wind;
                    if ($pop !== null && $pop !== '') {
                        $line .= ', rain_chance_pct ' . $pop;
                    }
                    $lines[] = $line;
                }
            } else {
                $lines[] = 'Daily forecast arrays: not present in payload.';
            }

            if (method_exists('WeatherService', 'recommendations')) {
                $tips = WeatherService::recommendations($forecast, $farm);
                if ($tips) {
                    $lines[] = 'System weather tips (guidance only):';
                    foreach (array_slice($tips, 0, 4) as $tip) {
                        $lines[] = '- ' . ($tip[0] ?? 'Tip') . ': ' . ($tip[1] ?? '');
                    }
                }
            }
        } catch (Throwable $e) {
            GeminiService::logSafe('assistant_weather', 'Weather snapshot failed');
            $lines[] = 'Live weather: request failed. Do not invent forecast values.';
        }

        return $lines;
    }
}
