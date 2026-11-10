<?php

class CalendarService
{
    public static function events(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if (!table_exists('calendar_events')) {
            return [];
        }
        $from = $start->format('Y-m-d');
        $to = $end->format('Y-m-d');
        [$farmSql, $params] = scoped_farm_sql();
        $rows = db()->select(
            "SELECT * FROM calendar_events WHERE event_date BETWEEN ? AND ? {$farmSql} ORDER BY event_date ASC, start_time ASC, id ASC",
            array_merge([$from, $to], $params)
        );
        $events = [];
        foreach ($rows as $row) {
            $events[] = self::formatEvent($row);
        }
        return $events;
    }

    public static function find(int $id): ?array
    {
        if (!table_exists('calendar_events') || $id <= 0) {
            return null;
        }
        $row = db()->find('calendar_events', $id);
        if (!$row) {
            return null;
        }
        if (!Auth::isAdmin()) {
            $farmId = (int) ($row['farm_id'] ?? 0);
            if ($farmId > 0 && !Auth::canAccessFarm($farmId)) {
                return null;
            }
            if ($farmId === 0 && (int) ($row['user_id'] ?? 0) !== (int) Auth::id()) {
                return null;
            }
        }
        return $row;
    }

    public static function formatEvent(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $time = trim((string) (($row['start_time'] ?? '') . (($row['end_time'] ?? '') ? '–' . $row['end_time'] : '')));
        $title = (string) ($row['title'] ?? 'Event');
        if ($time !== '' && $time !== '–') {
            $title = $time . ' ' . $title;
        }
        return [
            'id' => $id,
            'date' => substr((string) ($row['event_date'] ?? ''), 0, 10),
            'title' => $title,
            'raw_title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'module' => 'calendar',
            'kind' => (string) ($row['category'] ?? 'general'),
            'category' => (string) ($row['category'] ?? 'general'),
            'status' => '',
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'farm_id' => $row['farm_id'] ?? null,
            'reminder' => (int) ($row['reminder'] ?? 0),
            'url' => url('calendar?view=day&date=' . urlencode(substr((string) ($row['event_date'] ?? ''), 0, 10)) . '&event=' . $id),
            'edit_url' => url('calendar?event=' . $id),
        ];
    }

    public static function categories(): array
    {
        return [
            'general' => 'General',
            'planting' => 'Planting',
            'harvest' => 'Harvest',
            'livestock' => 'Livestock',
            'maintenance' => 'Maintenance',
            'meeting' => 'Meeting',
            'other' => 'Other',
        ];
    }

    public static function save(array $data, ?int $id = null): int
    {
        $payload = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'event_date' => $data['event_date'],
            'start_time' => $data['start_time'] ?: null,
            'end_time' => $data['end_time'] ?: null,
            'category' => $data['category'] ?: 'general',
            'farm_id' => $data['farm_id'] ?: null,
            'reminder' => !empty($data['reminder']) ? 1 : 0,
            'user_id' => (int) Auth::id(),
        ];
        if ($id) {
            db()->update('calendar_events', $payload, $id);
            return $id;
        }
        return db()->insert('calendar_events', $payload);
    }

    public static function delete(int $id): void
    {
        db()->delete('calendar_events', $id);
    }
}
