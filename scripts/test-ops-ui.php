<?php
/**
 * Smoke checks for calendar events, weather icons, ops UI schema, and demo purge markers.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('DATABASE_URL');
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = '';

require $root . '/backend/bootstrap.php';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

Schema::ensureOpsUiSchema(db());

check('calendar_events table exists', table_exists('calendar_events'));
$cols = array_flip(db()->columns('farms'));
check('farms.boundary_geojson column exists', isset($cols['boundary_geojson']));

$sunny = weather_icon_kind(800, 'Clear sky');
$rain = weather_icon_kind(501, 'moderate rain');
$storm = weather_icon_kind(200, 'thunderstorm');
$fog = weather_icon_kind(741, 'fog');
check('sunny icon kind', $sunny === 'sunny');
check('rain icon kind', $rain === 'rain');
check('thunder icon kind', $storm === 'thunder');
check('fog icon kind', $fog === 'fog');
$svg = weather_icon_svg(800, 'Clear sky');
check('weather svg contains sun circle', str_contains($svg, 'f0c14a'));
$tips = WeatherService::recommendations([
    'current' => ['temperature_2m' => 28, 'wind_speed_10m' => 10, 'precipitation' => 0],
    'daily' => [
        'precipitation_sum' => [0, 0, 0, 0, 0],
        'temperature_2m_max' => [30, 31, 29, 28, 27],
        'wind_speed_10m_max' => [12, 10, 9, 11, 8],
    ],
], ['name' => 'Test']);
$hasIrrigationTip = false;
foreach ($tips as $tip) {
    if (str_contains(strtolower((string) ($tip[0] ?? '')), 'irrigat')) {
        $hasIrrigationTip = true;
    }
}
check('weather tips exclude irrigation', !$hasIrrigationTip);

$admin = db()->first("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
check('admin user still present', (bool) $admin);

$demoFarm = db()->first('SELECT id FROM farms WHERE name = ? LIMIT 1', ['Golden Valley Estate']);
// Purge may already have run; running again should be safe
Schema::purgeDemoOpsData(db());
$demoFarmAfter = db()->first('SELECT id FROM farms WHERE name = ? LIMIT 1', ['Golden Valley Estate']);
check('Golden Valley demo farm removed', !$demoFarmAfter);

// Calendar CRUD round-trip
$adminUser = db()->find('users', (int) $admin['id']);
Auth::setOverride($adminUser);
$_SESSION['user_id'] = (int) $admin['id'];
$id = CalendarService::save([
    'title' => 'Ops UI test event',
    'description' => 'Temporary',
    'event_date' => date('Y-m-d'),
    'start_time' => '09:00',
    'end_time' => '10:00',
    'category' => 'general',
    'farm_id' => null,
    'reminder' => 1,
]);
check('calendar event insert', $id > 0);
$row = CalendarService::find($id);
check('calendar event readable', $row && $row['title'] === 'Ops UI test event');
$start = new DateTimeImmutable(date('Y-m-d'));
$events = CalendarService::events($start, $start);
$found = false;
foreach ($events as $ev) {
    if ((int) $ev['id'] === $id) {
        $found = true;
    }
}
check('calendar event listed for today', $found);
CalendarService::delete($id);
check('calendar event deleted', !CalendarService::find($id));
Auth::setOverride(null);

echo PHP_EOL . ($fail === 0 ? 'ALL CHECKS PASSED' : ($fail . ' CHECK(S) FAILED')) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
