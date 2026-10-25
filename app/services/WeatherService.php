<?php

class WeatherService
{
    public static function fetchForFarm(array $farm): ?array
    {
        // Serve today's cached forecast for 30 minutes (skip Open-Meteo + DB write)
        $existing = db()->first(
            'SELECT id, raw_json, updated_at, created_at FROM weather_logs WHERE farm_id = ? AND log_date = ?',
            [$farm['id'], date('Y-m-d')]
        );
        if ($existing && !empty($existing['raw_json'])) {
            $stamp = strtotime((string) ($existing['updated_at'] ?: $existing['created_at'] ?: ''));
            if ($stamp && (time() - $stamp) < 1800) {
                $cached = json_decode((string) $existing['raw_json'], true);
                if (is_array($cached) && !empty($cached['current'])) {
                    return $cached;
                }
            }
        }

        $lat = $farm['latitude'] ?: -33.9321;
        $lon = $farm['longitude'] ?: 18.8602;
        $url = config('weather_api') . '?' . http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m,weather_code',
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,weather_code',
            'forecast_days' => 7,
            'timezone' => 'auto',
        ]);
        $json = self::httpGet($url);
        if (!$json) {
            return null;
        }
        $data = json_decode($json, true);
        if (!$data || empty($data['current'])) {
            return null;
        }
        $current = $data['current'];
        $alert = 'normal';
        $rain = (float) ($current['precipitation'] ?? 0);
        $wind = (float) ($current['wind_speed_10m'] ?? 0);
        $temp = (float) ($current['temperature_2m'] ?? 0);
        if ($wind >= 50 || $rain >= 20 || $temp >= 38 || $temp <= 0) {
            $alert = 'extreme';
        } elseif ($wind >= 35 || $rain >= 8 || $temp >= 34) {
            $alert = 'watch';
        }
        $row = [
            'farm_id' => $farm['id'],
            'log_date' => date('Y-m-d'),
            'temperature' => $temp,
            'humidity' => $current['relative_humidity_2m'] ?? null,
            'rainfall' => $rain,
            'wind_speed' => $wind,
            'conditions' => self::codeLabel($current['weather_code'] ?? 0),
            'alert_level' => $alert,
            'source' => 'api',
            'raw_json' => $json,
        ];
        if ($existing) {
            db()->update('weather_logs', $row, $existing['id']);
        } else {
            db()->insert('weather_logs', $row);
        }
        if ($alert !== 'normal') {
            NotificationService::create([
                'farm_id' => $farm['id'],
                'category' => 'weather',
                'title' => 'Weather risk: ' . $row['conditions'],
                'message' => 'Current conditions may affect field work and irrigation.',
                'severity' => $alert === 'extreme' ? 'danger' : 'warning',
                'link' => 'weather',
            ]);
        }
        return $data;
    }

    public static function recommendations(array $forecast, array $farm): array
    {
        $tips = [];
        $daily = $forecast['daily'] ?? [];
        $rain3 = 0;
        if (!empty($daily['precipitation_sum'])) {
            $rain3 = array_sum(array_slice($daily['precipitation_sum'], 0, 3));
        }
        $maxTemp = !empty($daily['temperature_2m_max']) ? max(array_slice($daily['temperature_2m_max'], 0, 3)) : 0;
        if ($rain3 < 2) {
            $tips[] = ['Irrigation', 'Little rainfall is forecast over the next 3 days. Prioritise drip irrigation on active plantings and check soil moisture before pumping.'];
        } else {
            $tips[] = ['Irrigation', 'Rain is expected. Delay non-essential irrigation to reduce water cost and avoid waterlogging.'];
        }
        if ($maxTemp >= 32) {
            $tips[] = ['Crops', 'High temperatures are forecast. Irrigate early morning, watch for heat stress, and avoid midday spraying.'];
        } else {
            $tips[] = ['Crops', 'Temperatures are moderate. Suitable window for planting, scouting and foliar applications.'];
        }
        $wind = !empty($daily['wind_speed_10m_max']) ? max(array_slice($daily['wind_speed_10m_max'], 0, 3)) : 0;
        if ($wind >= 30) {
            $tips[] = ['Spraying', 'Strong winds expected. Postpone chemical applications to prevent drift.'];
        } else {
            $tips[] = ['Spraying', 'Wind conditions look acceptable for scheduled crop-protection work.'];
        }
        $tips[] = ['Market', 'Review current crop prices before committing harvest volumes. Strong demand periods typically follow dry spells on staple crops.'];
        return $tips;
    }

    public static function codeLabel($code): string
    {
        $code = (int) $code;
        $map = [
            0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
            45 => 'Fog', 48 => 'Depositing rime fog',
            51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Dense drizzle',
            61 => 'Slight rain', 63 => 'Rain', 65 => 'Heavy rain',
            71 => 'Slight snow', 73 => 'Snow', 75 => 'Heavy snow',
            80 => 'Rain showers', 81 => 'Heavy showers', 95 => 'Thunderstorm',
        ];
        return $map[$code] ?? 'Mixed conditions';
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $res = curl_exec($ch);
            $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            return $ok ? $res : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res !== false ? $res : null;
    }
}
