<?php

function config($key = null, $default = null)
{
    static $cfg;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config/config.php';
    }
    if ($key === null) {
        return $cfg;
    }
    $parts = explode('.', $key);
    $val = $cfg;
    foreach ($parts as $p) {
        if (!is_array($val) || !array_key_exists($p, $val)) {
            return $default;
        }
        $val = $val[$p];
    }
    return $val;
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Temporarily off — set IFMS_FARM_CODE=1 in .env to show Farm Code UI again. */
function farm_code_feature_enabled(): bool
{
    $raw = strtolower(trim((string) (getenv('IFMS_FARM_CODE') ?: ($_ENV['IFMS_FARM_CODE'] ?? '0'))));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function url($path = ''): string
{
    $path = ltrim((string) $path, '/');
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($base === '/' || $base === '\\' || $base === '.') {
        $base = '';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

function absolute_url($path = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host . url($path);
}

function asset($path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function brand_logo_url(): string
{
    return asset('img/farmwise-logo.jpg');
}

function brand_logo(string $class = 'brand-logo'): string
{
    return '<img class="' . e($class) . '" src="' . e(brand_logo_url()) . '" alt="FarmWise – Farm Management System" width="1024" height="1024">';
}

function page_title(?string $page = null): string
{
    $brand = 'FarmWise | Farm Management System';
    $page = trim((string) $page);
    return $page === '' ? $brand : $page . ' | ' . $brand;
}

function brand_head(?string $page = null): string
{
    $icon = e(brand_logo_url());
    return '<title>' . e(page_title($page)) . '</title>' . "\n"
        . '    <link rel="icon" type="image/jpeg" href="' . $icon . '">' . "\n"
        . '    <link rel="apple-touch-icon" href="' . $icon . '">';
}

function password_input(array $attrs = []): string
{
    $name = $attrs['name'] ?? 'password';
    $required = !empty($attrs['required']) ? ' required' : '';
    $autocomplete = e($attrs['autocomplete'] ?? 'current-password');
    $extra = $attrs['extra'] ?? '';
    $id = e($attrs['id'] ?? $name);
    $placeholder = isset($attrs['placeholder']) ? ' placeholder="' . e((string) $attrs['placeholder']) . '"' : '';
    $eye = '<svg class="eye-on" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
    $eyeOff = '<svg class="eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.1A10.6 10.6 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.2 3.1"/><path d="M6.6 6.6C4.1 8.3 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.4-1"/></svg>';
    return '<div class="password-wrap">'
        . '<input type="password" id="' . $id . '" name="' . e($name) . '" autocomplete="' . $autocomplete . '"' . $placeholder . $required . ' ' . $extra . '>'
        . '<button type="button" class="password-toggle" data-toggle-password aria-label="Show password" aria-pressed="false">' . $eye . $eyeOff . '</button>'
        . '</div>';
}

function redirect($path, $code = 302): void
{
    if ((string) (getenv('IFMS_TEST_CAPTURE') ?: '') === '1') {
        $GLOBALS['ifms_test_exit'] = ['type' => 'redirect', 'path' => (string) $path, 'code' => (int) $code];
        throw new RuntimeException('IFMS_TEST_EXIT');
    }
    header('Location: ' . url($path), true, $code);
    exit;
}

function flash($key, $message = null)
{
    if ($message === null) {
        $val = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $val;
    }
    $_SESSION['_flash'][$key] = $message;
}

function flashes(): array
{
    $all = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $all;
}

function old($key, $default = '')
{
    $old = $_SESSION['_old'] ?? [];
    return $old[$key] ?? $default;
}

function remember_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function user_initials(?array $user = null): string
{
    $user = $user ?: Auth::user();
    $name = trim((string) ($user['name'] ?? 'U'));
    $parts = preg_split('/\s+/', $name) ?: [];
    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $last === '' ? $first : $first . $last;
}

function user_first_name(?array $user = null): string
{
    $user = $user ?: Auth::user();
    $name = trim((string) ($user['name'] ?? 'there'));
    return explode(' ', $name)[0] ?: 'there';
}

function time_greeting(?int $hour = null): string
{
    if ($hour === null) {
        try {
            $tzName = app_timezone();
            $tz = @timezone_open($tzName) ?: timezone_open('Africa/Lusaka');
            $hour = (int) (new DateTimeImmutable('now', $tz ?: new DateTimeZone('Africa/Lusaka')))->format('G');
        } catch (Throwable $e) {
            $hour = (int) date('G');
        }
    }
    if ($hour < 12) {
        return 'Good morning';
    }
    if ($hour < 17) {
        return 'Good afternoon';
    }
    return 'Good evening';
}

function relative_time($value): string
{
    if (!$value) {
        return '';
    }
    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $n = (int) floor($diff / 60);
        return $n . ' minute' . ($n === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $n = (int) floor($diff / 3600);
        return $n . ' hour' . ($n === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $n = (int) floor($diff / 86400);
        return $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
    }
    return dater($value, 'd M Y');
}

function weather_icon(?string $conditions): string
{
    $kind = weather_icon_kind(null, $conditions);
    return match ($kind) {
        'thunder' => 'cloud-lightning',
        'snow' => 'snowflake',
        'heavy_rain', 'rain', 'drizzle' => 'cloud-rain',
        'fog' => 'cloud-fog',
        'cloudy' => 'cloud',
        'sunny' => 'sun',
        'wind' => 'wind',
        'humidity' => 'droplets',
        default => 'cloud-sun',
    };
}

/** Map OpenWeather / WMO codes and free-text conditions to a stable icon kind. */
function weather_icon_kind($code = null, ?string $conditions = null): string
{
    $c = strtolower(trim((string) $conditions));
    if ($c === 'humidity') {
        return 'humidity';
    }
    if ($c === 'wind') {
        return 'wind';
    }
    if ($c === 'rain' || $c === 'rainfall') {
        return 'rain';
    }
    if (is_numeric($code)) {
        $id = (int) $code;
        if ($id >= 200 && $id < 300) {
            return 'thunder';
        }
        if ($id >= 300 && $id < 400) {
            return 'drizzle';
        }
        if ($id >= 500 && $id < 520) {
            return $id >= 502 ? 'heavy_rain' : 'rain';
        }
        if ($id >= 520 && $id < 600) {
            return 'heavy_rain';
        }
        if ($id >= 600 && $id < 700) {
            return 'snow';
        }
        if ($id >= 700 && $id < 800) {
            return 'fog';
        }
        if ($id === 800) {
            return 'sunny';
        }
        if ($id === 801 || $id === 802) {
            return 'partly';
        }
        if ($id === 803 || $id === 804) {
            return 'cloudy';
        }
        // WMO open-meteo style
        if ($id === 0 || $id === 1) {
            return 'sunny';
        }
        if ($id === 2) {
            return 'partly';
        }
        if ($id === 3) {
            return 'cloudy';
        }
        if ($id === 45 || $id === 48) {
            return 'fog';
        }
        if (in_array($id, [51, 53, 55, 56, 57], true)) {
            return 'drizzle';
        }
        if (in_array($id, [61, 63, 80, 81], true)) {
            return 'rain';
        }
        if (in_array($id, [65, 82], true)) {
            return 'heavy_rain';
        }
        if (in_array($id, [71, 73, 75, 77, 85, 86], true)) {
            return 'snow';
        }
        if (in_array($id, [95, 96, 99], true)) {
            return 'thunder';
        }
    }
    if ($c !== '') {
        if (str_contains($c, 'thunder') || str_contains($c, 'storm')) {
            return 'thunder';
        }
        if (str_contains($c, 'snow') || str_contains($c, 'sleet')) {
            return 'snow';
        }
        if (str_contains($c, 'heavy rain') || str_contains($c, 'torrent')) {
            return 'heavy_rain';
        }
        if (str_contains($c, 'drizzle')) {
            return 'drizzle';
        }
        if (str_contains($c, 'rain') || str_contains($c, 'shower')) {
            return 'rain';
        }
        if (str_contains($c, 'fog') || str_contains($c, 'mist') || str_contains($c, 'haze') || str_contains($c, 'smoke')) {
            return 'fog';
        }
        if (str_contains($c, 'overcast') || str_contains($c, 'broken cloud') || $c === 'cloudy') {
            return 'cloudy';
        }
        if (str_contains($c, 'few cloud') || str_contains($c, 'scatter') || str_contains($c, 'partly')) {
            return 'partly';
        }
        if (str_contains($c, 'clear') || str_contains($c, 'sun')) {
            return 'sunny';
        }
        if (str_contains($c, 'cloud')) {
            return 'cloudy';
        }
    }
    return 'partly';
}

/** Inline SVG weather illustration for KPI cards and forecast rows. */
function weather_icon_svg($code = null, ?string $conditions = null): string
{
    $kind = weather_icon_kind($code, $conditions);
    $sun = '<circle cx="32" cy="28" r="10" fill="#f0c14a"/><g stroke="#f0c14a" stroke-width="2.5" stroke-linecap="round"><path d="M32 10v4M32 42v4M14 28h4M46 28h4M18.5 14.5l2.8 2.8M42.7 38.7l2.8 2.8M18.5 41.5l2.8-2.8M42.7 17.3l2.8-2.8"/></g>';
    $cloud = '<path d="M22 40h24a10 10 0 0 0 1-20 14 14 0 0 0-27-3 9 9 0 0 0 2 23z" fill="#8fa3b8"/>';
    $cloudDark = '<path d="M22 40h24a10 10 0 0 0 1-20 14 14 0 0 0-27-3 9 9 0 0 0 2 23z" fill="#5d6f82"/>';
    $drops = '<g fill="#4a90c8"><path d="M26 44c0 2.2-1.6 3.5-3 3.5s-3-1.3-3-3.5 3-6 3-6 3 3.8 3 6z"/><path d="M36 46c0 2.2-1.6 3.5-3 3.5s-3-1.3-3-3.5 3-6 3-6 3 3.8 3 6z"/><path d="M46 44c0 2.2-1.6 3.5-3 3.5s-3-1.3-3-3.5 3-6 3-6 3 3.8 3 6z"/></g>';
    $heavyDrops = '<g fill="#356f9c"><path d="M24 42c0 2.4-1.8 3.8-3.2 3.8S17.5 44.4 17.5 42 21 34 21 34s3 5.6 3 8z"/><path d="M34 45c0 2.4-1.8 3.8-3.2 3.8S27.5 47.4 27.5 45 31 37 31 37s3 5.6 3 8z"/><path d="M44 42c0 2.4-1.8 3.8-3.2 3.8S37.5 44.4 37.5 42 41 34 41 34s3 5.6 3 8z"/><path d="M29 48c0 2-1.4 3.2-2.6 3.2S23.8 50 23.8 48 27 42 27 42s2 4.2 2 6z"/><path d="M39 49c0 2-1.4 3.2-2.6 3.2S33.8 51 33.8 49 37 43 37 43s2 4.2 2 6z"/></g>';
    $bolt = '<path d="M34 28l-6 12h6l-2 12 10-16h-6l4-8z" fill="#f0c14a"/>';
    $mist = '<g stroke="#9aabbc" stroke-width="2.5" stroke-linecap="round"><path d="M16 36h32M14 42h28M20 48h24"/></g>';
    $snow = '<g fill="#c5d4e0" stroke="#9eb4c7" stroke-width="1"><circle cx="24" cy="46" r="2.2"/><circle cx="34" cy="50" r="2.2"/><circle cx="44" cy="46" r="2.2"/><circle cx="29" cy="42" r="1.6"/><circle cx="39" cy="44" r="1.6"/></g>';
    $body = match ($kind) {
        'sunny' => $sun,
        'partly' => '<g transform="translate(-4,-4) scale(.78)">' . $sun . '</g>' . $cloud,
        'cloudy' => $cloud,
        'drizzle' => $cloud . '<g opacity=".75">' . $drops . '</g>',
        'rain' => $cloud . $drops,
        'heavy_rain' => $cloudDark . $heavyDrops,
        'thunder' => $cloudDark . $bolt . '<g opacity=".7">' . $drops . '</g>',
        'fog' => $cloud . $mist,
        'snow' => $cloud . $snow,
        'humidity' => '<ellipse cx="32" cy="22" rx="10" ry="14" fill="#4a90c8"/><path d="M22 22c0 12 20 12 20 0" fill="#4a90c8"/><circle cx="32" cy="40" r="10" fill="#6aa8d8" opacity=".35"/>',
        'wind' => '<g fill="none" stroke="#5d6f82" stroke-width="3" stroke-linecap="round"><path d="M10 28h28a8 8 0 1 0-2-15"/><path d="M10 38h34a6 6 0 1 1-1.5 11"/><path d="M10 48h22"/></g>',
        default => $cloud,
    };
    return '<svg class="wx-svg wx-' . e($kind) . '" viewBox="0 0 64 64" width="48" height="48" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

function record_screen_url(?string $module, $recordId = null, ?string $action = null, ?string $details = null): string
{
    $module = (string) $module;
    $details = strtolower((string) $details);
    $id = (int) $recordId;
    if ($module === 'auth' || $module === 'users') {
        return $id > 0 ? url('users/edit/' . $id) : url('users');
    }
    if ($module === 'backups' || $module === 'backup' || str_contains($details, 'backup') || ($module === 'system' && str_contains($details, 'restor'))) {
        return url('backup');
    }
    $pages = [
        'audit_logs' => 'audit',
        'system' => 'audit',
        'notifications' => 'notifications',
        'weather' => 'weather',
        'reports' => 'reports',
        'settings' => 'settings',
        'integrations' => 'integrations',
        'messages' => 'messages',
        'calendar' => 'calendar',
        'yield_forecasts' => 'forecasts',
        'market_insights' => 'market-insights',
        'dashboard' => 'dashboard',
        'profile' => 'profile',
    ];
    if (isset($pages[$module])) {
        return url($pages[$module]);
    }
    if ($module === '') {
        return url('audit');
    }
    if ($id > 0) {
        return url('module/' . $module . '/view/' . $id);
    }
    return url('module/' . $module);
}

function can_see_nav(array $item): bool
{
    $key = $item['key'] ?? '';
    if ($key === '' || $key === 'profile' || ($item['href'] ?? '') === 'profile') {
        return Auth::check();
    }
    if ($key === 'dashboard') {
        return Auth::check();
    }
    return Auth::can($key, 'view');
}

function money($amount): string
{
    return app_currency_symbol() . number_format((float) $amount, 2);
}

function app_currency(): string
{
    try {
        return (string) (Settings::get('currency', config('currency')) ?: config('currency'));
    } catch (Throwable $e) {
        return (string) config('currency');
    }
}

function app_currency_symbol(): string
{
    try {
        return (string) (Settings::get('currency_symbol', config('currency_symbol')) ?: config('currency_symbol'));
    } catch (Throwable $e) {
        return (string) config('currency_symbol');
    }
}

function app_timezone(): string
{
    try {
        $tz = Settings::get('timezone', config('timezone'));
        return $tz ?: (string) config('timezone');
    } catch (Throwable $e) {
        return (string) config('timezone');
    }
}

function supported_currencies(): array
{
    return [
        'ZMW' => ['label' => 'Zambian Kwacha', 'symbol' => 'K'],
        'ZAR' => ['label' => 'South African Rand', 'symbol' => 'R'],
        'USD' => ['label' => 'US Dollar', 'symbol' => '$'],
        'EUR' => ['label' => 'Euro', 'symbol' => '€'],
        'GBP' => ['label' => 'British Pound', 'symbol' => '£'],
        'BWP' => ['label' => 'Botswana Pula', 'symbol' => 'P'],
        'MWK' => ['label' => 'Malawian Kwacha', 'symbol' => 'MK'],
    ];
}

function supported_timezones(): array
{
    return [
        'Africa/Johannesburg' => 'Africa/Johannesburg (CAT)',
        'Africa/Lusaka' => 'Africa/Lusaka (CAT)',
        'Africa/Harare' => 'Africa/Harare (CAT)',
        'Africa/Nairobi' => 'Africa/Nairobi (EAT)',
        'Africa/Lagos' => 'Africa/Lagos (WAT)',
        'UTC' => 'UTC',
        'Europe/London' => 'Europe/London',
    ];
}

function qty($n, $decimals = 2): string
{
    return rtrim(rtrim(number_format((float) $n, $decimals), '0'), '.');
}

function dater($value, $format = 'd M Y'): string
{
    if (!$value) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable((string) $value, new DateTimeZone(app_timezone()));
        return $dt->format($format);
    } catch (Throwable $e) {
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        return $ts ? date($format, $ts) : '—';
    }
}

/**
 * ZICTA mobile NDCs for Airtel / MTN / Zamtel (9-digit national numbers).
 * Airtel: 57, 77, 97 · MTN: 56, 76, 96 · Zamtel: 55, 75, 95
 * @return list<string>
 */
function zambia_mobile_prefixes(): array
{
    return ['55', '56', '57', '75', '76', '77', '95', '96', '97'];
}

/**
 * True when the value contains characters other than digits, optional leading +,
 * spaces, or hyphens (letters and other symbols are not formatting).
 */
function phone_has_invalid_chars(string $phone): bool
{
    $raw = trim($phone);
    if ($raw === '') {
        return false;
    }
    return (bool) preg_match('/[^\d+\s\-]/u', $raw);
}

/**
 * Normalize Zambian mobile input to E.164 (+260XXXXXXXXX).
 * Only strips spaces and hyphens. Does not remove letters — those fail validation.
 * Accepts 097… / 075…, 26097…, and +26097… for allocated NDCs.
 */
function normalize_phone(string $phone): string
{
    $raw = trim($phone);
    if ($raw === '') {
        return '';
    }
    if (phone_has_invalid_chars($raw)) {
        return $raw;
    }
    $plus = str_starts_with($raw, '+');
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    // Local trunk: 0 + 9 digits (e.g. 0971234567)
    if (preg_match('/^0\d{9}$/', $digits)) {
        return '+260' . substr($digits, 1);
    }
    // National significant number only: 9 digits without trunk 0 (e.g. 971234567)
    if (preg_match('/^[1-9]\d{8}$/', $digits)) {
        return '+260' . $digits;
    }
    // With country code
    if (preg_match('/^260\d{9}$/', $digits)) {
        return '+' . $digits;
    }
    if ($plus && $digits !== '') {
        return '+' . $digits;
    }
    return $digits;
}

/**
 * Validate a Zambian mobile number (Airtel / MTN / Zamtel).
 * Local form must be 10 digits starting with 0 + allocated NDC (07x / 09x / 05x reserved blocks).
 */
function valid_phone(string $phone): bool
{
    if (trim($phone) === '') {
        return false;
    }
    if (phone_has_invalid_chars($phone)) {
        return false;
    }
    $n = normalize_phone($phone);
    if (!preg_match('/^\+260(\d{9})$/', $n, $m)) {
        return false;
    }
    $ndc = substr($m[1], 0, 2);
    return in_array($ndc, zambia_mobile_prefixes(), true);
}

function phone_validation_message(): string
{
    return 'Enter a valid Zambian mobile number for Airtel, MTN or Zamtel.';
}

/**
 * Strip technical/internal details before showing an error to end users.
 */
function user_safe_message(string $message, string $fallback = 'Something went wrong. Please try again later.'): string
{
    $message = trim($message);
    if ($message === '') {
        return $fallback;
    }
    $technical = '/(sqlstate|pdoexception|stack trace|exception|fatal error|invalid api key|curl error|connection refused|undefined (index|variable|offset)|on line \d+|\/(app|vendor|xampp|users)\b|Bearer\s|npg_|password=|postgresql:\/\/|mysql:\/\/)/i';
    if (preg_match($technical, $message)) {
        return $fallback;
    }
    // Cap length so huge dumps never reach the UI
    if (mb_strlen($message) > 220) {
        return $fallback;
    }
    return $message;
}

/** Remove connection strings and credentials before writing a server log. */
function redact_for_log(string $message): string
{
    $message = preg_replace('#\b((?:postgres|postgresql|mysql|sqlite)://)\S+#i', '$1[redacted]', $message) ?? $message;
    $message = preg_replace('/(password|passwd|pwd|secret|api[_-]?key|token|bearer)\s*[=:]\s*\S+/i', '$1=[redacted]', $message) ?? $message;
    $message = preg_replace('/\b(?:npg|sk|AIza)_[A-Za-z0-9_\-]+/', '[redacted]', $message) ?? $message;
    return $message;
}

/** Record a failure for operators. The browser must not receive this text. */
function log_internal_error(string $context, Throwable $e): void
{
    $line = '[' . date('c') . '] ' . $context . ' ' . $e::class . ': ' . redact_for_log($e->getMessage())
        . ' @ ' . $e->getFile() . ':' . $e->getLine();
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    error_log($line);
}

/**
 * SQL predicate for the signed-in farm.
 * Administrators with no farm selected may see every farm.
 * Everyone else with no active farm matches nothing.
 *
 * @return array{0:string,1:array}
 */
function active_farm_constraint(string $column = 'farm_id'): array
{
    $farmId = Auth::farmId();
    if ($farmId) {
        return [$column . ' = ?', [$farmId]];
    }
    if (Auth::isAdmin()) {
        return ['1=1', []];
    }
    return ['1=0', []];
}

/**
 * Which farm profile rows this user may list.
 *
 * @return array{0:string,1:array}
 */
function farm_directory_constraint(): array
{
    if (Auth::isAdmin() && !Auth::farmId()) {
        return ['1=1', []];
    }
    if (Auth::farmId() && (Auth::isAdmin() || Auth::canAccessFarm((int) Auth::farmId()))) {
        return ['id = ?', [(int) Auth::farmId()]];
    }
    if (Auth::isAdmin()) {
        return ['1=1', []];
    }
    $ids = [];
    foreach (Auth::accessibleFarms() as $farm) {
        $ids[] = (int) $farm['id'];
    }
    if ($ids === []) {
        return ['1=0', []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return ['id IN (' . $placeholders . ')', $ids];
}

function farm_code_prefix(?string $farmName): string
{
    $name = trim((string) $farmName);
    if ($name === '') {
        return '';
    }
    $clean = preg_replace('/[^A-Za-z0-9\s]/', ' ', $name) ?? '';
    $words = preg_split('/\s+/', trim($clean)) ?: [];
    $letters = '';
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $letters .= strtoupper($word[0]);
        if (strlen($letters) >= 4) {
            break;
        }
    }
    if (strlen($letters) < 2) {
        $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
        $letters = substr($compact, 0, 3);
    }
    return substr($letters, 0, 4);
}

/**
 * Unique farm code. A name prefix is fixed at creation and is not rebuilt when the farm is renamed.
 */
function generate_join_code(?string $farmName = null): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $prefix = farm_code_prefix($farmName);
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $suffix = '';
        $length = $prefix !== '' ? 6 : 8;
        for ($i = 0; $i < $length; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = $prefix !== '' ? ($prefix . '-' . $suffix) : $suffix;
        $exists = db()->first('SELECT id FROM farms WHERE upper(join_code) = ?', [$code]);
        if (!$exists) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique farm code.');
}

function normalize_farm_code(string $code): string
{
    return strtoupper(trim($code));
}

function find_farm_by_code(string $code): ?array
{
    $code = normalize_farm_code($code);
    if ($code === '' || !preg_match('/^[A-Z0-9-]{4,20}$/', $code)) {
        return null;
    }
    $farm = db()->first('SELECT * FROM farms WHERE upper(join_code) = ?', [$code]);
    return $farm ?: null;
}

function safe_return_path(?string $path = null): string
{
    $path = $path !== null ? $path : (string) input('return', '');
    if ($path === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $path = (string) (parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?: '');
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        if ($base && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
    }
    $path = ltrim((string) $path, '/');
    if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//') || str_contains($path, '..')) {
        return 'dashboard';
    }
    return $path;
}

function request_query(array $overrides = [], array $drop = []): string
{
    $query = $_GET;
    foreach ($drop as $key) {
        unset($query[$key]);
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return http_build_query($query);
}

function is_valid_date($value): bool
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
}

function status_badge($status): string
{
    $s = strtolower((string) $status);
    $map = [
        'active' => 'ok', 'healthy' => 'ok', 'completed' => 'ok', 'paid' => 'ok',
        'approved' => 'ok', 'harvested' => 'ok', 'delivered' => 'ok',
        'pending' => 'warn', 'scheduled' => 'warn', 'low' => 'warn', 'due' => 'warn',
        'sent' => 'ok', 'failed' => 'danger',
        'growing' => 'info', 'planted' => 'info', 'in_progress' => 'info',
        'inactive' => 'muted', 'draft' => 'muted', 'fallow' => 'muted',
        'suspended' => 'danger', 'critical' => 'danger', 'overdue' => 'danger', 'outbreak' => 'danger',
        'expired' => 'danger', 'cancelled' => 'danger', 'deceased' => 'danger',
        'sick' => 'danger', 'rejected' => 'danger',
    ];
    $tone = $map[$s] ?? 'muted';
    return '<span class="badge badge-' . $tone . '">' . e(ucwords(str_replace('_', ' ', (string) $status))) . '</span>';
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

function input($key, $default = null)
{
    if (isset($_POST[$key])) {
        return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
    }
    return $default;
}

function input_all(): array
{
    return array_merge($_GET, $_POST);
}

function json_response($data, $code = 200): void
{
    if ((string) (getenv('IFMS_TEST_CAPTURE') ?: '') === '1') {
        $GLOBALS['ifms_test_exit'] = ['type' => 'json', 'data' => $data, 'code' => (int) $code];
        throw new RuntimeException('IFMS_TEST_EXIT');
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function frontend_file(string $name): string
{
    $name = str_replace(['..', '\\'], ['', '/'], $name);
    $root = dirname(__DIR__) . '/frontend';
    $page = $root . '/pages/' . $name . '.php';
    if (is_file($page)) {
        return $page;
    }
    $component = $root . '/components/' . $name . '.php';
    if (is_file($component)) {
        return $component;
    }
    return $page;
}

function abort($code, $message = 'Request cannot be completed'): void
{
    if ((string) (getenv('IFMS_TEST_CAPTURE') ?: '') === '1') {
        $GLOBALS['ifms_test_exit'] = ['type' => 'abort', 'code' => (int) $code, 'message' => (string) $message];
        throw new RuntimeException('IFMS_TEST_EXIT');
    }
    http_response_code($code);
    $page = frontend_file('error');
    if (file_exists($page)) {
        $errorCode = $code;
        $errorMessage = $message;
        require $page;
    } else {
        echo e($message);
    }
    exit;
}

function view($name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $file = frontend_file($name);
    if (!file_exists($file)) {
        abort(500, 'View not found: ' . $name);
    }
    require $file;
}

function render($name, array $data = []): string
{
    extract($data, EXTR_SKIP);
    ob_start();
    require frontend_file($name);
    return ob_get_clean();
}

function nav_items(): array
{
    return require __DIR__ . '/config/navigation.php';
}

function module_def($key): ?array
{
    static $all;
    if ($all === null) {
        $all = [];
        foreach (glob(__DIR__ . '/config/modules/*.php') as $file) {
            $defs = require $file;
            foreach ($defs as $k => $def) {
                $all[$k] = $def;
            }
        }
    }
    return $all[$key] ?? null;
}

function all_modules(): array
{
    $keys = [];
    foreach (glob(__DIR__ . '/config/modules/*.php') as $file) {
        foreach (array_keys(require $file) as $k) {
            $keys[] = $k;
        }
    }
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = module_def($k);
    }
    return $out;
}

function current_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($base && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }
    return '/' . ltrim($uri, '/');
}

function is_active_path($prefix): bool
{
    $path = current_path();
    $prefix = '/' . ltrim($prefix, '/');
    return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
}

function table_exists(string $table): bool
{
    return in_array($table, db()->tables(), true);
}

function session_memo_get(string $key)
{
    $row = $_SESSION['_memo'][$key] ?? null;
    if (!is_array($row) || (int) ($row['exp'] ?? 0) < time()) {
        return null;
    }
    return $row['v'];
}

function session_memo_set(string $key, $value, int $ttl): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['_memo'][$key] = ['exp' => time() + max(1, $ttl), 'v' => $value];
}

function session_memo_flush(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['_memo']);
    }
}

function option_label($row, array $fields = ['name', 'title', 'code']): string
{
    if (!$row) {
        return '';
    }
    foreach ($fields as $f) {
        if (!empty($row[$f])) {
            return (string) $row[$f];
        }
    }
    return '#' . ($row['id'] ?? '');
}

function farm_options(?int $farmId = null): array
{
    if ($farmId) {
        $rows = db()->select('SELECT id, name FROM farms WHERE id = ? ORDER BY name', [$farmId]);
    } else {
        $rows = Auth::check() ? Auth::accessibleFarms() : [];
    }
    $opts = [];
    foreach ($rows as $r) {
        $opts[$r['id']] = $r['name'];
    }
    return $opts;
}

function rel_options(string $table, string $label = 'name', ?string $where = null, array $params = []): array
{
    $sql = "SELECT id, {$label} AS label FROM {$table}";
    if ($where) {
        $sql .= " WHERE {$where}";
    }
    $sql .= " ORDER BY label";
    $opts = [];
    foreach (db()->select($sql, $params) as $r) {
        $opts[$r['id']] = $r['label'];
    }
    return $opts;
}

function scoped_farm_sql(string $alias = ''): array
{
    $col = $alias ? "{$alias}.farm_id" : 'farm_id';
    $fid = Auth::farmId();
    if ($fid) {
        return [" AND {$col} = ?", [$fid]];
    }
    if (Auth::isAdmin()) {
        return ['', []];
    }
    return [" AND {$col} = -1", []];
}

function generate_code(string $prefix): string
{
    return $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function csv_download(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function days_until($date): ?int
{
    if (!$date) {
        return null;
    }
    $ts = strtotime($date);
    if (!$ts) {
        return null;
    }
    return (int) floor(($ts - strtotime('today')) / 86400);
}
