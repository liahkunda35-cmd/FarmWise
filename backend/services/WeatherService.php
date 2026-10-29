<?php

class WeatherService
{
    private const CACHE_SECONDS = 1800;

    public static function apiKey(): string
    {
        $key = getenv('OPENWEATHER_API_KEY');
        if ($key === false || $key === null || $key === '') {
            $key = $_ENV['OPENWEATHER_API_KEY'] ?? $_SERVER['OPENWEATHER_API_KEY'] ?? '';
        }
        return is_string($key) ? trim($key) : '';
    }

    public static function fetchForFarm(array $farm): ?array
    {
        if ((string) (getenv('IFMS_TEST_CAPTURE') ?: '') === '1') {
            return self::withMeta(null, false, 'test_capture', 'Weather network calls are disabled during automated tests.');
        }
        $cached = self::readCache($farm);
        if ($cached && self::cacheIsFresh($cached['row'])) {
            $payload = $cached['payload'];
            $payload['meta'] = array_merge($payload['meta'] ?? [], [
                'cached' => true,
                'updated_at' => $cached['row']['updated_at'] ?? $cached['row']['created_at'] ?? null,
            ]);
            return $payload;
        }

        if (!self::hasCoordinates($farm)) {
            return self::withMeta($cached['payload'] ?? null, false, 'missing_location', 'Find your farm on the map (Farms → edit → Find on map) so weather can load for your area.');
        }

        if (self::apiKey() === '') {
            return self::withMeta($cached['payload'] ?? null, false, 'missing_key', 'Weather is not configured. Add OPENWEATHER_API_KEY on the server.');
        }

        $lat = (float) $farm['latitude'];
        $lon = (float) $farm['longitude'];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return self::withMeta($cached['payload'] ?? null, false, 'invalid_location', 'The saved map pin looks wrong. Open the farm and place the pin again.');
        }
        $precision = (string) ($farm['location_precision'] ?? 'approximate');

        $currentRaw = self::httpGetJson(self::endpointUrl('weather', $lat, $lon));
        $forecastRaw = self::httpGetJson(self::endpointUrl('forecast', $lat, $lon));

        if ($currentRaw === null && $forecastRaw === null) {
            return self::withMeta($cached['payload'] ?? null, false, 'api_error', 'Live weather could not be reached. Showing the last saved reading if available.');
        }

        $normalized = self::normalizeOpenWeather($currentRaw, $forecastRaw, $lat, $lon);
        if (empty($normalized['current'])) {
            return self::withMeta($cached['payload'] ?? null, false, 'api_error', 'Weather data was incomplete for this location. Try again shortly.');
        }

        self::persistLog($farm, $normalized, $cached['row'] ?? null);
        $msg = null;
        if ($precision === 'approximate') {
            $place = trim((string) ($farm['location_label'] ?? $farm['town'] ?? $farm['location'] ?? 'your town'));
            $msg = 'Weather is for the town/village area (' . $place . '), not an exact farm pin. Drag the pin on the farm map for a closer match.';
        }
        $normalized['meta'] = [
            'ok' => true,
            'error' => null,
            'message' => $msg,
            'location_precision' => $precision,
            'source' => 'openweather',
            'cached' => false,
            'updated_at' => date('c'),
        ];
        return $normalized;
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
        if (is_string($code) && $code !== '' && !is_numeric($code)) {
            return ucfirst($code);
        }
        $code = (int) $code;
        // OpenWeather condition ids
        if ($code >= 200 && $code <= 804) {
            return self::openWeatherLabel($code);
        }
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

    private static function openWeatherLabel(int $id): string
    {
        return match (true) {
            $id >= 200 && $id < 300 => 'Thunderstorm',
            $id >= 300 && $id < 400 => 'Drizzle',
            $id >= 500 && $id < 600 => 'Rain',
            $id >= 600 && $id < 700 => 'Snow',
            $id >= 700 && $id < 800 => 'Atmosphere',
            $id === 800 => 'Clear sky',
            $id === 801 => 'Few clouds',
            $id === 802 => 'Scattered clouds',
            $id === 803 => 'Broken clouds',
            $id === 804 => 'Overcast clouds',
            default => 'Mixed conditions',
        };
    }

    private static function hasCoordinates(array $farm): bool
    {
        return isset($farm['latitude'], $farm['longitude'])
            && $farm['latitude'] !== '' && $farm['longitude'] !== ''
            && $farm['latitude'] !== null && $farm['longitude'] !== null
            && is_numeric($farm['latitude']) && is_numeric($farm['longitude']);
    }

    private static function endpointUrl(string $path, float $lat, float $lon): string
    {
        $base = $path === 'forecast'
            ? (string) (config('weather.forecast_url') ?: 'https://api.openweathermap.org/data/2.5/forecast')
            : (string) (config('weather.current_url') ?: 'https://api.openweathermap.org/data/2.5/weather');
        // Key is only appended server-side; never returned to the browser.
        return $base . '?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'units' => 'metric',
            'appid' => self::apiKey(),
        ]);
    }

    private static function normalizeOpenWeather(?array $current, ?array $forecast, float $lat, float $lon): array
    {
        $currentBlock = null;
        if (is_array($current) && isset($current['main'])) {
            $wx = $current['weather'][0] ?? [];
            $desc = (string) ($wx['description'] ?? self::codeLabel((int) ($wx['id'] ?? 0)));
            $rain = (float) ($current['rain']['1h'] ?? $current['rain']['3h'] ?? 0);
            $windMs = (float) ($current['wind']['speed'] ?? 0);
            $currentBlock = [
                'time' => isset($current['dt']) ? date('c', (int) $current['dt']) : date('c'),
                'temperature_2m' => round((float) ($current['main']['temp'] ?? 0), 1),
                'relative_humidity_2m' => (int) ($current['main']['humidity'] ?? 0),
                'precipitation' => round($rain, 2),
                'wind_speed_10m' => round($windMs * 3.6, 1), // m/s → km/h for existing UI
                'weather_code' => (int) ($wx['id'] ?? 0),
                'weather_description' => $desc,
            ];
        }

        $daily = [
            'time' => [],
            'temperature_2m_max' => [],
            'temperature_2m_min' => [],
            'precipitation_sum' => [],
            'wind_speed_10m_max' => [],
            'weather_code' => [],
        ];

        if (is_array($forecast) && !empty($forecast['list']) && is_array($forecast['list'])) {
            $buckets = [];
            foreach ($forecast['list'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $day = isset($item['dt_txt'])
                    ? substr((string) $item['dt_txt'], 0, 10)
                    : date('Y-m-d', (int) ($item['dt'] ?? time()));
                if (!isset($buckets[$day])) {
                    $buckets[$day] = [
                        'temps' => [],
                        'rain' => 0.0,
                        'winds' => [],
                        'codes' => [],
                    ];
                }
                $buckets[$day]['temps'][] = (float) ($item['main']['temp'] ?? 0);
                $buckets[$day]['rain'] += (float) ($item['rain']['3h'] ?? 0);
                $buckets[$day]['winds'][] = ((float) ($item['wind']['speed'] ?? 0)) * 3.6;
                $buckets[$day]['codes'][] = (int) ($item['weather'][0]['id'] ?? 0);
            }
            $i = 0;
            foreach ($buckets as $day => $b) {
                if ($i >= 5) {
                    break;
                }
                $daily['time'][] = $day;
                $daily['temperature_2m_max'][] = round(max($b['temps'] ?: [0]), 1);
                $daily['temperature_2m_min'][] = round(min($b['temps'] ?: [0]), 1);
                $daily['precipitation_sum'][] = round($b['rain'], 2);
                $daily['wind_speed_10m_max'][] = round(max($b['winds'] ?: [0]), 1);
                $daily['weather_code'][] = $b['codes'][0] ?? 0;
                $i++;
            }
        }

        return [
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => $currentBlock,
            'daily' => $daily['time'] ? $daily : null,
            'source' => 'openweather',
        ];
    }

    private static function persistLog(array $farm, array $normalized, ?array $existing): void
    {
        $current = $normalized['current'] ?? [];
        $temp = (float) ($current['temperature_2m'] ?? 0);
        $rain = (float) ($current['precipitation'] ?? 0);
        $wind = (float) ($current['wind_speed_10m'] ?? 0);
        $alert = 'normal';
        if ($wind >= 50 || $rain >= 20 || $temp >= 38 || $temp <= 0) {
            $alert = 'extreme';
        } elseif ($wind >= 35 || $rain >= 8 || $temp >= 34) {
            $alert = 'watch';
        }
        $conditions = (string) ($current['weather_description'] ?? self::codeLabel($current['weather_code'] ?? 0));
        $row = [
            'farm_id' => $farm['id'],
            'log_date' => date('Y-m-d'),
            'temperature' => $temp,
            'humidity' => $current['relative_humidity_2m'] ?? null,
            'rainfall' => $rain,
            'wind_speed' => $wind,
            'conditions' => $conditions,
            'alert_level' => $alert,
            'source' => 'openweather',
            'raw_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
        ];
        if ($existing && !empty($existing['id'])) {
            db()->update('weather_logs', $row, $existing['id']);
        } else {
            $prior = db()->first(
                'SELECT id FROM weather_logs WHERE farm_id = ? AND log_date = ?',
                [$farm['id'], date('Y-m-d')]
            );
            if ($prior) {
                db()->update('weather_logs', $row, $prior['id']);
            } else {
                db()->insert('weather_logs', $row);
            }
        }
        if ($alert !== 'normal') {
            NotificationService::create([
                'farm_id' => $farm['id'],
                'category' => 'weather',
                'title' => 'Weather risk: ' . $conditions,
                'message' => 'Current conditions may affect field work and irrigation.',
                'severity' => $alert === 'extreme' ? 'danger' : 'warning',
                'link' => 'weather',
            ]);
        }
    }

    private static function readCache(array $farm): ?array
    {
        $existing = db()->first(
            'SELECT id, raw_json, updated_at, created_at, temperature, humidity, rainfall, wind_speed, conditions FROM weather_logs WHERE farm_id = ? AND log_date = ?',
            [$farm['id'], date('Y-m-d')]
        );
        if (!$existing) {
            $existing = db()->first(
                'SELECT id, raw_json, updated_at, created_at, temperature, humidity, rainfall, wind_speed, conditions FROM weather_logs WHERE farm_id = ? ORDER BY log_date DESC, id DESC',
                [$farm['id']]
            );
        }
        if (!$existing) {
            return null;
        }
        $payload = null;
        if (!empty($existing['raw_json'])) {
            $decoded = json_decode((string) $existing['raw_json'], true);
            if (is_array($decoded) && !empty($decoded['current'])) {
                $payload = $decoded;
            }
        }
        if (!$payload && isset($existing['temperature'])) {
            $payload = [
                'current' => [
                    'temperature_2m' => $existing['temperature'],
                    'relative_humidity_2m' => $existing['humidity'],
                    'precipitation' => $existing['rainfall'],
                    'wind_speed_10m' => $existing['wind_speed'],
                    'weather_code' => 0,
                    'weather_description' => $existing['conditions'] ?: 'Mixed conditions',
                    'time' => $existing['updated_at'] ?? $existing['created_at'] ?? null,
                ],
                'daily' => null,
                'source' => 'cache',
            ];
        }
        return $payload ? ['row' => $existing, 'payload' => $payload] : null;
    }

    private static function cacheIsFresh(array $row): bool
    {
        $stamp = strtotime((string) ($row['updated_at'] ?: $row['created_at'] ?: ''));
        return $stamp && (time() - $stamp) < self::CACHE_SECONDS;
    }

    private static function withMeta(?array $payload, bool $ok, string $error, string $message): array
    {
        $base = is_array($payload) ? $payload : ['current' => null, 'daily' => null];
        $base['meta'] = [
            'ok' => $ok,
            'error' => $error,
            'message' => $message,
            'source' => $payload['source'] ?? 'openweather',
            'cached' => !empty($payload['current']),
            'stale' => !empty($payload['current']),
            'updated_at' => $payload['current']['time'] ?? null,
        ];
        return $base;
    }

    /**
     * @return array|null Decoded JSON body, or null on failure. Never logs the request URL (contains the API key).
     */
    private static function httpGetJson(string $url): ?array
    {
        $body = null;
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            if (class_exists('SslCa')) {
                SslCa::applyCurl($ch);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            }
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                return null;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'header' => "Accept: application/json\r\n",
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            if ($body === false) {
                return null;
            }
        }
        if ($status !== 200) {
            return null;
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }
}
