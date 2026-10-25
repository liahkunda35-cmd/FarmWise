<?php

class CalendarService
{
    public static function events(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $from = $start->format('Y-m-d');
        $to = $end->format('Y-m-d');
        [$farmSql, $params] = scoped_farm_sql();
        $events = [];

        $add = function (array $rows, string $module, string $dateCol, string $titleCol, string $kind) use (&$events) {
            if (!Auth::can($module, 'view')) {
                return;
            }
            foreach ($rows as $row) {
                $date = $row[$dateCol] ?? null;
                if (!$date) {
                    continue;
                }
                $events[] = [
                    'date' => substr((string) $date, 0, 10),
                    'title' => $row[$titleCol] ?? ($module . ' #' . $row['id']),
                    'module' => $module,
                    'id' => $row['id'],
                    'kind' => $kind,
                    'status' => $row['status'] ?? '',
                    'url' => url('module/' . $module . '/view/' . $row['id']),
                ];
            }
        };

        if (table_exists('plantings') && Auth::can('plantings', 'view')) {
            $add(db()->select("SELECT * FROM plantings WHERE planting_date BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'plantings', 'planting_date', 'season', 'planting');
            $add(db()->select("SELECT * FROM plantings WHERE expected_harvest BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'plantings', 'expected_harvest', 'season', 'harvest');
        }
        if (table_exists('harvests') && Auth::can('harvests', 'view')) {
            $add(db()->select("SELECT * FROM harvests WHERE harvest_date BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'harvests', 'harvest_date', 'grade', 'harvest');
        }
        if (table_exists('tasks') && Auth::can('tasks', 'view')) {
            $add(db()->select("SELECT * FROM tasks WHERE due_date BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'tasks', 'due_date', 'title', 'task');
        }
        if (table_exists('irrigation_schedules') && Auth::can('irrigation_schedules', 'view')) {
            $add(db()->select("SELECT * FROM irrigation_schedules WHERE scheduled_date BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'irrigation_schedules', 'scheduled_date', 'recommendation', 'irrigation');
        }
        if (table_exists('spray_schedules') && Auth::can('spray_schedules', 'view')) {
            $add(db()->select("SELECT * FROM spray_schedules WHERE scheduled_date BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'spray_schedules', 'scheduled_date', 'product_name', 'spray');
        }
        if (table_exists('livestock_health') && Auth::can('livestock_health', 'view')) {
            $add(db()->select("SELECT * FROM livestock_health WHERE record_date BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'livestock_health', 'record_date', 'title', 'livestock');
            $add(db()->select("SELECT * FROM livestock_health WHERE next_due BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'livestock_health', 'next_due', 'title', 'livestock');
        }
        if (table_exists('breeding_records') && Auth::can('breeding_records', 'view')) {
            $add(db()->select("SELECT * FROM breeding_records WHERE expected_birth BETWEEN ? AND ? {$farmSql}", array_merge([$from, $to], $params)), 'breeding_records', 'expected_birth', 'status', 'livestock');
        }

        usort($events, fn($a, $b) => strcmp($a['date'] . $a['title'], $b['date'] . $b['title']));
        return $events;
    }
}
