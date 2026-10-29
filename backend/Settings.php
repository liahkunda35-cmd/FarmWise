<?php

class Settings
{
    public static function get(string $key, $default = null)
    {
        $row = db()->first('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        if (!$row || $row['setting_value'] === null || $row['setting_value'] === '') {
            return $default;
        }
        return $row['setting_value'];
    }

    public static function put(string $key, $value): void
    {
        $existing = db()->first('SELECT id FROM settings WHERE setting_key = ?', [$key]);
        if ($existing) {
            db()->update('settings', ['setting_value' => $value], $existing['id']);
            return;
        }
        db()->insert('settings', [
            'setting_key' => $key,
            'setting_value' => $value,
        ]);
    }

    public static function all(): array
    {
        $out = [];
        foreach (db()->select('SELECT setting_key, setting_value FROM settings') as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    public static function enabled(string $key): bool
    {
        return in_array((string) self::get($key, '0'), ['1', 'true', 'yes', 'on'], true);
    }
}
