<?php

/**
 * Read-only farm snapshot for the authenticated AI assistant.
 * Kept lean and session-cached so chat turns do not stall the single-threaded PHP server.
 */
class FarmAssistantContext
{
    private const CACHE_TTL = 120;

    public static function snapshot(): string
    {
        if (!Auth::check()) {
            return 'No authenticated user.';
        }

        $key = 'ai_ctx:' . (int) Auth::id() . ':' . (int) (Auth::farmId() ?? 0) . ':' . (string) Auth::role();
        $cached = session_memo_get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $text = self::buildSnapshot();
        session_memo_set($key, $text, self::CACHE_TTL);
        return $text;
    }

    private static function buildSnapshot(): string
    {
        $lines = [];
        $user = Auth::user();
        $first = trim((string) preg_replace('/\s+/u', ' ', (string) ($user['name'] ?? '')));
        $first = $first !== '' ? (explode(' ', $first)[0] ?? '') : '';
        $lines[] = 'User first name: ' . ($first !== '' ? $first : 'unknown') . ' (role: ' . (Auth::role() ?? 'n/a') . ')';
        $farmId = Auth::farmId();
        $lines[] = 'Useful in-app destinations: /dashboard, /fields, /plantings, /animals, /tasks, /inventory, /harvests, /weather, /reports, /map';

        if (Auth::isAdmin() && !$farmId) {
            $farmCount = (int) db()->value('SELECT COUNT(*) FROM farms');
            $lines[] = 'Scope: administrator with no farm selected (' . $farmCount . ' farms). Ask them to select a farm before private records.';
            return implode("\n", $lines);
        }

        if (!$farmId) {
            $lines[] = 'No farm is currently selected for this user.';
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
        $lines[] = 'Location: ' . ((($farm['location'] ?? '') !== '') ? $farm['location'] : 'not set');
        if (isset($farm['latitude'], $farm['longitude']) && $farm['latitude'] !== '' && $farm['longitude'] !== null) {
            $lines[] = 'Map coordinates: ' . $farm['latitude'] . ', ' . $farm['longitude'];
        }
        if (Auth::can('farms', 'view') || Auth::can('fields', 'view')) {
            $lines[] = 'Total area (ha): ' . ($farm['total_area'] ?? '0');
        }

        $canFields = Auth::can('fields', 'view');
        $canPlant = Auth::can('plantings', 'view');
        $canAnimals = Auth::can('animals', 'view');
        $canInv = Auth::can('inventory_items', 'view');
        $canTasks = Auth::can('tasks', 'view');
        $canTx = Auth::can('transactions', 'view');
        $canHarvest = Auth::can('harvests', 'view');
        $canWeather = Auth::can('weather', 'view');

        // Single KPI round-trip
        $counts = db()->first(
            "SELECT
                (SELECT COUNT(*) FROM fields WHERE farm_id = ?) AS fields,
                (SELECT COUNT(*) FROM plantings WHERE farm_id = ? AND status IN ('planted','growing')) AS plantings,
                (SELECT COUNT(*) FROM animals WHERE farm_id = ? AND status = 'active') AS animals,
                (SELECT COUNT(*) FROM inventory_items WHERE farm_id = ?) AS inventory,
                (SELECT COUNT(*) FROM tasks WHERE farm_id = ? AND status != 'completed') AS open_tasks,
                (SELECT COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END),0) FROM transactions WHERE farm_id = ?) AS expense,
                (SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END),0) FROM transactions WHERE farm_id = ?) AS income",
            [$farmId, $farmId, $farmId, $farmId, $farmId, $farmId, $farmId]
        ) ?: [];

        if ($canFields) {
            $lines[] = 'Fields: ' . (int) ($counts['fields'] ?? 0);
        }
        if ($canPlant) {
            $lines[] = 'Active plantings: ' . (int) ($counts['plantings'] ?? 0);
        }
        if ($canAnimals) {
            $lines[] = 'Active animals: ' . (int) ($counts['animals'] ?? 0);
        }
        if ($canInv) {
            $lines[] = 'Inventory items: ' . (int) ($counts['inventory'] ?? 0);
        }
        if ($canTasks) {
            $lines[] = 'Open tasks: ' . (int) ($counts['open_tasks'] ?? 0);
        }
        if ($canTx) {
            $lines[] = 'Recorded expenses total: ' . number_format((float) ($counts['expense'] ?? 0), 2);
            $lines[] = 'Recorded income total: ' . number_format((float) ($counts['income'] ?? 0), 2);
        }

        if ($canPlant) {
            $plantings = db()->select(
                "SELECT p.season, p.growth_stage, p.status, p.planting_date, c.name AS crop
                 FROM plantings p
                 LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
                 WHERE p.farm_id = ?
                 ORDER BY p.id DESC LIMIT 5",
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

        if ($canTasks) {
            [$taskExtra, $taskParams] = TaskAccess::listConstraint();
            $tasks = db()->select(
                "SELECT title, due_date, status, priority FROM tasks
                 WHERE farm_id = ? AND status != 'completed'" . $taskExtra . '
                 ORDER BY due_date IS NULL, due_date ASC, id ASC LIMIT 3',
                array_merge([$farmId], $taskParams)
            );
            if ($tasks) {
                $lines[] = 'Upcoming / open tasks:';
                foreach ($tasks as $t) {
                    $lines[] = '- ' . ($t['title'] ?? 'Task') .
                        ' · due ' . (($t['due_date'] ?? '') !== '' ? $t['due_date'] : 'unset') .
                        ' · ' . ($t['status'] ?? '');
                }
            }
        }

        if ($canHarvest) {
            $harvests = db()->select(
                'SELECT harvest_date, quantity, unit, grade, status FROM harvests WHERE farm_id = ? ORDER BY harvest_date DESC LIMIT 3',
                [$farmId]
            );
            if ($harvests) {
                $lines[] = 'Recent harvests:';
                foreach ($harvests as $h) {
                    $lines[] = '- ' . ($h['harvest_date'] ?? '') . ': ' . ($h['quantity'] ?? '') . ' ' . ($h['unit'] ?? '') .
                        ' (' . ($h['status'] ?? '') . ')';
                }
            }
        }

        if ($canAnimals) {
            $animals = db()->select(
                "SELECT species, COUNT(*) AS total
                 FROM animals WHERE farm_id = ? AND status = 'active'
                 GROUP BY species ORDER BY total DESC LIMIT 4",
                [$farmId]
            );
            if ($animals) {
                $lines[] = 'Active livestock (grouped):';
                foreach ($animals as $a) {
                    $lines[] = '- ' . ($a['species'] ?? 'Animal') . ': ' . (int) ($a['total'] ?? 0);
                }
            }
        }

        if ($canWeather) {
            try {
                $wx = db()->first(
                    'SELECT temperature, humidity, rainfall, wind_speed, conditions, log_date
                     FROM weather_logs WHERE farm_id = ? ORDER BY log_date DESC, id DESC LIMIT 1',
                    [$farmId]
                );
                if ($wx) {
                    $lines[] = 'Latest stored weather (' . ($wx['log_date'] ?? 'n/a') . '): temp '
                        . ($wx['temperature'] ?? 'n/a') . ', ' . ($wx['conditions'] ?? 'n/a')
                        . ', humidity ' . ($wx['humidity'] ?? 'n/a')
                        . ', rain ' . ($wx['rainfall'] ?? 'n/a');
                    $lines[] = 'For a live forecast, send the user to /weather. Do not invent forecast numbers.';
                } else {
                    $lines[] = 'No stored weather log. Point the user to /weather after map location is set.';
                }
            } catch (Throwable $e) {
                $lines[] = 'Weather log unavailable right now.';
            }
        }

        if (Auth::can('market_insights', 'view') || Auth::can('market_prices', 'view')) {
            try {
                $lines[] = AgriPatternService::marketAssistantSnippet((int) $farmId);
            } catch (Throwable $e) {
                $lines[] = 'Market price context unavailable right now.';
            }
        }
        if (Auth::can('agri_history', 'view') || Auth::can('market_insights', 'view')) {
            try {
                $prov = (string) ($farm['province'] ?? '');
                $lines[] = AgriPatternService::assistantSnippet($prov !== '' ? $prov : null);
            } catch (Throwable $e) {
                $lines[] = 'ZamStats CFS context unavailable right now.';
            }
        }

        $lines[] = 'Note: figures above are from authorized FarmWise records only. Do not invent additional numbers. Cite ZamStats CFS / WFP-HDX / farmer sources when quoting prices or yields. Never invent missing seasons or rainfall.';
        return implode("\n", $lines);
    }
}




